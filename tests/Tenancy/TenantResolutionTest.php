<?php

declare(strict_types=1);

/**
 * #[TenantFrom]: which tenant a request is for, decided before anything
 * that reads one runs.
 *
 * "Nothing decides which tenant a request is for; the ORM fails closed with
 * NoTenantContext until something does." These drive a two-tenant fixture
 * application through the whole request path -- pages, a route group,
 * fragments, a re-render after an action, an action, a query, /_bootstrap
 * -- since a path that ran a route without resolving its tenant would run it
 * as nobody, or as whoever the request arrived as.
 */

namespace Tinvar\Tests\Tenancy\TenantResolution;

use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Request;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\Signature;
use Tinvar\Tenancy\TenantHierarchy;
use Tinvar\Tenancy\TenantTree;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Tenancy\Fixtures\App;
use Tinvar\Tests\Tenancy\Fixtures\SessionTenant;
use Tinvar\Tests\Tenancy\Fixtures\SignIn;

use const Tinvar\Tests\Tenancy\Fixtures\SESSION;

require_once __DIR__ . '/Fixtures/app.php';

/** acme and globex, both under a reseller. */
final class ResellerOverBoth implements TenantTree
{
    public function parentOf(string $tenant): ?string
    {
        return \in_array($tenant, ['acme', 'globex'], true) ? 'reseller' : null;
    }

    public function descendantsOf(string $tenant): array
    {
        return $tenant === 'reseller' ? ['acme', 'globex'] : [];
    }
}

/** The two-tenant application, under a reseller, with a session's tenant read from a header. */
function underReseller(): App
{
    return new App(new TenantFixture()->connection, [SignIn::class, SessionTenant::class], tenants: new TenantHierarchy(new ResellerOverBoth()));
}

function app(?TenantFixture $fixture = null, array $global = [SignIn::class], ?FragmentManifest $manifest = null): App
{
    return new App(($fixture ?? new TenantFixture())->connection, $global, $manifest);
}

/** @return array<string, mixed> */
function page(App $app, string $path, string $host, array $headers = [], array $query = []): array
{
    $response = $app->get($path, $host, $headers, $query);
    expect($response->status)->toBe(200, $response->body());

    return App::json($response)['page'];
}

describe('a host-bound route group', function () {
    it('runs every page in it as the tenant its host belongs to', function () {
        $app = app();

        expect(page($app, '/t/scripts', 'acme.test'))->toBe(['tenant' => 'acme', 'names' => ['acme-script-1', 'acme-script-2']])
            ->and(page($app, '/t/scripts', 'globex.test'))->toBe(['tenant' => 'globex', 'names' => ['globex-script-1', 'globex-script-2']]);
    });

    // Normalized before the application is asked, so one tenant is not two
    // lookups that behave differently.
    it('reads the host however it is spelled', function () {
        expect(page(app(), '/t/scripts', 'ACME.test.:8443')['tenant'])->toBe('acme');
    });

    it('binds route models in that tenant, so another tenant\'s id is not found', function () {
        $fixture = new TenantFixture();
        $app = app($fixture);

        expect(page($app, '/t/scripts/' . $fixture->scripts['acme'][0], 'acme.test'))->toBe(['name' => 'acme-script-1'])
            ->and($app->get('/t/scripts/' . $fixture->scripts['globex'][0], 'acme.test')->status)->toBe(404);
    });

    it('takes a page declaring its own source as well as one in a group', function () {
        expect(page(app(), '/own/scripts', 'globex.test')['tenant'])->toBe('globex');
    });

    it('leaves no tenant behind once the request is over', function () {
        $app = app();
        page($app, '/t/scripts', 'acme.test');

        expect($app->orm->tenant())->toBe('');
    });
});

