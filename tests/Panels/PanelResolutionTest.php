<?php

declare(strict_types=1);

/**
 * Panels resolved from a route, a route group or a namespace, with the
 * panel's stack in front of them and its membership model enforced.
 *
 * "#[Panel]'s two extra fields are carried and not yet enforced." These
 * drive a fixture application with a staff panel and a reseller panel
 * through every path the spec's endpoint table gives a panel's stack --
 * pages, fragments, the re-render after an action, actions, queries -- and
 * hold `requires:` and `permission:` to what the application's Memberships
 * answers.
 */

namespace Tinvar\Tests\Panels\PanelResolution;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Panels\Fixtures\App;
use Tinvar\Tests\Panels\Fixtures\Directory;
use Tinvar\Tests\Panels\Fixtures\HostPanel;
use Tinvar\Tests\Panels\Fixtures\SignIn;
use Tinvar\Tests\Panels\Fixtures\Trace;

require_once __DIR__ . '/Fixtures/app.php';

/** @return array<string, mixed> */
function page(App $app, string $path, ?string $user, array $headers = [], string $host = 'shop.test'): array
{
    $response = $app->get($path, $user, $headers, $host);
    expect($response->status)->toBe(200, $response->body());

    return App::json($response)['page'];
}

describe('resolving a panel', function () {
    it('puts every page in a route group in the group\'s panel', function () {
        expect(page(new App(), '/admin/dashboard', 'staff')['panel'])->toBe('admin');
    });

    it('puts a page in its namespace\'s panel when it declares none', function () {
        $app = new App();

        expect(page($app, '/reports', 'staff')['panel'])->toBe('admin')
            ->and(Trace::$events)->toContain('guard:admin');
    });

    it('leaves a page in no panel when nothing puts it in one', function () {
        $app = new App();

        expect(page($app, '/home', 'customer')['panel'])->toBe('')
            ->and($app->directory->asked)->toBe([]);
    });

    it('is gone once the request is over', function () {
        $app = new App();
        page($app, '/admin/dashboard', 'staff');

        expect($app->panels->currentOrNull())->toBeNull();
    });

    // A panel only narrows: a request the application already put in
    // another panel is not moved into this one.
    it('refuses a request already in another panel', function () {
        $app = new App(global: [SignIn::class, HostPanel::class]);

        expect($app->get('/admin/dashboard', 'staff', ['x-panel' => 'admin'])->status)->toBe(200)
            ->and($app->get('/admin/dashboard', 'staff', ['x-panel' => 'reseller'])->status)->toBe(404);
    });
});

describe('the panel\'s stack', function () {
    // "guard, MFA, network allowlist, email verification": the guard first,
    // then the membership check, then the panel's own extension points,
    // then the page's middleware, then the loaders -- all inside the panel.
    it('runs in front of the page\'s own middleware and loaders, in order, inside the panel', function () {
        $app = new App();
        page($app, '/admin/dashboard', 'staff');

        expect(Trace::$events)->toBe(['guard:admin', 'membership', 'mfa', 'page-middleware', 'loader']);
    });

    it('refuses nobody signed in with a 401, and a user with no membership with a 403', function () {
        $app = new App();

        expect($app->get('/admin/dashboard', null)->status)->toBe(401)
            ->and($app->get('/admin/dashboard', 'customer')->status)->toBe(403)
            ->and(Trace::$events)->not->toContain('loader');
    });

    // The definition's permission holds for every page in the panel; a page's
    // own narrows further. Neither replaces the other.
    it('holds a membership to the definition\'s permission and the page\'s both', function () {
        $app = new App();

        expect(page($app, '/admin/orders', 'staff')['permissions'])->toBe(['admin.access', 'orders.view', 'orders.refund'])
            ->and($app->get('/admin/dashboard', 'clerk')->status)->toBe(200)
            ->and($app->get('/admin/orders', 'clerk')->status)->toBe(403);
    });

    // "Widening comes from the session's membership: its tenant kind plus a
    // panel permission." The membership is the one held in the tenant the
    // request resolved, and its kind is compared by name, enum or string.
    it('holds `requires:` to the kind of tenant the membership is held in, there', function () {
        $app = new App();

        expect(page($app, '/reseller', 'partner', host: 'acme.test')['panel'])->toBe('reseller')
            ->and($app->get('/reseller', 'partner', host: 'globex.test')->status)->toBe(403)
            ->and($app->directory->asked)->toBe([['partner', 'reseller', 'acme'], ['partner', 'reseller', 'globex']]);
    });
});

