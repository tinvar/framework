<?php

declare(strict_types=1);

/**
 * A check naming a declared-optional input is skipped when the call leaves
 * that input out. When every check on a query or action named one, leaving
 * them all out left nothing to ask, and the body ran unauthorized -- reading
 * null as "no filter" if that is what it did with it.
 */

namespace Tinvar\Tests\Auth\OptionalSubject;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\Checks;
use Tinvar\Http\ForbiddenException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final class Ran
{
    public static int $bodies = 0;
}

/** Every rack when none is named: exactly the body the check must guard. */
#[Query(name: 'optionalSubject.racks'), Authorize('view', 'rack')]
final class RacksOrOne
{
    /** @return list<string> */
    public function __invoke(?Rack $rack = null): array
    {
        Ran::$bodies++;

        return $rack === null ? ['every rack'] : [$rack->name];
    }
}

final readonly class PickSiteRequest extends FormRequest
{
    public function __construct(public ?Site $site = null) {}
}

final class SiteActions
{
    #[Action, Authorize('purge', 'site')]
    public function purge(?Site $site = null): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok($site === null ? 'every site' : $site->code);
    }

    #[Action, Authorize('purge', 'site')]
    public function purgeFromForm(PickSiteRequest $request): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok('purged');
    }
}

/** @return array{0: Orm, 1: Fixture} */
function orm(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    return [new Orm($fixture->connection, new IdentityMap('test')), $fixture];
}

beforeEach(function () {
    Ran::$bodies = 0;
});

describe('a query whose every check names an optional input', function () {
    it('refuses a call that leaves the input out, without running the body', function () {
        [$orm] = orm();
        $registry = new QueryRegistry();
        $registry->register(RacksOrOne::class);
        $gate = new AllowAllGate();

        $results = (new QueryRunner($registry, $orm, $gate))
            ->run([['id' => 0, 'name' => 'optionalSubject.racks', 'args' => []]], ['optionalSubject.racks']);

        expect($results[0])->not->toHaveKey('value')
            ->and($results[0]['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN)
            ->and(Ran::$bodies)->toBe(0)
            ->and($gate->checks)->toBe([]);
    });

    it('still serves a call that names it, once its check passes', function () {
        [$orm, $fixture] = orm();
        $registry = new QueryRegistry();
        $registry->register(RacksOrOne::class);

        $results = (new QueryRunner($registry, $orm, new AllowAllGate()))->run(
            [['id' => 0, 'name' => 'optionalSubject.racks', 'args' => ['rack' => $fixture->ids['racks'][0]]]],
            ['optionalSubject.racks'],
        );

        expect($results[0])->toHaveKey('value');
    });
});

describe('an action whose every check names an optional input', function () {
    it('refuses a call that leaves the input out, without running the body', function (string $action) {
        [$orm] = orm();
        $registry = new ActionRegistry();
        $registry->register(SiteActions::class);
        $gate = new AllowAllGate();
        $runner = new ActionRunner($registry, $orm, $gate, new Validator($orm));

        expect(fn () => $runner->run($action, []))->toThrow(ForbiddenException::class)
            ->and(Ran::$bodies)->toBe(0)
            ->and($gate->checks)->toBe([]);
    })->with(['SiteActions.purge', 'SiteActions.purgeFromForm']);

    it('still runs one that names it, once its check passes', function () {
        [$orm, $fixture] = orm();
        $registry = new ActionRegistry();
        $registry->register(SiteActions::class);
        $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));

        expect($runner->run('SiteActions.purge', ['site' => $fixture->ids['sites'][0]])->value)->toBe('LAX0');
    });
});

it('leaves an entry point that declares no checks to its #[NoAuthorization]', function () {
    expect(Checks::noneApply([], [], []))->toBeFalse();
});
