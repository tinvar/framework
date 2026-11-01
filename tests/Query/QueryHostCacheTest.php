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
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Query\QueryRunner;
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

#[Page('/', view: 'account/home', name: 'account.home'), Domain('{account}.{root}'), NoAuthorization(reason: 'a test page')]
final class AccountHome
{
    public function __invoke(string $account): void {}
}

/** Holds no host: answered on every host, from whatever the application put behind it. */
#[Query(name: 'visitor.account', cache: '1m', shared: true), NoAuthorization(reason: 'a test query'), PublicAccess]
final class VisitorAccount
{
    public function __invoke(): string
    {
        return CurrentAccount::$name;
    }
}

/** Each run of its body answers differently: whichever request reads another's answer is caught out. */
#[Query(name: 'visitor.run', cache: '1m', shared: true), NoAuthorization(reason: 'a test query'), PublicAccess]
final class VisitorRun
{
    public static int $runs = 0;

    public function __invoke(): string
    {
        return 'run-' . ++self::$runs;
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

function kernel(?QueryCache $cache = null): Kernel
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $container = new Container();
    $container->instance(Orm::class, $orm);
    $queries = new QueryRegistry($container->resolver());
    $queries->register(AccountName::class);
    $queries->register(SiteName::class);
    $queries->register(VisitorAccount::class);
    $queries->register(VisitorRun::class);
    $routes = new RouteRegistry();
    $routes->registerAll([AccountHome::class]);

    return new Kernel(
        orm: $orm,
        routes: $routes,
        cache: $cache,
        queries: $queries,
        actions: new \Tinvar\Actions\ActionRegistry($container->resolver()),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        allowedQueries: static fn (Request $r): array => ['account.name', 'site.name', 'visitor.account', 'visitor.run'],
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

/**
 * A render's calls, as the renderer makes them for a page: through a runner
 * on the Kernel's cache, held as the Kernel holds a render's.
 *
 * @return array{Kernel, QueryRunner}
 */
function rendering(): array
{
    $auth = new AuthContext();
    $cache = new QueryCache(new ArrayStore(), $auth);
    $kernel = kernel($cache);
    $runner = new QueryRunner($kernel->queries, $kernel->orm, new AllowAllGate(), cache: $cache, panels: $kernel->queryPanelRefusal(...));

    return [$kernel, $runner];
}

function answer(QueryRunner $runner, string $query = 'visitor.account'): mixed
{
    $result = $runner->run([['id' => 0, 'name' => $query, 'args' => []]], [$query])[0] ?? [];

    return $result['value'] ?? $result['error']['code'] ?? null;
}

// The security review: the host a key named was read off the request the
// Kernel was handling when the lookup ran. A body streamed after handle()
// returned found none, and keyed both hosts as one.
it('keys a call made while a streamed page is sent by the host the page was asked on', function () {
    [$kernel, $runner] = rendering();
    $streamed = static fn (string $host): string => $kernel->handle(
        new Request('GET', '/', ['host' => $host]),
        static fn (LoadedRoute $l, Request $r): Response => Response::stream(static function (\Closure $sink) use ($runner): void {
            $sink((string) answer($runner));
        }),
    )->body();

    expect($streamed('acme.shop.test'))->toBe('acme')
        ->and($streamed('globex.shop.test'))->toBe('globex');
});

// Two requests run in fibers, interleaved: each call is keyed by its own
// request's host, never by the one the Kernel took up last.
it('keys each fiber\'s calls by its own request\'s host when requests interleave', function () {
    [$kernel, $runner] = rendering();
    VisitorRun::$runs = 0;
    $page = static fn (string $host): \Fiber => new \Fiber(static fn (): string => $kernel->handle(
        new Request('GET', '/', ['host' => $host]),
        static function (LoadedRoute $l, Request $r) use ($runner): Response {
            \Fiber::suspend();
            $answer = (string) answer($runner, 'visitor.run');
            // Still answering when the other request makes its call.
            \Fiber::suspend();

            return Response::make(200, $answer);
        },
    )->body());

    $acme = $page('acme.shop.test');
    $globex = $page('globex.shop.test');
    $acme->start();
    $globex->start();
    $acme->resume();
    $globex->resume();
    $globex->resume();
    $acme->resume();

    // Acme's answer, computed while the Kernel was last handed globex's
    // request, was filed as globex's, and globex was answered from it.
    expect($acme->getReturn())->toStartWith('run-')
        ->and($globex->getReturn())->toStartWith('run-')
        ->and($globex->getReturn())->not->toBe($acme->getReturn());
});

// Where the application routes by host and no host is known for a call --
// work running outside any request -- its answer is neither looked up nor
// kept: never keyed as no host's.
it('caches nothing for a call no host is known for', function () {
    [, $runner] = rendering();
    CurrentAccount::$name = 'acme';
    $first = answer($runner);
    CurrentAccount::$name = 'globex';

    expect($first)->toBe('acme')
        ->and(answer($runner))->toBe('globex');
});
