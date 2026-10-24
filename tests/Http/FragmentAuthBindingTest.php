<?php

declare(strict_types=1);

/**
 * Fragment tokens -- a page's and /_bootstrap's -- bound to who is signed in
 * and their auth epoch, as route tokens are (ADR 0019).
 *
 * A role changed, or an impersonation started, with the session id kept: a
 * route token from before no longer counts, and neither may a fragment
 * token from the page it came with. Bound to the session alone, it went on
 * rendering more of a page drawn for someone else.
 */

namespace Tinvar\Tests\Http\FragmentAuthBinding;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\TokenClaims;
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

const SESSION = 'session-abc';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** @return array{FragmentSupport, AuthContext, Signer, Fixture, RouteRegistry} */
function support(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);
    $auth = new AuthContext();
    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

    return [new FragmentSupport(
        signer: $signer,
        builds: new BuildRegistry('build-1'),
        render: static fn (): mixed => null,
        sessionId: static fn (Request $r): string => SESSION,
        auth: $auth,
    ), $auth, $signer, $fixture, $routes];
}

/** @return array<string, string> */
function params(Fixture $fixture): array
{
    return ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]];
}

describe('a fragment token', function () {
    it('names who was signed in, at which auth epoch, from a page render', function () {
        [$support, $auth, $signer, $fixture] = support();
        $auth->login(new Member('m-1'), '3');

        $token = FragmentToken::decode(
            $support->minterFor(Request::get('/'), 'racks.show', params($fixture))->mint('UnitHistory', []),
            $signer,
        );

        expect($token->authHash)->toBe(TokenClaims::hashAuth($signer, 'm-1', '3'))
            ->and($token->belongsTo($signer, SESSION))->toBeTrue();
    });

    // A static page's boundaries are handed their tokens per visitor, by
    // /_bootstrap: bound as a rendered page's are.
    it('names them from /_bootstrap too', function () {
        [$support, $auth, $signer, $fixture, $routes] = support();
        $auth->login(new Member('m-1'), '3');
        $manifest = (new FragmentManifest())->declare('racks.show', 'history', 'UnitHistory', [], params($fixture));

        $response = new BootstrapEndpoint($routes, $manifest, $support)->handle(
            Request::post(BootstrapEndpoint::PATH),
            json_encode(['route' => 'racks.show', 'params' => params($fixture)], JSON_THROW_ON_ERROR),
        );
        $token = FragmentToken::decode(json_decode($response->body(), true)['tokens']['history'], $signer);

        expect($token->authHash)->toBe(TokenClaims::hashAuth($signer, 'm-1', '3'));
    });

    it('keeps the binding when it is refreshed', function () {
        [$support, $auth, $signer, $fixture] = support();
        $auth->login(new Member('m-1'), '3');
        $token = FragmentToken::decode($support->minterFor(Request::get('/'), 'racks.show', params($fixture))->mint('UnitHistory', []), $signer);

        expect($token->refreshed()?->authHash)->toBe($token->authHash);
    });
});

/** @return array{FragmentEndpoint, AuthContext, Signer, Fixture} */
function endpoint(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);
    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);
    $auth = new AuthContext();

    return [new FragmentEndpoint(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
        orm: $orm,
        signer: $signer,
        builds: new BuildRegistry('build-1'),
        render: static fn (): Response => Response::make(200, 'RSC-PAYLOAD', ['content-type' => 'text/x-component']),
        sessionId: static fn (Request $r): string => SESSION,
        middlewareResolver: static fn (string $class): Middleware => new $class(),
        panels: new PanelContext(),
        auth: $auth,
    ), $auth, $signer, $fixture];
}

function token(Signer $signer, Fixture $fixture, string $authHash): FragmentToken
{
    return new FragmentToken(
        component: 'UnitHistory',
        props: [],
        route: 'racks.show',
        params: params($fixture),
        panel: '',
        tenant: '',
        build: 'build-1',
        sessionHash: FragmentToken::hashSession($signer, SESSION),
        expiresAt: time() + 3600,
        issuedAt: time(),
        authHash: $authHash,
    );
}

function fetch(FragmentEndpoint $endpoint, Signer $signer, FragmentToken $token): int
{
    try {
        return $endpoint->handle(new Request('GET', FragmentEndpoint::PREFIX . $token->encode($signer)))->status;
    } catch (HttpException $e) {
        return $e->status;
    }
}

describe('the fragment endpoint', function () {
    it('renders a token minted for whoever is signed in now, at their epoch', function () {
        [$endpoint, $auth, $signer, $fixture] = endpoint();
        $auth->login(new Member('m-1'), '3');

        expect(fetch($endpoint, $signer, token($signer, $fixture, TokenClaims::hashAuth($signer, 'm-1', '3'))))->toBe(200);
    });

    it('refuses one minted before the auth epoch moved, in the same session', function () {
        [$endpoint, $auth, $signer, $fixture] = endpoint();
        $auth->login(new Member('m-1'), '4');

        expect(fetch($endpoint, $signer, token($signer, $fixture, TokenClaims::hashAuth($signer, 'm-1', '3'))))->toBe(404);
    });

    it('refuses one minted for another user in the same session', function () {
        [$endpoint, $auth, $signer, $fixture] = endpoint();
        $auth->login(new Member('customer'), '1');

        expect(fetch($endpoint, $signer, token($signer, $fixture, TokenClaims::hashAuth($signer, 'agent', '1'))))->toBe(404);
    });

    // Minted before tokens named the user: bound to the session, as it was.
    it('renders one that names no user, as a token always could', function () {
        [$endpoint, $auth, $signer, $fixture] = endpoint();
        $auth->login(new Member('m-1'), '3');

        expect(fetch($endpoint, $signer, token($signer, $fixture, '')))->toBe(200);
    });
});
