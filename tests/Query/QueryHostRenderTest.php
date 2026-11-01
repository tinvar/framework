<?php

declare(strict_types=1);

/**
 * A query's #[Domain], called from a render (ADR 0149).
 *
 * Held to its host over HTTP, a query held to admin.{root} still ran from a
 * render on any other host whose page's allowlist named it -- the renderer
 * calling it from a storefront page ran it past the host its declaration
 * holds it to. Refused now as a query's #[Panel] is from a render.
 */

namespace Tinvar\Tests\Query\QueryHostRender;

use Tinvar\Attributes\Domain;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\Domains;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;

#[Page('/', view: 'shop/home', name: 'shop.home'), NoAuthorization(reason: 'a test page')]
final class ShopHome
{
    public function __invoke(): void {}
}

#[Page('/', view: 'admin/home', name: 'admin.home'), Domain('admin.{root}'), NoAuthorization(reason: 'a test page')]
final class AdminHome
{
    public function __invoke(): void {}
}

#[Query(name: 'admin.revenue'), Domain('admin.{root}'), NoAuthorization(reason: 'a test query')]
final class AdminRevenue
{
    public function __invoke(): int
    {
        return 42;
    }
}

#[Query(name: 'any.count'), NoAuthorization(reason: 'a test query')]
final class AnyCount
{
    public function __invoke(): int
    {
        return 7;
    }
}

/** @return array{Kernel, QueryRegistry, Orm} */
function app(): array
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $container = new Container();
    $container->instance(Orm::class, $orm);
    $routes = new RouteRegistry();
    $routes->registerAll([ShopHome::class, AdminHome::class]);
    $queries = new QueryRegistry($container->resolver());
    $queries->register(AdminRevenue::class);
    $queries->register(AnyCount::class);

    return [new Kernel(
        orm: $orm,
        routes: $routes,
        queries: $queries,
        actions: new \Tinvar\Actions\ActionRegistry($container->resolver()),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        allowedQueries: static fn (Request $r): array => ['admin.revenue', 'any.count'],
        gate: new AllowAllGate(),
        container: $container,
        domains: new Domains(['root' => 'shop.test']),
    ), $queries, $orm];
}

/**
 * @param list<string> $names
 * @return array<string, array<string, mixed>>
 */
function renderCalls(string $host, array $names): array
{
    [$kernel, $queries, $orm] = app();
    $runner = new QueryRunner($queries, $orm, new AllowAllGate(), panels: $kernel->queryPanelRefusal(...));
    $results = [];
    $response = $kernel->handle(
        new Request('GET', '/', ['host' => $host]),
        static function (LoadedRoute $loaded, Request $passed) use ($runner, $names, &$results): Response {
            $calls = array_map(static fn (int $i, string $name): array => ['id' => $i, 'name' => $name, 'args' => []], array_keys($names), $names);
            foreach ($runner->run($calls, $names) as $result) {
                $results[$names[$result['id']]] = $result;
            }

            return Response::make(200, '');
        },
    );
    expect($response->status)->toBe(200, $response->body());

    return $results;
}

it('refuses a call from a render on another host to a query held to admin.{root}', function () {
    $results = renderCalls('shop.test', ['admin.revenue', 'any.count']);

    expect($results['admin.revenue']['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN)
        ->and($results['any.count']['value'] ?? null)->toBe(7);
});

it('runs it from a render on its own host', function () {
    $results = renderCalls('admin.shop.test', ['admin.revenue']);

    expect($results['admin.revenue']['value'] ?? null)->toBe(42);
});