describe('failing closed', function () {
    it('refuses a host that belongs to no tenant with a 404, before any loader runs', function () {
        $response = app()->get('/t/scripts', 'nobody.test');

        expect($response->status)->toBe(404)
            ->and(App::json($response)['error']['code'])->toBe('tenant_unknown');
    });

    it('refuses an address, or no host at all, the same way', function () {
        $app = app();

        expect($app->get('/t/scripts', '127.0.0.1')->status)->toBe(404)
            ->and($app->get('/t/scripts', '')->status)->toBe(404);
    });

    // NoTenantContext is classified: it says "not here" with a code of its
    // own, not a bare 500 that reads as a crash.
    it('answers a tenant-scoped read on a route that names no tenant with a classified 404', function () {
        $response = app()->get('/forgot/scripts', 'acme.test');

        expect($response->status)->toBe(404)
            ->and(App::json($response)['error']['code'])->toBe('no_tenant');
    });

    // "The tenant resolved from a host only constrains." A session already
    // running as one tenant is not switched into another by a Host header.
    it('refuses a host naming a tenant other than the one the request already runs as', function () {
        $app = app(global: [SignIn::class, SessionTenant::class]);

        expect(page($app, '/t/scripts', 'acme.test', ['x-session-tenant' => 'acme'])['tenant'])->toBe('acme')
            ->and($app->get('/t/scripts', 'acme.test', ['x-session-tenant' => 'globex'])->status)->toBe(404);
    });
});

// "The tenant resolved from a host only constrains: the session's tenant
// must be that tenant or a descendant." With the application's tree, a
// session running below the host's tenant goes on as its own.
describe('a host over a tenant hierarchy', function () {
    it('runs a session below the host\'s tenant as the session\'s own', function () {
        $app = underReseller();

        expect(page($app, '/t/scripts', 'reseller.test', ['x-session-tenant' => 'acme']))
            ->toBe(['tenant' => 'acme', 'names' => ['acme-script-1', 'acme-script-2']])
            ->and(page($app, '/t/scripts', 'reseller.test', ['x-session-tenant' => 'globex'])['tenant'])->toBe('globex')
            ->and(page($app, '/t/scripts', 'reseller.test')['tenant'])->toBe('reseller');
    });

    it('refuses a session above the host\'s tenant, or beside it', function () {
        $app = underReseller();

        expect($app->get('/t/scripts', 'acme.test', ['x-session-tenant' => 'reseller'])->status)->toBe(404)
            ->and($app->get('/t/scripts', 'acme.test', ['x-session-tenant' => 'globex'])->status)->toBe(404);
    });

    it('holds a session to the host\'s tenant exactly with no tree', function () {
        $app = app(global: [SignIn::class, SessionTenant::class]);

        expect($app->get('/t/scripts', 'reseller.test', ['x-session-tenant' => 'acme'])->status)->toBe(404);
    });

    // A signed link names the tenant whose data it was signed to show.
    it('keeps a signed link to the tenant it names, even above the session\'s', function () {
        $app = underReseller();
        $url = parse_url($app->urls->sign('/links/scripts', 'reseller'));
        parse_str($url['query'] ?? '', $query);

        expect($app->get($url['path'] ?? '', 'acme.test', ['x-session-tenant' => 'acme'], $query)->status)->toBe(404);
    });

    // Every token is bound to the one tenant the request runs as.
    it('binds what it mints to the session\'s tenant, and takes back only that', function () {
        $app = underReseller();

        $claims = RouteToken::decode((string) $app->get('/t/scripts', 'reseller.test', ['x-session-tenant' => 'acme'])
            ->header('x-route-token'), $app->routeSigner)->claims;
        $acme = $app->get(FragmentEndpoint::PREFIX . $app->fragmentToken('acme'), 'reseller.test', ['x-session-tenant' => 'acme']);
        $parent = $app->get(FragmentEndpoint::PREFIX . $app->fragmentToken('reseller'), 'reseller.test', ['x-session-tenant' => 'acme']);

        expect($claims->tenant)->toBe('acme')
            ->and($acme->status)->toBe(200)
            ->and($parent->status)->toBe(404)
            ->and($app->fragments)->toBe(['acme']);
    });
});

