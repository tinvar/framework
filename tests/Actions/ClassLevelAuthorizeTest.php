<?php

declare(strict_types=1);

/**
 * A class-level #[Authorize] on an action holder was dropped without a word
 * as soon as a method declared a check of its own -- the registry complained
 * only when the method declared nothing. "Everything in here is admin-only"
 * then meant nothing for any action that also checked its model.
 */

namespace Tinvar\Tests\Actions\ClassLevelAuthorize;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final class AdminArea {}

/** Says no to 'admin' when told to, yes to everything else, and records what it was asked. */
final class RecordingGate implements Gate
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $admin) {}

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->asked[] = $check->ability;

        return $check->ability !== 'admin' || $this->admin;
    }
}

final class Ran
{
    public static int $bodies = 0;
}

#[Authorize('admin', AdminArea::class)]
final class AdminRackActions
{
    #[Action, Authorize('update', 'rack')]
    public function purge(Rack $rack): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok('purged');
    }
}

/** The class check names an input purge() takes and ping() does not. */
#[Authorize('view', 'rack')]
final class MismatchedClassCheck
{
    #[Action, Authorize('update', 'rack')]
    public function purge(Rack $rack): ActionResult
    {
        return ActionResult::ok();
    }

    #[Action, Authorize('ping', AdminArea::class)]
    public function ping(): ActionResult
    {
        return ActionResult::ok('pong');
    }
}

/** @return array{0: ActionRunner, 1: Fixture} */
function runner(Gate $gate): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(AdminRackActions::class);

    return [new ActionRunner($registry, $orm, $gate, new \Tinvar\Validation\Validator($orm)), $fixture];
}

beforeEach(function () {
    Ran::$bodies = 0;
});

it('keeps the class check beside the method check, class first', function () {
    $compiled = (new ActionRegistry())->register(AdminRackActions::class);

    expect(array_map(static fn (Authorize $c): string => $c->ability, $compiled[0]->checks))
        ->toBe(['admin', 'update']);
});

it('refuses the action when the class check denies, though the method check would allow', function () {
    $gate = new RecordingGate(admin: false);
    [$runner, $fixture] = runner($gate);

    expect(fn () => $runner->run('AdminRackActions.purge', ['rack' => $fixture->ids['racks'][0]]))
        ->toThrow(ForbiddenException::class)
        ->and(Ran::$bodies)->toBe(0)
        ->and($gate->asked)->toBe(['admin']);
});

it('runs the action once both checks pass', function () {
    $gate = new RecordingGate(admin: true);
    [$runner, $fixture] = runner($gate);

    expect($runner->run('AdminRackActions.purge', ['rack' => $fixture->ids['racks'][0]])->value)->toBe('purged')
        ->and($gate->asked)->toBe(['admin', 'update']);
});

// Now that the class check runs on every action, one naming an input only
// some actions take would throw on the first call to the others.
it('refuses a class check naming an input the action does not take', function () {
    expect(fn () => (new ActionRegistry())->register(MismatchedClassCheck::class))
        ->toThrow(ActionException::class, "ping() inherits from its class #[Authorize('view', 'rack')]");

    $violations = (new BuildRules())->check([MismatchedClassCheck::class]);

    expect(array_column($violations, 'rule'))->toBe([BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT])
        ->and($violations[0]->where)->toBe(MismatchedClassCheck::class . '::ping()');
});