describe('what the client holds', function () {
    // "Client-held data ... is cleared ... on panel switch": the route token
    // and the history key carry the panel, so moving between panels changes
    // the client's binding and it purges what it held.
    it('binds the route token and the history key to the panel', function () {
        $app = new App();

        $admin = $app->get('/admin/dashboard', 'staff');
        $home = $app->get('/home', 'staff');

        $adminClaims = RouteToken::decode((string) $admin->header('x-route-token'), $app->routeSigner)->claims;
        $homeClaims = RouteToken::decode((string) $home->header('x-route-token'), $app->routeSigner)->claims;

        expect($adminClaims->panel)->toBe('admin')
            ->and($homeClaims->panel)->toBe('')
            ->and($adminClaims->authHash)->toBe($homeClaims->authHash)
            ->and(App::json($admin)['historyKey'])->not->toBe(App::json($home)['historyKey']);
    });

    it('hands /_bootstrap\'s visitor tokens for the panel the page\'s route is in', function () {
        $manifest = (new FragmentManifest())->declare('admin.orders', 'recent', 'Recent', [], []);
        $app = new App(manifest: $manifest);

        $response = $app->post(BootstrapEndpoint::PATH, 'staff', ['route' => 'admin.orders', 'params' => []]);
        $token = FragmentToken::decode(App::json($response)['tokens']['recent'], $app->fragmentSigner);

        expect($token->panel)->toBe('admin');
    });
});

describe('a fragment', function () {
    it('runs its route\'s panel stack, and renders in the panel', function () {
        $app = new App();
        $token = $app->fragmentToken('admin.orders', 'staff', 'admin');

        $response = $app->get(FragmentEndpoint::PREFIX . $token, 'staff');

        expect($response->status)->toBe(200)
            ->and(Trace::$events)->toBe(['guard:admin', 'membership', 'mfa', 'fragment'])
            ->and($app->fragments)->toBe(['admin']);
    });

    it('is refused by the panel\'s membership check like the page', function () {
        $app = new App();
        $token = $app->fragmentToken('admin.orders', 'clerk', 'admin');

        expect($app->get(FragmentEndpoint::PREFIX . $token, 'clerk')->status)->toBe(403)
            ->and($app->fragments)->toBe([]);
    });

    it('is refused when its token names another panel than its route is in', function () {
        $app = new App();
        $token = $app->fragmentToken('admin.orders', 'staff', '');

        expect($app->get(FragmentEndpoint::PREFIX . $token, 'staff')->status)->toBe(404)
            ->and(Trace::$events)->toBe([]);
    });
});

describe('an action', function () {
    it('runs its panel\'s stack before its body, with its own permission held too', function () {
        $app = new App();

        $staff = $app->post('/_action/AdminActions.refund', 'staff');
        $events = Trace::$events;
        $clerk = $app->post('/_action/AdminActions.refund', 'clerk');

        expect($staff->status)->toBe(200)
            ->and($events)->toBe(['guard:admin', 'membership', 'mfa', 'action'])
            ->and($clerk->status)->toBe(403);
    });

    // The page it re-renders goes through its own panel's stack again.
    it('re-renders an admin page through that page\'s panel stack', function () {
        $app = new App();
        $token = (string) $app->get('/admin/orders', 'staff')->header('x-route-token');
        Trace::reset();

        $response = $app->post('/_action/AdminActions.refund', 'staff', ['_routes' => [$token]]);

        expect(App::json($response)['payloads'])->toBe(['admin.orders?' => 'RSC-ROUTE'])
            ->and(Trace::$events)->toBe(['guard:admin', 'membership', 'mfa', 'action', 'guard:admin', 'membership', 'mfa', 'loader', 'rerender']);
    });
});