describe('a signed link', function () {
    it('runs as the tenant its signature names, whatever the host', function () {
        $app = app();
        $url = parse_url($app->urls->sign('/links/scripts', 'globex'));
        parse_str($url['query'] ?? '', $query);

        expect(page($app, $url['path'] ?? '', 'acme.test', query: $query)['tenant'])->toBe('globex');
    });

    it('refuses one whose tenant was edited, or that carries none', function () {
        $app = app();
        parse_str(parse_url($app->urls->sign('/links/scripts', 'globex'))['query'] ?? '', $query);

        expect($app->get('/links/scripts', 'acme.test', query: [...$query, 'tenant' => 'acme'])->status)->toBe(404)
            ->and($app->get('/links/scripts', 'acme.test')->status)->toBe(404);
    });
});

describe('what the client holds', function () {
    // The route token carries the tenant the route resolved, so the client's
    // binding -- session, user, tenant, panel -- changes when the tenant
    // does, and what it holds is purged.
    it('binds the route token and the history key to the resolved tenant', function () {
        $app = app();

        $acme = $app->get('/t/scripts', 'acme.test');
        $globex = $app->get('/t/scripts', 'globex.test');

        $acmeClaims = RouteToken::decode((string) $acme->header('x-route-token'), $app->routeSigner)->claims;
        $globexClaims = RouteToken::decode((string) $globex->header('x-route-token'), $app->routeSigner)->claims;

        expect($acmeClaims->tenant)->toBe('acme')
            ->and($globexClaims->tenant)->toBe('globex')
            ->and($acmeClaims->sessionHash)->toBe($globexClaims->sessionHash)
            ->and(App::json($acme)['historyKey'])->toBeString()
            ->and(App::json($acme)['historyKey'])->not->toBe(App::json($globex)['historyKey']);
    });

    it('renders a fragment minted for the tenant the request resolves to', function () {
        $app = app();
        $token = $app->fragmentToken('acme');

        $response = $app->get(FragmentEndpoint::PREFIX . $token, 'acme.test');

        expect($response->status)->toBe(200)->and($app->fragments)->toBe(['acme']);
    });

    // "A tenant in a token that doesn't match the resolved tenant is
    // refused." The token never establishes a tenant; it has to agree with
    // the one the host resolves.
    it('refuses a fragment minted for another tenant', function () {
        $app = app();
        $token = $app->fragmentToken('acme');

        expect($app->get(FragmentEndpoint::PREFIX . $token, 'globex.test')->status)->toBe(404)
            ->and($app->fragments)->toBe([]);
    });

    it('hands /_bootstrap\'s visitor tokens for the tenant the page\'s route resolves to', function () {
        $fixture = new TenantFixture();
        $manifest = (new FragmentManifest())->declare('scripts.index', 'recent', 'Recent', [], []);
        $app = app($fixture, manifest: $manifest);

        $response = $app->post(BootstrapEndpoint::PATH, 'globex.test', ['route' => 'scripts.index', 'params' => []]);
        $token = FragmentToken::decode(App::json($response)['tokens']['recent'], $app->fragmentSigner);

        expect($token->tenant)->toBe('globex')
            ->and($app->post(BootstrapEndpoint::PATH, 'nobody.test', ['route' => 'scripts.index', 'params' => []])->body())
            ->toBe('{"tokens":[]}');
    });
});

