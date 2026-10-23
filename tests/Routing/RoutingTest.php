<?php

declare(strict_types=1);

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\DenyGate;
use Tinvar\Http\ForbiddenException;
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
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Routing\Fixtures\BadReturnPage;
use Tinvar\Tests\Routing\Fixtures\HealthPage;
use Tinvar\Tests\Routing\Fixtures\OrphanCheckPage;
use Tinvar\Tests\Routing\Fixtures\RackIndexPage;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RawSubjectPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;
use Tinvar\Tests\Routing\Fixtures\SiteNewPage;
use Tinvar\Tests\Routing\Fixtures\Trace;
use Tinvar\Tests\Routing\Fixtures\TypedParamPage;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/routes.php';

const ROUTE_CLASSES = [
    RootLayout::class, SiteLayout::class, RackShowPage::class, SiteNewPage::class,
    HealthPage::class, TypedParamPage::class, BadReturnPage::class, OrphanCheckPage::class,
    RackIndexPage::class, RawSubjectPage::class,
];

function registry(): RouteRegistry
{
    $registry = new RouteRegistry();
    $registry->registerAll(ROUTE_CLASSES);

    return $registry;
}

/** @return array{0: Router, 1: Fixture, 2: Orm} */
function router(?object $gate = null, array $globalMiddleware = []): array
{
    Trace::reset();
    $fixture = new Fixture(racks: 2, unitsPerRack: 2, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $runner = new LoaderRunner(
        $orm,
        static fn (string $class): object => new $class(),
        $gate ?? new AllowAllGate(),
    );

    $router = new Router(
        registry(),
        $runner,
        static fn (string $class): Middleware => new $class(),
        $globalMiddleware,
    );

    return [$router, $fixture, $orm];
}

function respondWithLoaded(): Closure
{
    return static fn (LoadedRoute $loaded, Request $request): Response
        => Response::make(200, json_encode([
            'page' => $loaded->page === null ? null : get_object_vars($loaded->page),
            'layouts' => array_keys($loaded->layouts),
        ]));
}

describe('route compilation', function () {
    it('rejects a malformed template', function (string $class, string $because) {
        expect(fn () => (new RouteRegistry())->register($class))
            ->toThrow(RoutingException::class, $because);
    })->with([
        'invalid parameter name' => [Tinvar\Tests\Routing\Fixtures\BadParameterNamePage::class, 'is not a valid parameter name'],
        'repeated parameter' => [Tinvar\Tests\Routing\Fixtures\DuplicateParameterPage::class, 'appears twice'],
        'space in a literal segment' => [Tinvar\Tests\Routing\Fixtures\BadSegmentPage::class, 'not a literal path segment'],
    ]);

    it('refuses a class with neither #[Page] nor #[Layout]', function () {
        expect(fn () => (new RouteRegistry())->register(Tinvar\Tests\Routing\Fixtures\NotARoute::class))
            ->toThrow(RoutingException::class, 'neither a #[Page] nor a #[Layout]');
    });

    it('refuses two routes sharing a name', function () {
        $registry = new RouteRegistry();
        $registry->register(Tinvar\Tests\Routing\Fixtures\RackShowPage::class);

        expect(fn () => $registry->register(Tinvar\Tests\Routing\Fixtures\BadSegmentPage::class))
            ->toThrow(RoutingException::class);
    });

    it('reports an unknown route name', function () {
        expect(fn () => registry()->url('nope'))->toThrow(RoutingException::class, 'No route is named');
    });

    it('refuses two routes claiming the same template', function () {
        $registry = new RouteRegistry();
        $registry->register(RackShowPage::class);

        expect(fn () => $registry->register(RackShowPage::class))
            ->toThrow(RoutingException::class, 'Two routes claim');
    });

    it('generates a url from a named route', function () {
        expect(registry()->url('racks.show', ['site' => 's1', 'rack' => 'r1']))
            ->toBe('/sites/s1/racks/r1');
    });

    it('url-encodes parameters so a value cannot invent a segment', function () {
        expect(registry()->url('racks.show', ['site' => 'a/b', 'rack' => 'r 1']))
            ->toBe('/sites/a%2Fb/racks/r%201');
    });

    it('refuses to build a url with a parameter missing', function () {
        expect(fn () => registry()->url('racks.show', ['site' => 's1']))
            ->toThrow(RoutingException::class, "needs a 'rack' parameter");
    });
});

describe('matching', function () {
    it('matches a page and extracts its parameters', function () {
        $chain = registry()->match('/sites/abc/racks/xyz');

        expect($chain)->not->toBeNull()
            ->and($chain->page->class)->toBe(RackShowPage::class)
            ->and($chain->parameters)->toBe(['site' => 'abc', 'rack' => 'xyz']);
    });

    it('prefers a static segment over a parameter', function () {
        expect(registry()->match('/sites/new')->page->class)->toBe(SiteNewPage::class);
    });

    it('returns null for a path nothing claims', function () {
        expect(registry()->match('/nothing/here'))->toBeNull();
    });

    it('decodes percent-encoded parameters', function () {
        expect(registry()->match('/counts/42')->parameters)->toBe(['count' => '42']);
    });

    it('normalizes a path before matching', function () {
        expect(Request::normalizePath('/sites//abc/racks/xyz/'))->toBe('/sites/abc/racks/xyz');
    });

    it('refuses a path containing traversal segments', function () {
        expect(fn () => Request::normalizePath('/sites/../admin'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('the layout chain', function () {
    it('collects the layouts wrapping a page, outermost first', function () {
        $chain = registry()->match('/sites/abc/racks/xyz');

        expect(array_map(static fn ($l) => $l->class, $chain->layouts))
            ->toBe([RootLayout::class, SiteLayout::class]);
    });

    it('matches a layout by structure, not by string prefix', function () {
        // /sites/{site} must not wrap /sites/new, whose segment is literal.
        $chain = registry()->match('/sites/new');

        expect(array_map(static fn ($l) => $l->class, $chain->layouts))->toBe([RootLayout::class]);
    });

    it('runs loaders outermost first, then the page', function () {
        [$router, $fixture] = router();
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());

        expect(array_values(array_filter(Trace::$events, static fn (string $e): bool => !str_starts_with($e, 'middleware:'))))
            ->toBe(['layout:root', 'layout:site', 'page:rack-show']);
    });

    it("passes a parent layout's data to a child by type", function () {
        [$router, $fixture] = router();
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $response = $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());
        $decoded = json_decode($response->body(), true);

        // SiteLayout combined the site code with RootLayoutData's tenant,
        // and the page received that layout's data in turn.
        expect($decoded['page']['siteCode'])->toBe('LAX0@acme')
            ->and($decoded['layouts'])->toBe(['root', 'sites']);
    });

    it('refuses a loader asking for data no layout above it returned', function () {
        $registry = new RouteRegistry();
        $registry->registerAll([RackShowPage::class]);   // no layouts registered
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $runner = new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate());
        $router = new Router($registry, $runner, static fn (string $c): Middleware => new $c());

        expect(fn () => $router->handle(
            Request::get("/sites/{$fixture->ids['sites'][0]}/racks/{$fixture->ids['racks'][0]}"),
            respondWithLoaded(),
        ))->toThrow(RoutingException::class, 'which no layout above it returned');
    });

    it('allows a page with no loader at all', function () {
        [$router] = router();

        $response = $router->handle(Request::get('/health'), respondWithLoaded());

        expect(json_decode($response->body(), true)['page'])->toBeNull();
    });
});

describe('route model binding', function () {
    it('resolves a model from the path through the identity map', function () {
        [$router, $fixture, $orm] = router();
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());

        expect($orm->identityMap()->has(Rack::class, $rack))->toBeTrue();
    });

    it('404s on a model id that does not exist', function () {
        [$router, $fixture] = router();
        $site = $fixture->ids['sites'][0];

        expect(fn () => $router->handle(
            Request::get("/sites/{$site}/racks/0192f3a1-0000-7000-8000-000000000000"),
            respondWithLoaded(),
        ))->toThrow(NotFoundException::class);
    });

    it('404s rather than erroring when an int parameter is not a number', function () {
        [$router] = router();

        expect(fn () => $router->handle(Request::get('/counts/abc'), respondWithLoaded()))
            ->toThrow(NotFoundException::class);
    });

    it('casts an int parameter', function () {
        [$router] = router();

        $response = $router->handle(Request::get('/counts/42'), respondWithLoaded());

        expect(json_decode($response->body(), true)['page']['tenant'])->toBe('42');
    });
});

describe('authorization in the route chain', function () {
    it('runs every declared check before any loader body', function () {
        [$router, $fixture] = router(new DenyGate());
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        expect(fn () => $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded()))
            ->toThrow(ForbiddenException::class);

        // The layout's check denied first, so no loader ran at all.
        expect(array_filter(Trace::$events, static fn (string $e): bool => str_starts_with($e, 'layout:')))
            ->toBe([]);
    });

    it('records the checks it ran, for every route in the chain', function () {
        $gate = new AllowAllGate();
        [$router, $fixture] = router($gate);
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());

        expect($gate->checks)->toBe([
            ['ability' => 'view', 'argument' => 'site'],
            ['ability' => 'view', 'argument' => 'rack'],
        ]);
    });

    it('refuses a check naming something the route cannot resolve', function () {
        [$router] = router();

        expect(fn () => $router->handle(Request::get('/orphan'), respondWithLoaded()))
            ->toThrow(RoutingException::class, 'neither a model-typed parameter');
    });

    // A policy written for a Rack must never be handed the raw path segment.
    it('refuses a check whose argument resolves only to a path segment', function () {
        [$router] = router();

        expect(fn () => $router->handle(Request::get('/raw/anything'), respondWithLoaded()))
            ->toThrow(RoutingException::class, 'neither a model-typed parameter');

        expect(Trace::$events)->not->toContain('page:raw');
    });

    it('accepts a class-level check such as Authorize(viewAny, Rack::class)', function () {
        $gate = new AllowAllGate();
        [$router] = router($gate);

        $router->handle(Request::get('/racks'), respondWithLoaded());

        expect($gate->checks)->toContain(['ability' => 'viewAny', 'argument' => Rack::class]);
    });

    it('hands the gate the resolved model, not the string from the URL', function () {
        $seen = null;
        $gate = new class ($seen) implements Tinvar\Auth\Gate {
            public array $subjects = [];

            public function __construct(mixed &$seen) {}

            public function allows(Tinvar\Attributes\Authorize $check, array $arguments): bool
            {
                $this->subjects[$check->argument ?? '?'] = $arguments['subject'] ?? null;

                return true;
            }
        };

        [$router, $fixture] = router($gate);
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());

        expect($gate->subjects['site'])->toBeInstanceOf(Tinvar\Tests\Orm\Fixtures\Site::class)
            ->and($gate->subjects['rack'])->toBeInstanceOf(Rack::class)
            ->and($gate->subjects['rack']->primaryKey())->toBe($rack);
    });
});

