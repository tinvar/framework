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

        expect($payloads)->toBe(['racks.show' => 'payload:racks.show']);
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
            ->and($body['payloads'])->toBe(['racks.show' => 'payload:racks.show']);
    });

    // Each re-render is a full GET pipeline, so an unbounded list would let
    // one write cost many renders.
    it('bounds how many routes one action may re-render', function () {
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
            Tinvar\Http\Endpoints\ActionEndpoint::MAX_REVALIDATED_ROUTES + 20,
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

        // They are all the same route, so one payload -- the cap is about
        // how many are LOOKED AT, which the duplicate tokens make visible
        // only by not blowing up.
        expect(json_decode($response->body(), true)['payloads'])
            ->toBe(['racks.show' => 'payload:racks.show']);
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
