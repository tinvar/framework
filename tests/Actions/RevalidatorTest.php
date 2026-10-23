<?php

declare(strict_types=1);

/**
 * "revalidate('racks/show') targets routes ... The response carries a fresh
 * RSC payload for affected mounted routes, each rendered through its own
 * full pipeline from a signed route token."
 *
 * ActionResult::revalidate() recorded route names that nothing could act on:
 * the loop had a producer and no consumer, which both phase reviews named as
 * the blocking prerequisite for the client router.
 */

use Tinvar\Actions\Revalidator;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';
require_once __DIR__ . '/Fixtures/actions.php';

const REVALIDATE_SESSION = 'session-abc';

/** @return array{0: Revalidator, 1: Fixture, 2: Signer, 3: PanelContext, 4: Orm} */
function revalidator(?object $gate = null): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $signer = new Signer('test-app-key', RouteToken::PURPOSE);
    $panels = new PanelContext();

    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

    $revalidator = new Revalidator(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), $gate ?? new AllowAllGate()),
        signer: $signer,
        orm: $orm,
        render: static fn (LoadedRoute $loaded, Request $r): string
            => 'payload:' . $loaded->chain->page->name,
        builds: new Tinvar\Http\BuildRegistry('build-1'),
        middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
        panels: $panels,
    );

    return [$revalidator, $fixture, $signer, $panels, $orm];
}

function routeToken(Signer $signer, Fixture $fixture, array $overrides = []): string
{
    return (new RouteToken(new TokenClaims(
        route: $overrides['route'] ?? 'racks.show',
        params: $overrides['params'] ?? [
            'site' => $fixture->ids['sites'][0],
            'rack' => $fixture->ids['racks'][0],
        ],
        panel: $overrides['panel'] ?? '',
        tenant: $overrides['tenant'] ?? '',
        build: $overrides['build'] ?? 'build-1',
        sessionHash: $overrides['sessionHash']
            ?? TokenClaims::hashSession($signer, REVALIDATE_SESSION),
        expiresAt: $overrides['expiresAt'] ?? time() + 3600,
        issuedAt: $overrides['issuedAt'] ?? time(),
    )))->encode($signer);
}

function session(): Closure
{
    return static fn (Request $r): string => $r->header('x-session') ?? REVALIDATE_SESSION;
}