describe('the route chain is not cached across requests', function () {
    // A RouteChain carries the parameters of the request that built it. Caching
    // one by template would hand every later request the first one's row.
    it('gives each request its own parameters for the same template', function () {
        $registry = registry();

        $first = $registry->match('/sites/aaa/racks/111');
        $second = $registry->match('/sites/bbb/racks/222');

        expect($first->parameters)->toBe(['site' => 'aaa', 'rack' => '111'])
            ->and($second->parameters)->toBe(['site' => 'bbb', 'rack' => '222'])
            ->and($first->parameters)->not->toBe($second->parameters);
    });

    it('loads the row named by the current request, not an earlier one', function () {
        [$router, $fixture] = router();
        $site = $fixture->ids['sites'][0];

        $one = json_decode($router->handle(
            Request::get("/sites/{$site}/racks/{$fixture->ids['racks'][0]}"),
            respondWithLoaded(),
        )->body(), true);

        $two = json_decode($router->handle(
            Request::get("/sites/{$site}/racks/{$fixture->ids['racks'][1]}"),
            respondWithLoaded(),
        )->body(), true);

        expect($one['page']['rack'])->toBe('R-01')
            ->and($two['page']['rack'])->toBe('R-02');
    });
});

describe('middleware', function () {
    it('runs global middleware before the chain, outer layout to page', function () {
        [$router, $fixture] = router(globalMiddleware: [Tinvar\Tests\Routing\Fixtures\RecordingMiddleware::class]);
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());

        $middleware = array_values(array_filter(Trace::$events, static fn (string $e): bool => str_starts_with($e, 'middleware:')));
        expect($middleware)->toBe([
            'middleware:' . Tinvar\Tests\Routing\Fixtures\RecordingMiddleware::class,
            'middleware:outer',
            'middleware:inner',
        ]);
    });

    it('lets middleware refuse before any loader runs', function () {
        [$router, $fixture] = router(globalMiddleware: [Tinvar\Tests\Routing\Fixtures\BlockingMiddleware::class]);
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $response = $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());

        expect($response->status)->toBe(503)
            ->and(array_filter(Trace::$events, static fn (string $e): bool => str_starts_with($e, 'layout:')))->toBe([]);
    });

    it('runs a middleware listed on both a layout and its page once', function () {
        [$router, $fixture] = router(globalMiddleware: [Tinvar\Tests\Routing\Fixtures\InnerMiddleware::class]);
        $site = $fixture->ids['sites'][0];
        $rack = $fixture->ids['racks'][0];

        $router->handle(Request::get("/sites/{$site}/racks/{$rack}"), respondWithLoaded());

        expect(array_count_values(Trace::$events)['middleware:inner'])->toBe(1);
    });
});

