<?php

declare(strict_types=1);

/**
 * A prefetch is a GET like any other.
 *
 * tinvar-spec.md: "Loaders and queries run in a read-only transaction ...
 * because hovering a link runs them. Only GET links prefetch." The client
 * says `Purpose: prefetch` so an application can skip what a visit records,
 * and nothing about access may change with it: the same middleware, the same
 * #[Authorize] chain, the same loaders, under the same read-only rule.
 */

namespace Tinvar\Tests\Routing\Prefetch;

use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Gate;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;
use Tinvar\Tests\Routing\Fixtures\Trace;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/routes.php';

/** Says yes or no to everything, and remembers what it was asked. */
final class RecordingGate implements Gate
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $allow) {}

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->asked[] = $check->ability;

        return $this->allow;
    }
}

/** @return array{Router, Fixture} */
function router(Gate $gate): array
{
    Trace::reset();
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

    return [new Router(
        $routes,
        new LoaderRunner($orm, static fn (string $class): object => new $class(), $gate),
        static fn (string $class): Middleware => new $class(),
    ), $fixture];
}

function path(Fixture $fixture): string
{
    return "/sites/{$fixture->ids['sites'][0]}/racks/{$fixture->ids['racks'][0]}";
}

it('is recognised by the header the client sends', function () {
    expect(Request::get('/', ['Purpose' => 'prefetch'])->isPrefetch())->toBeTrue()
        ->and(Request::get('/')->isPrefetch())->toBeFalse()
        ->and(Request::get('/', ['Purpose' => 'preview'])->isPrefetch())->toBeFalse();
});

it('runs the whole chain a visit runs: middleware, every check, every loader', function () {
    $gate = new RecordingGate(true);
    [$router, $fixture] = router($gate);
    $loaded = null;

    $response = $router->handle(
        Request::get(path($fixture), ['RSC' => '1', 'Purpose' => 'prefetch']),
        static function (LoadedRoute $route) use (&$loaded): Response {
            $loaded = $route;

            return Response::make(200, 'payload');
        },
    );

    expect($response->status)->toBe(200)
        ->and($gate->asked)->toBe(['view', 'view'])
        ->and(Trace::$events)->toBe([
            'middleware:outer',
            'middleware:inner',
            'layout:root',
            'layout:site',
            'page:rack-show',
        ])
        ->and($loaded?->page)->not->toBeNull();
});

it('is refused exactly as a visit is', function () {
    [$router, $fixture] = router(new RecordingGate(false));

    expect(static fn () => $router->handle(
        Request::get(path($fixture), ['RSC' => '1', 'Purpose' => 'prefetch']),
        static fn (): Response => Response::make(200, 'never'),
    ))->toThrow(ForbiddenException::class);

    expect(Trace::$events)->not->toContain('page:rack-show');
});