describe('re-rendering after an action', function () {
    it('renders a route the action invalidated and the client is holding', function () {
        [$revalidator, $fixture, $signer] = revalidator();

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        // Keyed by PAGE -- route and params -- not by route name, so a
        // client can match a payload to the token it sent.
        expect($payloads)->toBe([
            Revalidator::pageKey('racks.show', [
                'site' => $fixture->ids['sites'][0],
                'rack' => $fixture->ids['racks'][0],
            ]) => 'payload:racks.show',
        ]);
    });

    /**
     * Keying by route name alone meant two tokens for the same route with
     * DIFFERENT params rendered twice and the second silently overwrote the
     * first -- the client got a payload for the wrong row and no way to
     * tell.
     */
    it('keeps two instances of one route apart', function () {
        [$revalidator, $fixture, $signer] = revalidator();

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [
                routeToken($signer, $fixture),
                routeToken($signer, $fixture, ['params' => [
                    'site' => $fixture->ids['sites'][0],
                    'rack' => $fixture->ids['racks'][1],
                ]]),
            ],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toHaveCount(2);
    });

    /**
     * The cap bounds RENDERS, applied after deduplication. It used to bound
     * tokens examined, so eight copies of one token were eight full GET
     * pipelines for one payload -- and the test for it passed with the cap
     * deleted, because dedupe-by-key guaranteed one payload either way.
     */
    it('renders a duplicated token once', function () {
        [$revalidator, $fixture, $signer, $panels, $orm] = revalidator();

        $renders = 0;
        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $counting = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static function (LoadedRoute $l, Request $r) use (&$renders): string {
                $renders++;

                return 'payload';
            },
            builds: new Tinvar\Http\BuildRegistry('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
            panels: $panels,
        );

        $counting->rerender(
            ['racks.show'],
            array_fill(0, 8, routeToken($signer, $fixture)),
            Request::post('/_action/x'),
            session(),
        );

        expect($renders)->toBe(1);
    });

    it('renders at most the cap, counted in pages', function () {
        // Enough real racks that every page resolves -- synthetic ids would
        // 404 in the loaders and never reach the render closure, so the
        // count would be zero whether or not the cap worked.
        $fixture = new Fixture(racks: Revalidator::MAX_ROUTES + 12, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $signer = new Signer('test-app-key', RouteToken::PURPOSE);
        $panels = new PanelContext();

        $renders = 0;
        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $counting = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static function (LoadedRoute $l, Request $r) use (&$renders): string {
                $renders++;

                return 'payload';
            },
            builds: new Tinvar\Http\BuildRegistry('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
            panels: $panels,
        );

        $tokens = [];
        foreach ($fixture->ids['racks'] as $rack) {
            $tokens[] = routeToken($signer, $fixture, ['params' => [
                'site' => $fixture->ids['sites'][0],
                'rack' => $rack,
            ]]);
        }

        expect($tokens)->toHaveCount(Revalidator::MAX_ROUTES + 12);

        $counting->rerender(['racks.show'], $tokens, Request::post('/_action/x'), session());

        expect($renders)->toBe(Revalidator::MAX_ROUTES);
    });

    // Neither side decides alone.
    it('ignores a token for a route the action did not invalidate', function () {
        [$revalidator, $fixture, $signer] = revalidator();

        $payloads = $revalidator->rerender(
            ['something.else'],
            [routeToken($signer, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    });

    it('renders nothing when the client holds no tokens', function () {
        [$revalidator] = revalidator();

        expect($revalidator->rerender(['racks.show'], [], Request::post('/_action/x'), session()))
            ->toBe([]);
    });
});

/**
 * "If the pipeline denies, the action result is returned WITHOUT a payload."
 * A revalidation is a courtesy attached to a write that already happened; a
 * page the session may no longer see must not turn a successful action into
 * a failed request.
 */
describe('a token this server will not act on', function () {
    it('omits the payload when the route pipeline denies', function () {
        [$revalidator, $fixture, $signer] = revalidator(new DenyGate());

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([], 'the write stands; the payload does not');
    });

    it('omits it for another session', function () {
        [$revalidator, $fixture, $signer] = revalidator();

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            new Request('POST', '/_action/x', ['x-session' => 'someone-else']),
            session(),
        );

        expect($payloads)->toBe([]);
    });

    it('omits it for another tenant', function () {
        [$revalidator, $fixture, $signer, , $orm] = revalidator();
        $orm->switchTenant('acme');

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture, ['tenant' => 'globex'])],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    });

    it('omits it for another panel', function () {
        [$revalidator, $fixture, $signer, $panels] = revalidator();

        $payloads = $panels->within('customer', static fn (): array => $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture, ['panel' => 'admin'])],
            Request::post('/_action/x'),
            session(),
        ));

        expect($payloads)->toBe([]);
    });

    it('omits it when the route no longer exists', function () {
        [$revalidator, $fixture, $signer] = revalidator();

        $payloads = $revalidator->rerender(
            ['gone.away'],
            [routeToken($signer, $fixture, ['route' => 'gone.away'])],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    });

    it('omits it for a forged or expired token', function (array $overrides) {
        [$revalidator, $fixture, $signer] = revalidator();

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture, $overrides)],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    })->with([
        'expired' => [['expiresAt' => time() - 1]],
        'no issue time' => [['issuedAt' => 0]],
        'too old to refresh' => [['issuedAt' => time() - RouteToken::MAX_LIFETIME - 1]],
    ]);

    it('omits it for a token signed with another key', function () {
        [$revalidator, $fixture] = revalidator();
        $theirs = new Signer('their-key', RouteToken::PURPOSE);

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($theirs, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    });

    // A route token signed for a different purpose must not verify here.
    it('omits it for a token minted for fragments', function () {
        [$revalidator, $fixture] = revalidator();
        $fragments = new Signer('test-app-key', Tinvar\Render\FragmentToken::PURPOSE);

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($fragments, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    });
});

/**
 * Through the endpoint, which is where a client actually meets it: the
 * action's response carries the payloads, keyed by route.
 */