describe('responses', function () {
    it('404s when nothing matches', function () {
        [$router] = router();

        expect(fn () => $router->handle(Request::get('/nope'), respondWithLoaded()))
            ->toThrow(NotFoundException::class);
    });

    it('adds Vary: RSC, because one URL returns two bodies', function () {
        [$router] = router();

        expect($router->handle(Request::get('/health'), respondWithLoaded())->header('vary'))
            ->toBe('RSC');
    });

    it('does not duplicate Vary when the responder already set it', function () {
        [$router] = router();

        $response = $router->handle(
            Request::get('/health'),
            static fn (): Response => Response::make(200, '', ['vary' => 'RSC, Cookie']),
        );

        expect($response->header('vary'))->toBe('RSC, Cookie');
    });

    it('refuses a loader that returns something other than a Data object', function () {
        [$router] = router();

        expect(fn () => $router->handle(Request::get('/bad-return'), respondWithLoaded()))
            ->toThrow(RoutingException::class, 'only Data objects cross');
    });
});

it('reads the RSC header the client router sends', function () {
    expect(Request::get('/x', ['RSC' => '1'])->wantsRscPayload())->toBeTrue()
        ->and(Request::get('/x')->wantsRscPayload())->toBeFalse()
        ->and(Request::get('/x', ['Purpose' => 'prefetch'])->isPrefetch())->toBeTrue();
});
