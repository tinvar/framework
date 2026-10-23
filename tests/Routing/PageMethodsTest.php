<?php

declare(strict_types=1);

/**
 * Page loaders ran for any HTTP method: a POST or a DELETE to a page URL ran
 * its loaders and answered 200. A page answers GET and HEAD; anything else is
 * a 405 naming what is allowed.
 */

namespace Tinvar\Tests\Routing\PageMethods;

use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final class Ran
{
    /** @var list<string> */
    public static array $events = [];
}

final class RouteGuard implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Ran::$events[] = 'middleware';

        return $next($request);
    }
}

#[Page('/reports', view: 'reports/index', name: 'reports.index'), UseMiddleware(RouteGuard::class)]
final class Reports
{
    public function __invoke(): null
    {
        Ran::$events[] = 'loader';

        return null;
    }
}

function router(): Router
{
    $registry = new RouteRegistry();
    $registry->register(Reports::class);
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    return new Router(
        $registry,
        new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
        static fn (string $c): Middleware => new $c(),
    );
}

function respond(): \Closure
{
    return static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200, 'page');
}

beforeEach(function () {
    Ran::$events = [];
});

it('answers GET and HEAD', function (string $method) {
    expect(router()->handle(new Request($method, '/reports'), respond())->status)->toBe(200)
        ->and(Ran::$events)->toBe(['middleware', 'loader']);
})->with(['GET', 'HEAD']);

it('refuses any other method with a 405 naming what is allowed, running nothing of the route', function (string $method) {
    try {
        router()->handle(new Request($method, '/reports'), respond());
        $refused = null;
    } catch (HttpException $e) {
        $refused = $e;
    }

    expect($refused?->status)->toBe(405)
        ->and($refused?->headers)->toBe(['allow' => 'GET, HEAD'])
        ->and(Ran::$events)->toBe([]);
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('still answers a path no route matches with a 404, whatever the method', function () {
    expect(fn () => router()->handle(new Request('POST', '/nowhere'), respond()))
        ->toThrow(NotFoundException::class);
});