describe('through the action endpoint', function () {
    it('carries payloads for the routes the client was holding', function () {
        [$revalidator, $fixture, $signer] = revalidator();
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new Tinvar\Actions\ActionRegistry();
        $registry->register(Tinvar\Tests\Actions\Fixtures\RackActions::class);

        $endpoint = new Tinvar\Http\Endpoints\ActionEndpoint(
            new Tinvar\Actions\ActionRunner($registry, $orm, new AllowAllGate(), new Tinvar\Validation\Validator($orm)),
            revalidator: $revalidator,
            sessionId: session(),
        );

        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode([
                'rack' => $fixture->ids['racks'][0],
                'position' => 12,
                '_routes' => [routeToken($signer, $fixture)],
            ]),
        );

        $body = json_decode($response->body(), true);

        expect($body['revalidated'])->toBe(['racks.show'])
            ->and($body['payloads'])->toHaveCount(1)
            ->and(array_values($body['payloads']))->toBe(['payload:racks.show']);
    });

    // The endpoint's own bound is on how much it will PARSE, which is a
    // different limit from how many pages render.
    it('looks at no more tokens than it will parse', function () {
        [$revalidator, $fixture, $signer] = revalidator();
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new Tinvar\Actions\ActionRegistry();
        $registry->register(Tinvar\Tests\Actions\Fixtures\RackActions::class);

        $endpoint = new Tinvar\Http\Endpoints\ActionEndpoint(
            new Tinvar\Actions\ActionRunner($registry, $orm, new AllowAllGate(), new Tinvar\Validation\Validator($orm)),
            revalidator: $revalidator,
            sessionId: session(),
        );

        $many = array_fill(
            0,
            Tinvar\Http\Endpoints\ActionEndpoint::MAX_ROUTE_TOKENS + 20,
            routeToken($signer, $fixture),
        );

        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode([
                'rack' => $fixture->ids['racks'][0],
                'position' => 12,
                '_routes' => $many,
            ]),
        );

        expect(json_decode($response->body(), true)['payloads'])->toHaveCount(1);
    });

    it('carries no payloads when the client sends no tokens', function () {
        [$revalidator, $fixture] = revalidator();
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new Tinvar\Actions\ActionRegistry();
        $registry->register(Tinvar\Tests\Actions\Fixtures\RackActions::class);

        $endpoint = new Tinvar\Http\Endpoints\ActionEndpoint(
            new Tinvar\Actions\ActionRunner($registry, $orm, new AllowAllGate(), new Tinvar\Validation\Validator($orm)),
            revalidator: $revalidator,
            sessionId: session(),
        );

        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        expect(json_decode($response->body(), true)['payloads'])->toBe([]);
    });
});

/**
 * "Each re-render runs that route's full GET pipeline: MIDDLEWARE, layout
 * and page #[Authorize], loaders."
 *
 * The middleware was the part missing. FragmentEndpoint runs a route's own
 * stack before its subtree renders and this ran only the loaders, so
 * anything a route enforces in middleware rather than in a policy -- a panel
 * guard, MFA, an allowlist, email verification -- applied on navigation and
 * not here. Two re-render paths, one of them weaker.
 */
describe('the route pipeline', function () {
    it('runs the route own middleware', function () {
        [, $fixture, $signer, $panels, $orm] = revalidator();

        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $revalidator = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static fn (LoadedRoute $l, Request $r): string => 'payload',
            builds: new Tinvar\Http\BuildRegistry('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
            panels: $panels,
        );

        Tinvar\Tests\Routing\Fixtures\Trace::reset();

        $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        // RackShowPage declares #[UseMiddleware(InnerMiddleware::class)].
        expect(Tinvar\Tests\Routing\Fixtures\Trace::$events)->toContain('middleware:inner');
    });

    // The action request is already inside the global stack; running it
    // again would double every counter and side effect it has.
    it('does not run the global stack a second time', function () {
        [, $fixture, $signer, $panels, $orm] = revalidator();

        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $revalidator = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static fn (LoadedRoute $l, Request $r): string => 'payload',
            builds: new Tinvar\Http\BuildRegistry('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
            panels: $panels,
            globalMiddleware: [Tinvar\Tests\Routing\Fixtures\OuterMiddleware::class],
        );

        Tinvar\Tests\Routing\Fixtures\Trace::reset();

        $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        expect(Tinvar\Tests\Routing\Fixtures\Trace::$events)
            ->not->toContain('middleware:outer');
    });

    // Middleware that refuses must read as "no payload", not as an empty one.
    it('omits the payload when middleware short-circuits', function () {
        [, $fixture, $signer, $panels, $orm] = revalidator();

        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $revalidator = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static fn (LoadedRoute $l, Request $r): string => 'payload',
            builds: new Tinvar\Http\BuildRegistry('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware
                => new class implements Tinvar\Http\Middleware {
                    public function handle(Request $request, Closure $next): Tinvar\Http\Response
                    {
                        throw new Tinvar\Http\ForbiddenException('not here');
                    }
                },
            panels: $panels,
        );

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    });
});

/**
 * A token is only useful if something issues one. The fragment feature
 * shipped a token nothing could mint and it took an end-of-phase review to
 * notice; this one is minted by the page render that the client will send
 * it back from.
 */
