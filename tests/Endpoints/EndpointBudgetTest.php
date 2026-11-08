<?php

declare(strict_types=1);

/**
 * #[EndpointBudget] (ADR 0187): endpoints that declare one spend a shared
 * budget of their own, sized on the attribute, in place of the one every
 * other endpoint spends -- an API's scripts are not a browser's fetches.
 */

namespace Tinvar\Tests\Endpoints\Budget;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\EndpointBudget;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\Domains;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

final readonly class Answer extends Data
{
    public function __construct(public string $from) {}
}

/** Signs in whoever the bearer names, as an API's own middleware does. */
final class Bearer implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $token = $request->header('authorization');
        if ($token !== null) {
            $this->auth->login(new User(substr($token, 7)));
        }

        return $next($request);
    }
}

final class Site
{
    #[Get('/status', name: 'site.status'), PublicAccess, NoAuthorization(reason: 'a status')]
    public function status(): Answer
    {
        return new Answer('site');
    }
}

#[WithoutSession(reason: 'a script sends a bearer token'), Domain('api.{root}'), EndpointBudget('api', perMinute: 3)]
final class Api
{
    #[Get('/v1/status', name: 'api.status'), UseMiddleware(Bearer::class), NoAuthorization(reason: 'a status')]
    public function status(): Answer
    {
        return new Answer('api');
    }
}

/** Another class naming the same budget, on the method: one budget between them. */
#[WithoutSession(reason: 'a script sends a bearer token'), Domain('api.{root}')]
final class ApiMore
{
    #[Get('/v1/more', name: 'api.more'), UseMiddleware(Bearer::class), NoAuthorization(reason: 'a status'), EndpointBudget('api', perMinute: 3)]
    public function more(): Answer
    {
        return new Answer('more');
    }
}

final class TwoSizes
{
    #[Get('/a', name: 'two.a'), PublicAccess, NoAuthorization(reason: 'test'), EndpointBudget('wide', perMinute: 3)]
    public function a(): Answer
    {
        return new Answer('a');
    }

    #[Get('/b', name: 'two.b'), PublicAccess, NoAuthorization(reason: 'test'), EndpointBudget('wide', perMinute: 4)]
    public function b(): Answer
    {
        return new Answer('b');
    }
}

#[EndpointBudget('api', perMinute: 3)]
final class BudgetedAction
{
    #[Action('budgeted.save'), NoAuthorization(reason: 'test')]
    public function save(): void {}
}

function budgetKernel(array $limits = []): Kernel
{
    $auth = new AuthContext();
    $registry = new EndpointRegistry();
    $registry->registerAll([Site::class, Api::class, ApiMore::class]);
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => $class === Bearer::class ? new Bearer($auth) : new $class(),
        allowedQueries: static fn (Request $r): array => [],
        throttle: new Throttle(new ArrayThrottleStore(throttleClock()), $auth, static fn (Request $r): string => $r->remoteAddress ?? ''),
        limits: [...Kernel::DEFAULT_LIMITS, ...$limits],
        tenants: null,
        endpoints: $registry,
        domains: new Domains(['root' => 'shop.test']),
    );
}

function call(Kernel $kernel, string $host, string $path, ?string $bearer = null, string $address = '192.0.2.1'): Response
{
    $headers = ['host' => $host];
    if ($bearer !== null) {
        $headers['authorization'] = "Bearer {$bearer}";
    }

    return $kernel->handle(
        new Request('GET', $path, $headers, remoteAddress: $address),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
    );
}

it('spends a budget of its own in place of the shared one, and leaves the shared one to the rest', function () {
    $kernel = budgetKernel(['endpoints' => 1]);

    foreach (range(1, 3) as $n) {
        expect(call($kernel, 'api.shop.test', '/v1/status', 'ada')->status)->toBe(200, "call {$n}");
    }
    expect(call($kernel, 'api.shop.test', '/v1/status', 'ada')->status)->toBe(429)
        // The API's three spent nothing of the site's one.
        ->and(call($kernel, 'shop.test', '/status')->status)->toBe(200)
        ->and(call($kernel, 'shop.test', '/status')->status)->toBe(429)
        // And the site's spent nothing of the API's: a fresh caller there still gets its own.
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'bob', '192.0.2.2')->status)->toBe(200);
});

it('is one budget for every endpoint naming it, whichever class it is on', function () {
    $kernel = budgetKernel();

    expect(call($kernel, 'api.shop.test', '/v1/status', 'ada')->status)->toBe(200)
        ->and(call($kernel, 'api.shop.test', '/v1/more', 'ada')->status)->toBe(200)
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'ada')->status)->toBe(200)
        ->and(call($kernel, 'api.shop.test', '/v1/more', 'ada')->status)->toBe(429);
});

it('counts by account too, once the endpoint\'s own middleware signed the caller in', function () {
    $kernel = budgetKernel();

    expect(call($kernel, 'api.shop.test', '/v1/status', 'ada', '192.0.2.1')->status)->toBe(200)
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'ada', '192.0.2.2')->status)->toBe(200)
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'ada', '192.0.2.3')->status)->toBe(200)
        // A fourth address is a fresh address bucket, and the same account.
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'ada', '192.0.2.4')->status)->toBe(429);
});

it('says its own limit in the headers it answers with', function () {
    expect(call(budgetKernel(), 'api.shop.test', '/v1/status', 'ada')->header('x-ratelimit-limit'))->toBe('3');
});

it('is refused when two endpoints give one budget two sizes', function () {
    expect(fn () => (new EndpointRegistry())->register(TwoSizes::class))
        ->toThrow(EndpointException::class, 'One budget has one size');
});

it('is refused on an action, which spends throttle:actions', function () {
    expect(fn () => (new ActionRegistry())->register(BudgetedAction::class))
        ->toThrow(ActionException::class, 'only an endpoint reads');
});

it('refuses a budget that is not one, or allows nothing', function () {
    expect(fn () => new EndpointBudget('no spaces', 1))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new EndpointBudget('api', 0))->toThrow(\InvalidArgumentException::class);
});
