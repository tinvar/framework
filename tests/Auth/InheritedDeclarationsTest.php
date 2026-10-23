<?php

declare(strict_types=1);

/**
 * PHP does not inherit class attributes, so a page or action holder extending
 * an #[Authorize]- and #[Middleware]-carrying base registered with no check
 * and no middleware, and the build rules -- reading the same reflection --
 * reported nothing. That failed open.
 */

namespace Tinvar\Tests\Auth\InheritedDeclarations;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final class AdminArea {}

final class AuditArea {}

final class AdminGuard implements \Tinvar\Http\Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

final class AuditGuard implements \Tinvar\Http\Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

/** Denies 'admin', allows everything else, and records what it was asked. */
final class DenyAdminGate implements Gate
{
    /** @var list<string> */
    public array $asked = [];

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->asked[] = $check->ability;

        return $check->ability !== 'admin';
    }
}

final class Ran
{
    public static int $bodies = 0;
}

/** "Everything extending this is admin-only." */
#[Authorize('admin', AdminArea::class), Middleware(AdminGuard::class)]
abstract class AdminActions {}

final class UserAdminActions extends AdminActions
{
    #[Action, NoAuthorization(reason: 'no model input; the admin check covers it')]
    public function purgeCaches(): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok();
    }
}

#[Authorize('audit', AuditArea::class), Middleware(AuditGuard::class)]
trait Audited {}

#[Authorize('admin', AdminArea::class)]
abstract class Grandparent {}

abstract class Parent_ extends Grandparent
{
    use Audited;
}

#[Authorize('own', Rack::class)]
final class Grandchild extends Parent_
{
    #[Action, Authorize('update', 'rack')]
    public function rename(Rack $rack): ActionResult
    {
        return ActionResult::ok();
    }
}

/** Says nothing of its own, under a base that does. */
final class SilentUnderAdmin extends AdminActions
{
    #[Action]
    public function ping(): ActionResult
    {
        return ActionResult::ok();
    }
}

#[Authorize('view', 'rack')]
abstract class RackScoped {}

/** Inherits a check naming an input it does not take. */
final class InheritsAMismatch extends RackScoped
{
    #[Action, NoAuthorization(reason: 'no model input')]
    public function ping(): ActionResult
    {
        return ActionResult::ok();
    }
}

#[Authorize('admin', AdminArea::class), Middleware(AdminGuard::class)]
abstract class AdminPage {}

#[Page('/admin/stats', view: 'admin/stats', name: 'admin.stats')]
final class Stats extends AdminPage {}

/** A page inheriting a check on an input its loader does not take. */
#[Page('/admin/racks', view: 'admin/racks')]
final class RackList extends RackScoped
{
    public function __invoke(): null
    {
        return null;
    }
}

#[Query(name: 'inherited.count')]
final class AdminCount extends AdminPage
{
    public function __invoke(): int
    {
        return 1;
    }
}

beforeEach(function () {
    Ran::$bodies = 0;
});

it("runs an action holder's inherited class check and middleware", function () {
    [$action] = (new ActionRegistry())->register(UserAdminActions::class);

    expect(array_map(static fn (Authorize $c): string => $c->ability, $action->checks))->toBe(['admin'])
        ->and($action->classLevelChecks)->toBe(1)
        ->and($action->middleware)->toBe([AdminGuard::class]);

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(UserAdminActions::class);
    $gate = new DenyAdminGate();
    $runner = new ActionRunner($registry, $orm, $gate, new Validator($orm));

    expect(fn () => $runner->run('UserAdminActions.purgeCaches', []))->toThrow(ForbiddenException::class)
        ->and(Ran::$bodies)->toBe(0)
        ->and($gate->asked)->toBe(['admin']);
});

it("compiles a page's inherited class check and middleware into its route", function () {
    $route = (new RouteRegistry())->register(Stats::class);

    expect(array_map(static fn (Authorize $c): string => $c->ability, $route->checks))->toBe(['admin'])
        ->and($route->middleware)->toBe([AdminGuard::class]);
});

it("keeps a query's inherited class check, counted as class-level", function () {
    $registry = new QueryRegistry();
    $registry->register(AdminCount::class);

    expect(array_map(static fn (Authorize $c): string => $c->ability, $registry->checksFor('inherited.count')))
        ->toBe(['admin'])
        ->and($registry->classLevelChecksFor('inherited.count'))->toBe(1)
        ->and($registry->middlewareFor('inherited.count'))->toBe([AdminGuard::class]);
});

it("reads ancestors first, a trait's declarations before its user's own, then the method's", function () {
    [$action] = (new ActionRegistry())->register(Grandchild::class);

    expect(array_map(static fn (Authorize $c): string => $c->ability, $action->checks))
        ->toBe(['admin', 'audit', 'own', 'update'])
        ->and($action->classLevelChecks)->toBe(3)
        ->and($action->middleware)->toBe([AuditGuard::class]);
});

it('refuses an action that declares nothing under a base that does, as it would on the class itself', function () {
    expect(fn () => (new ActionRegistry())->register(SilentUnderAdmin::class))
        ->toThrow(ActionException::class);
});

it('lets the build rules see an inherited check, as the registries do', function () {
    $violations = (new BuildRules())->check([InheritsAMismatch::class, RackList::class]);

    expect(array_column($violations, 'rule'))->toBe([
        BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT,
        BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT,
    ])
        ->and($violations[0]->where)->toBe(InheritsAMismatch::class . '::ping()')
        ->and($violations[1]->where)->toBe(RackList::class . '::__invoke()');

    expect(fn () => (new ActionRegistry())->register(InheritsAMismatch::class))
        ->toThrow(ActionException::class, "inherits from its class #[Authorize('view', 'rack')]");
});
