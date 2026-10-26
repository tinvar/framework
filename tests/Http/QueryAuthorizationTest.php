<?php

declare(strict_types=1);

/**
 * POST /_query names a route only to pick an allowlist, and that route's chain
 * never runs there -- the spec gives an HTTP call "the query's panel stack".
 * A query with no model input needed no #[Authorize] at all, so one meant for
 * an admin page answered any signed-in caller. Every query now declares who
 * may call it, and its own #[Middleware] runs around the call.
 */

namespace Tinvar\Tests\Http\QueryAuthorization;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Http\HttpException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\BatchQuery;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

final class AdminArea {}

final class AdminAreaPolicy
{
    public function viewAny(?object $user, string $subject): bool
    {
        return false;
    }
}

/** Stands in for MFA, a network allowlist, a panel guard. */
final class RequireMfa implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        throw new HttpException(403, 'MFA required.', 'forbidden');
    }
}

final class Ran
{
    public static int $bodies = 0;
}

final readonly class RevenueData extends Data
{
    public function __construct(public int $mrr) {}
}

#[Layout('/admin', view: 'admin/layout'), UseMiddleware(RequireMfa::class), Authorize('viewAny', AdminArea::class)]
final class AdminLayout {}

#[Page('/admin/revenue', view: 'admin/revenue', name: 'admin.revenue')]
final class RevenuePage {}

/** Reachable only from the admin page, and guarded only by it. */
#[Query(name: 'billing.unguarded')]
final class UnguardedRevenue
{
    public function __invoke(): RevenueData
    {
        return new RevenueData(1_234_567);
    }
}

#[Query(name: 'billing.revenue'), Authorize('viewAny', AdminArea::class)]
final class Revenue
{
    public function __invoke(): RevenueData
    {
        Ran::$bodies++;

        return new RevenueData(1_234_567);
    }
}

#[Query(name: 'billing.onMethod')]
final class CheckedOnMethod
{
    #[Authorize('viewAny', AdminArea::class)]
    public function __invoke(): RevenueData
    {
        return new RevenueData(1);
    }
}

#[Query(name: 'billing.exempt'), NoAuthorization(reason: 'any signed-in user may read the plan names')]
final class ExemptOnClass
{
    public function __invoke(): RevenueData
    {
        return new RevenueData(2);
    }
}

#[Query(name: 'billing.exemptMethod')]
final class ExemptOnMethod
{
    #[NoAuthorization(reason: 'any signed-in user may read the plan names')]
    public function __invoke(): RevenueData
    {
        return new RevenueData(3);
    }
}

#[Query(name: 'billing.batch', args: ['plan' => 'string'])]
final class CheckedOnLoadMany implements BatchQuery
{
    #[Authorize('viewAny', AdminArea::class)]
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): RevenueData => new RevenueData(4), $calls);
    }
}

#[Query(name: 'billing.mfa'), UseMiddleware(RequireMfa::class), NoAuthorization(reason: 'the middleware is what is under test')]
final class MfaOnClass
{
    public function __invoke(): RevenueData
    {
        Ran::$bodies++;

        return new RevenueData(5);
    }
}

#[Query(name: 'billing.mfaMethod'), NoAuthorization(reason: 'the middleware is what is under test')]
final class MfaOnMethod
{
    #[UseMiddleware(RequireMfa::class)]
    public function __invoke(): RevenueData
    {
        Ran::$bodies++;

        return new RevenueData(6);
    }
}

/** @param list<class-string> $queries */
function kernelWith(array $queries): Kernel
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $auth = new AuthContext();
    $auth->login(new User('ordinary-user'));

    $routes = new RouteRegistry();
    $routes->registerAll([AdminLayout::class, RevenuePage::class]);

    $registry = new QueryRegistry();
    $registry->registerAll($queries);

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: $registry,
        actions: new ActionRegistry(),
        auth: $auth,
        policies: (new PolicyRegistry())->register(AdminArea::class, AdminAreaPolicy::class),
        resolver: static fn (string $class): object => new $class(),
        // As QueryEndpoint prescribes: the caller says which route it acts
        // for, and the application supplies that route's list.
        allowedQueries: static fn (Request $r): array => $r->header('x-route') === 'admin.revenue'
            ? $registry->names()
            : [],
    );
}

function callQuery(Kernel $kernel, string $name): Response
{
    $request = new Request(
        'POST',
        "/_query/{$name}",
        ['x-xsrf-token' => 't', 'x-route' => 'admin.revenue'],
        cookies: [VerifyCsrfToken::COOKIE => 't'],
    );

    return $kernel->handle(
        $request,
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
        '{"args":{}}',
    );
}

describe('every query declares who may call it', function () {
    it('flags a query with no #[Authorize] and no #[NoAuthorization]', function () {
        $rules = array_column((new BuildRules())->check([UnguardedRevenue::class]), 'rule');

        expect($rules)->toBe([BuildRules::QUERY_MUST_DECLARE_AUTHORIZATION]);
    });

    it('refuses to register one', function () {
        expect(fn () => (new QueryRegistry())->register(UnguardedRevenue::class))
            ->toThrow(\InvalidArgumentException::class, BuildRules::QUERY_MUST_DECLARE_AUTHORIZATION);
    });

    it('accepts a check or an exemption on the class or on the entry method', function (string $class) {
        expect((new BuildRules())->check([$class]))->toBe([]);
    })->with([
        'check on the class' => [Revenue::class],
        'check on __invoke' => [CheckedOnMethod::class],
        'check on loadMany' => [CheckedOnLoadMany::class],
        'exemption on the class' => [ExemptOnClass::class],
        'exemption on __invoke' => [ExemptOnMethod::class],
    ]);
});

describe('/_query runs the query\'s own declarations', function () {
    beforeEach(function () {
        Ran::$bodies = 0;
    });

    it('denies a query its own check refuses, whichever route the caller names', function () {
        $response = callQuery(kernelWith([Revenue::class]), 'billing.revenue');

        expect($response->status)->toBe(403)
            ->and($response->body())->not->toContain('1234567')
            ->and(Ran::$bodies)->toBe(0);
    });

    it('runs a query\'s own #[Middleware] around the call', function (string $name, string $class) {
        $response = callQuery(kernelWith([$class]), $name);

        expect($response->status)->toBe(403)
            ->and(Ran::$bodies)->toBe(0);
    })->with([
        'on the class' => ['billing.mfa', MfaOnClass::class],
        'on __invoke' => ['billing.mfaMethod', MfaOnMethod::class],
    ]);

    it('serves a query whose declarations allow it', function () {
        $response = callQuery(kernelWith([ExemptOnClass::class]), 'billing.exempt');

        expect($response->status)->toBe(200)
            ->and($response->body())->toContain('"mrr":2');
    });
});