describe('minting a route token', function () {
    it('binds the route and params of the chain that was matched', function () {
        [, $fixture, $signer] = revalidator();

        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);
        $chain = $routes->chainFor('racks.show', [
            'site' => $fixture->ids['sites'][0],
            'rack' => $fixture->ids['racks'][0],
        ]);

        $minter = new Tinvar\Render\RouteMinter(
            signer: $signer,
            panel: 'admin',
            tenant: 'acme',
            build: 'b1',
            sessionId: REVALIDATE_SESSION,
        );

        $claims = RouteToken::decode($minter->mint($chain), $signer)->claims;

        expect($claims->route)->toBe('racks.show')
            ->and($claims->params['rack'])->toBe($fixture->ids['racks'][0])
            ->and($claims->panel)->toBe('admin')
            ->and($claims->tenant)->toBe('acme')
            ->and($claims->belongsTo($signer, REVALIDATE_SESSION))->toBeTrue();
    });

    // A token whose route cannot be named could never be re-rendered from.
    it('mints nothing for an unnamed route', function () {
        [, , $signer] = revalidator();

        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, Tinvar\Tests\Routing\Fixtures\RackIndexPage::class]);

        $minter = new Tinvar\Render\RouteMinter(
            signer: $signer,
            panel: '',
            tenant: '',
            build: 'b1',
            sessionId: REVALIDATE_SESSION,
        );

        $unnamed = new Tinvar\Routing\RouteChain(
            page: new Tinvar\Routing\CompiledRoute(
                kind: Tinvar\Routing\RouteKind::Page,
                class: RackShowPage::class,
                template: '/anon',
                view: 'anon',
                name: null,
                segments: ['anon'],
                parameters: [],
                middleware: [],
                checks: [],
            ),
            layouts: [],
            parameters: [],
        );

        expect($minter->mint($unnamed))->toBeNull();
    });

    it('refuses to mint with no session', function () {
        [, , $signer] = revalidator();

        expect(fn () => new Tinvar\Render\RouteMinter(
            signer: $signer,
            panel: '',
            tenant: '',
            build: 'b1',
            sessionId: '',
        ))->toThrow(InvalidArgumentException::class, 'session is required');
    });
});

/**
 * "Each re-render runs that route's full GET pipeline." What ran saw the
 * action's POST -- method, path and body -- so a loader reading the request
 * got action input, and middleware keyed on method or path behaved
 * differently here than on navigation. That is the asymmetry the feature
 * exists to close.
 */
describe('the request a re-render sees', function () {
    it('is a GET for the route, not the action POST', function () {
        [, $fixture, $signer, $panels, $orm] = revalidator();

        $seen = null;
        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $capturing = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static function (LoadedRoute $l, Request $r) use (&$seen): string {
                $seen = $r;

                return 'payload';
            },
            builds: new Tinvar\Http\BuildRegistry('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
            panels: $panels,
        );

        $capturing->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            new Request('POST', '/_action/RackActions.moveUnit', [], [], ['secret' => 'from the action']),
            session(),
        );

        expect($seen?->method)->toBe('GET')
            ->and($seen?->path)->toBe(
                '/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0]
            )
            ->and($seen?->body)->toBe([], 'the action body must not reach a loader');
    });

    // A bug in a loader used to look exactly like a permission result.
    it('reports a failure that is not a denial', function () {
        [, $fixture, $signer, $panels, $orm] = revalidator();

        $logged = [];
        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $broken = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static function (): string {
                throw new RuntimeException('the renderer is down');
            },
            builds: new Tinvar\Http\BuildRegistry('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
            panels: $panels,
            log: static function (Throwable $e) use (&$logged): void {
                $logged[] = $e->getMessage();
            },
        );

        $payloads = $broken->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture)],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([], 'the write still stands')
            ->and($logged)->toBe(['the renderer is down']);
    });

    // A revoked build means a full page load; that does not stop applying
    // because the token names a route instead of a component.
    it('omits a payload for a revoked build', function () {
        [, $fixture, $signer, $panels, $orm] = revalidator();

        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

        $revalidator = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static fn (LoadedRoute $l, Request $r): string => 'payload',
            builds: (new Tinvar\Http\BuildRegistry('build-2', ['build-1']))->revoke('build-1'),
            middlewareResolver: static fn (string $class): Tinvar\Http\Middleware => new $class(),
            panels: $panels,
        );

        $payloads = $revalidator->rerender(
            ['racks.show'],
            [routeToken($signer, $fixture, ['build' => 'build-1'])],
            Request::post('/_action/x'),
            session(),
        );

        expect($payloads)->toBe([]);
    });
});