it('runs a query\'s panel stack on a call over HTTP', function () {
    $app = new App();

    $response = $app->post('/_query/admin.stats', 'staff', ['args' => new \stdClass()]);

    expect($response->status)->toBe(200)
        ->and(Trace::$events)->toBe(['guard:admin', 'membership', 'mfa', 'query'])
        ->and($app->post('/_query/admin.stats', 'customer', ['args' => new \stdClass()])->status)->toBe(403);
});

#[Page('/typo', view: 'typo', name: 'typo'), Panel('admn')]
final class TypoPage {}

#[Layout('/clash', view: 'clash/layout'), Panel('admin')]
final class AdminClashGroup {}

#[Page('/clash/page', view: 'clash/page', name: 'clash.page'), Panel('reseller')]
final class ResellerPageInAdminGroup {}

#[Page('/narrowed', view: 'narrowed', name: 'narrowed'), Panel('reseller', permission: 'reseller.access')]
final class NarrowedPage {}

#[Panel('admin')]
final class SplitActions
{
    #[Action, NoAuthorization(reason: 'a fixture action'), Panel('reseller')]
    public function both(): ActionResult
    {
        return ActionResult::ok();
    }
}

/** A Kernel over $routes alone, with the fixture's panels and the memberships given. */
function boot(array $routes, ?Directory $memberships = null, ?PanelRegistry $panels = null): Kernel
{
    $registry = new RouteRegistry();
    $registry->registerAll($routes);

    return new Kernel(
        orm: new Orm((new TenantFixture())->connection, new IdentityMap('test')),
        routes: $registry,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        panelRegistry: $panels ?? App::panels(),
        memberships: $memberships,
    );
}

describe('declaring one', function () {
    // A typo would be a page with no guard and no stack that looked as if it
    // had both. Refused when the Kernel is built, not found by a visitor.
    it('refuses a panel the application does not define', function () {
        expect(fn () => boot([TypoPage::class], new Directory()))
            ->toThrow(\InvalidArgumentException::class, "panel 'admn', which the application does not define");
    });

    it('refuses a requirement with no Memberships to hold it to, declared or defined', function () {
        expect(fn () => boot([NarrowedPage::class]))->toThrow(\InvalidArgumentException::class, 'no Memberships')
            ->and(fn () => boot([], panels: new PanelRegistry(new PanelDefinition('admin', permission: 'admin.access'))))
            ->toThrow(\InvalidArgumentException::class, 'no Memberships');
    });

    it('refuses a page in another panel than its route group, whichever registers first', function () {
        expect(fn () => (new RouteRegistry())->registerAll([AdminClashGroup::class, ResellerPageInAdminGroup::class]))
            ->toThrow(RoutingException::class, 'one panel')
            ->and(fn () => (new RouteRegistry())->registerAll([ResellerPageInAdminGroup::class, AdminClashGroup::class]))
            ->toThrow(RoutingException::class, 'one panel');
    });

    it('refuses an action whose class and method name different panels', function () {
        expect(fn () => (new ActionRegistry())->register(SplitActions::class))->toThrow(ActionException::class, 'one panel');
    });

    it('refuses two panels of one name, or one namespace in two panels', function () {
        expect(fn () => new PanelRegistry(new PanelDefinition('admin'), new PanelDefinition('admin')))
            ->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new PanelRegistry(
                new PanelDefinition('a', namespaces: ['App\\Admin\\']),
                new PanelDefinition('b', namespaces: ['App\\Admin\\']),
            ))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new PanelDefinition('a', namespaces: ['App\\Admin']))->toThrow(\InvalidArgumentException::class);
    });

    it('places a class by its most specific namespace', function () {
        $panels = new PanelRegistry(
            new PanelDefinition('admin', namespaces: ['App\\Admin\\']),
            new PanelDefinition('billing', namespaces: ['App\\Admin\\Billing\\']),
        );

        expect($panels->panelOf('App\\Admin\\Billing\\Invoices'))->toBe('billing')
            ->and($panels->panelOf('App\\Admin\\Users'))->toBe('admin')
            ->and($panels->panelOf('App\\AdminTools\\X'))->toBeNull();
    });
});
