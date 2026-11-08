<?php

declare(strict_types=1);

/**
 * `throttle:endpoints@<host>` (ADR 0187): the endpoints one #[Domain] serves
 * spend a shared budget of their own, sized apart from the one every other
 * endpoint spends -- an API host's scripts are not a browser's fetches.
 */

namespace Tinvar\Tests\Endpoints\HostBudget;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
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

#[WithoutSession(reason: 'a script sends a bearer token'), Domain('api.{root}')]
final class Api
{
    #[Get('/v1/status', name: 'api.status'), UseMiddleware(Bearer::class), NoAuthorization(reason: 'a status')]
    public function status(): Answer
    {
        return new Answer('api');
    }
}

function hostKernel(array $limits): Kernel
{
    $auth = new AuthContext();
    $registry = new EndpointRegistry();
    $registry->registerAll([Site::class, Api::class]);
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

it('gives a host\'s endpoints a budget of their own, and leaves the shared one to the rest', function () {
    $kernel = hostKernel(['endpoints' => 1, 'endpoints@api.{root}' => 3]);

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

it('counts the host\'s budget by account too, once the endpoint\'s own middleware signed the caller in', function () {
    $kernel = hostKernel(['endpoints@api.{root}' => 2]);

    expect(call($kernel, 'api.shop.test', '/v1/status', 'ada', '192.0.2.1')->status)->toBe(200)
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'ada', '192.0.2.2')->status)->toBe(200)
        // A third address is a fresh address bucket, and the same account.
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'ada', '192.0.2.3')->status)->toBe(429);
});

it('says the host\'s limit in the headers it answers with', function () {
    $kernel = hostKernel(['endpoints@api.{root}' => 700]);

    expect(call($kernel, 'api.shop.test', '/v1/status', 'ada')->header('x-ratelimit-limit'))->toBe('700');
});

it('spends the shared budget on a host given none of its own', function () {
    $kernel = hostKernel(['endpoints' => 1]);

    expect(call($kernel, 'api.shop.test', '/v1/status', 'ada')->status)->toBe(200)
        ->and(call($kernel, 'api.shop.test', '/v1/status', 'ada')->status)->toBe(429);
});

it('refuses a budget for a host no endpoint is on, which would limit nothing', function () {
    expect(fn () => hostKernel(['endpoints@admin.{root}' => 10]))
        ->toThrow(\InvalidArgumentException::class, 'no endpoint');
});
