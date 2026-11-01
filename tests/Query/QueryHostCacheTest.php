<?php

declare(strict_types=1);

/**
 * A cached query held to a host a parameter takes (ADR 0149).
 *
 * `{account}.{root}` is one pattern and many hosts, and what a query
 * answers on acme's subdomain -- through anything the application put
 * behind the host, here a global middleware naming the account -- is not
 * globex's. The cache key named the user, the tenant, the panel and the
 * locale, and no host: globex's subdomain was answered acme's entry.
 */

namespace Tinvar\Tests\Query\QueryHostCache;

use Tinvar\Attributes\Domain;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\Domains;
use Tinvar\Routing\HostMatch;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;

/** The account the host names, as an application's own middleware keeps it. */
final class CurrentAccount implements Middleware
{
    public static string $name = '';

    public function handle(Request $request, \Closure $next): Response
    {
        self::$name = HostMatch::of($request)->parameters['account'] ?? '';

        return $next($request);
    }
}

#[Query(name: 'account.name', cache: '1m', shared: true), Domain('{account}.{root}'), NoAuthorization(reason: 'a test query'), PublicAccess]
final class AccountName
{
    public function __invoke(): string
    {
        return CurrentAccount::$name;
    }
}

#[Query(name: 'site.name', cache: '1m', shared: true), NoAuthorization(reason: 'a test query'), PublicAccess]
final class SiteName
{
    public static int $runs = 0;

    public function __invoke(): string
    {
        self::$runs++;

        return 'shop';
    }
}

function kernel(): Kernel
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $container = new Container();
    $container->instance(Orm::class, $orm);
    $queries = new QueryRegistry($container->resolver());
    $queries->register(AccountName::class);
    $queries->register(SiteName::class);

    return new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: $queries,
        actions: new \Tinvar\Actions\ActionRegistry($container->resolver()),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        allowedQueries: static fn (Request $r): array => ['account.name', 'site.name'],
        globalMiddleware: [CurrentAccount::class],
        gate: new AllowAllGate(),
        container: $container,
        domains: new Domains(['root' => 'shop.test']),
    );
}

function call(Kernel $kernel, string $host, string $name): string
{
    $response = $kernel->handle(
        new Request('POST', "/_query/{$name}", ['host' => $host, 'x-xsrf-token' => 't', 'x-route' => 'any'], cookies: [VerifyCsrfToken::COOKIE => 't']),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
        '{"args":{}}',
    );
    expect($response->status)->toBe(200, $response->body());

    return $response->body();
}

it('never answers one host of a parameter\'s pattern from another\'s cached entry', function () {
    $kernel = kernel();

    expect(call($kernel, 'acme.shop.test', 'account.name'))->toContain('acme')
        ->and(call($kernel, 'globex.shop.test', 'account.name'))->toContain('globex')
        ->and(call($kernel, 'acme.shop.test', 'account.name'))->toContain('acme');
});

it('still shares an entry across the hosts a query holding none is answered on', function () {
    $kernel = kernel();
    SiteName::$runs = 0;

    call($kernel, 'shop.test', 'site.name');
    call($kernel, 'shop.test', 'site.name');

    expect(SiteName::$runs)->toBe(1);
});