describe('an action', function () {
    it('resolves its model arguments in the tenant its host belongs to', function () {
        $fixture = new TenantFixture();
        $app = app($fixture);

        $ours = $app->post('/_action/ScriptActions.touch', 'acme.test', ['script' => $fixture->scripts['acme'][0]]);
        $theirs = $app->post('/_action/ScriptActions.touch', 'acme.test', ['script' => $fixture->scripts['globex'][0]]);

        expect($ours->status)->toBe(200)
            ->and(App::json($ours)['value'])->toBe('acme-script-1')
            ->and($theirs->status)->toBe(404);
    });

    it('fails closed when it names no tenant', function () {
        $fixture = new TenantFixture();

        $response = app($fixture)->post('/_action/ScriptActions.forgot', 'acme.test', ['script' => $fixture->scripts['acme'][0]]);

        expect($response->status)->toBe(404)->and(App::json($response)['error']['code'])->toBe('no_tenant');
    });

    // The page it re-renders is held to the tenant the page's own route
    // resolves for this request: a token from another tenant's page gets
    // the action's result and no payload.
    it('re-renders a page whose token names the tenant the route resolves to, and no other', function () {
        $fixture = new TenantFixture();
        $app = app($fixture);
        $acmeToken = (string) $app->get('/t/scripts', 'acme.test')->header('x-route-token');
        $globexToken = (string) $app->get('/t/scripts', 'globex.test')->header('x-route-token');

        $mine = $app->post('/_action/ScriptActions.touch', 'acme.test', [
            'script' => $fixture->scripts['acme'][0],
            '_routes' => [$acmeToken],
        ]);
        $foreign = $app->post('/_action/ScriptActions.touch', 'acme.test', [
            'script' => $fixture->scripts['acme'][0],
            '_routes' => [$globexToken],
        ]);

        expect(App::json($mine)['payloads'])->toBe(['scripts.index?' => 'RSC-ROUTE'])
            ->and(App::json($foreign)['payloads'])->toBe([])
            ->and($app->rerendered)->toBe([['route' => 'scripts.index', 'tenant' => 'acme']]);
    });
});

it('runs a query called over HTTP as the tenant its host belongs to', function () {
    $response = app()->post('/_query/scripts.names', 'globex.test', ['args' => new \stdClass()]);

    expect($response->status)->toBe(200)
        ->and(App::json($response)['value'])->toBe(['globex-script-1', 'globex-script-2']);
});

#[Layout('/clash', view: 'clash/layout'), TenantFrom(Host::class)]
final class HostGroup {}

#[Page('/clash/page', view: 'clash/page', name: 'clash.page'), TenantFrom(Signature::class)]
final class SignedPageInHostGroup {}

#[Layout('/clash/inner', view: 'clash/inner/layout'), TenantFrom(Signature::class)]
final class SignedGroupInHostGroup {}

final class NotASource {}

describe('declaring one', function () {
    // Which should win has no good answer, so neither does: the pair is
    // refused when the second of them registers, in either order.
    it('refuses a page and its group that name different sources, whichever registers first', function () {
        expect(fn () => (new RouteRegistry())->registerAll([HostGroup::class, SignedPageInHostGroup::class]))
            ->toThrow(RoutingException::class, 'one tenant source')
            ->and(fn () => (new RouteRegistry())->registerAll([SignedPageInHostGroup::class, HostGroup::class]))
            ->toThrow(RoutingException::class, 'one tenant source');
    });

    it('refuses a group inside a group that names a different source', function () {
        expect(fn () => (new RouteRegistry())->registerAll([SignedGroupInHostGroup::class, HostGroup::class]))
            ->toThrow(RoutingException::class, 'one tenant source');
    });

    // The attribute is the framework's and knows no tenant source: the class
    // is checked to exist when it is read, and to be a source by the tenancy
    // registered, when the entry point is (EntryContext::check()).
    it('refuses a source that is not one when it is registered, and one that is not there when it is read', function () {
        expect(fn () => new Tenancy(static fn (string $c): object => new $c())->check(NotASource::class))
            ->toThrow(\InvalidArgumentException::class, 'names no tenant source')
            ->and(fn () => new TenantFrom('Tinvar\\Tenancy\\Nowhere'))->toThrow(\InvalidArgumentException::class, 'names no class');
    });

    it('keeps the session binding in every token it mints', function () {
        $app = app();
        $claims = RouteToken::decode((string) $app->get('/t/scripts', 'acme.test')->header('x-route-token'), $app->routeSigner)->claims;

        expect($claims->belongsTo($app->routeSigner, SESSION))->toBeTrue();
    });
});
