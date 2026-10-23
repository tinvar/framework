<?php

declare(strict_types=1);

/**
 * "No declared check applies" refuses a call that leaves every optional
 * subject out. Judged over the class's checks and the method's merged into
 * one list, an unconditional class check -- an action holder's
 * Authorize('admin', AdminArea::class) -- made the method's own checks look
 * decided, and a method whose only check named the omitted input ran on the
 * class check alone.
 */

namespace Tinvar\Tests\Auth\ClassAndMethodChecks;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final class AdminArea {}

/** Allows everything, and records what it was asked. */
final class RecordingGate implements Gate
{
    /** @var list<string> */
    public array $asked = [];

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->asked[] = $check->ability;

        return true;
    }
}

final class Ran
{
    public static int $bodies = 0;
}

#[Authorize('admin', AdminArea::class)]
final class AdminSiteActions
{
    /** Every site when none is named. */
    #[Action, Authorize('purge', 'site')]
    public function purge(?Site $site = null): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok($site === null ? 'every site' : $site->code);
    }
}

/** The mirror: the class check names the optional input, the method opts out. */
#[Authorize('view', 'site')]
final class SiteListActions
{
    #[Action, NoAuthorization(reason: 'listing needs no check of its own')]
    public function list(?Site $site = null): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok('listed');
    }
}

/** A query's class check, and a method check on an optional rack. */
#[Query(name: 'classAndMethod.racks'), Authorize('viewAny', Rack::class)]
final class RacksOrOne
{
    /** @return list<string> */
    #[Authorize('view', 'rack')]
    public function __invoke(?Rack $rack = null): array
    {
        Ran::$bodies++;

        return $rack === null ? ['every rack'] : [$rack->name];
    }
}

/** @return array{0: Orm, 1: Fixture} */
function orm(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    return [new Orm($fixture->connection, new IdentityMap('test')), $fixture];
}

/** @param class-string $holder */
function actions(string $holder, Gate $gate): array
{
    [$orm, $fixture] = orm();
    $registry = new ActionRegistry();
    $registry->register($holder);

    return [new ActionRunner($registry, $orm, $gate, new Validator($orm)), $fixture];
}

beforeEach(function () {
    Ran::$bodies = 0;
});

describe('an action holder\'s class check', function () {
    it('does not decide for a method whose own checks all name an omitted input', function () {
        $gate = new RecordingGate();
        [$runner] = actions(AdminSiteActions::class, $gate);

        expect(fn () => $runner->run('AdminSiteActions.purge', []))->toThrow(ForbiddenException::class)
            ->and(Ran::$bodies)->toBe(0)
            ->and($gate->asked)->toBe([]);
    });

    it('still runs beside the method check when the input is named, and both must pass', function () {
        $gate = new RecordingGate();
        [$runner, $fixture] = actions(AdminSiteActions::class, $gate);

        expect($runner->run('AdminSiteActions.purge', ['site' => $fixture->ids['sites'][0]])->value)->toBe('LAX0')
            ->and($gate->asked)->toBe(['admin', 'purge']);
    });

    it('is decided on its own when every class check names an omitted input', function () {
        [$runner] = actions(SiteListActions::class, new RecordingGate());

        expect(fn () => $runner->run('SiteListActions.list', []))->toThrow(ForbiddenException::class)
            ->and(Ran::$bodies)->toBe(0);
    });
});

describe('a query\'s class check', function () {
    it('does not decide for an entry method whose own checks all name an omitted input', function () {
        [$orm] = orm();
        $registry = new QueryRegistry();
        $registry->register(RacksOrOne::class);
        $gate = new RecordingGate();

        $results = (new QueryRunner($registry, $orm, $gate))
            ->run([['id' => 0, 'name' => 'classAndMethod.racks', 'args' => []]], ['classAndMethod.racks']);

        expect($results[0]['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN)
            ->and(Ran::$bodies)->toBe(0)
            ->and($gate->asked)->toBe([]);
    });

    it('still runs beside the method check when the input is named', function () {
        [$orm, $fixture] = orm();
        $registry = new QueryRegistry();
        $registry->register(RacksOrOne::class);
        $gate = new RecordingGate();

        $results = (new QueryRunner($registry, $orm, $gate))->run(
            [['id' => 0, 'name' => 'classAndMethod.racks', 'args' => ['rack' => $fixture->ids['racks'][0]]]],
            ['classAndMethod.racks'],
        );

        expect($results[0])->toHaveKey('value')
            ->and($gate->asked)->toBe(['viewAny', 'view']);
    });
});
