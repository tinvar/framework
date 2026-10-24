<?php

declare(strict_types=1);

/**
 * A tenant hierarchy through the request path: the one widening a panel's
 * membership may grant, the platform's panels kept off the tenants' hosts,
 * and a tenant switcher held to the subtree a realm bounds.
 *
 * tinvar-spec.md, "Panels and scope" and "Identity across domains":
 * "Widening (a reseller's customers, all tenants for staff) comes from the
 * session's membership: its tenant kind plus a panel permission"; "The
 * admin panel is mapped only to platform-owned hosts"; "A session created
 * on it can only hold memberships within that domain's tenant subtree, and
 * the tenant switcher is limited to that subtree."
 */

namespace Tinvar\Tests\Tenancy\Hierarchy;

use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Tenancy\TenantSwitcher;
use Tinvar\Tests\Tenancy\Fixtures\Hierarchy\App;
use Tinvar\Tests\Tenancy\Fixtures\Hierarchy\Member;
use Tinvar\Tests\Tenancy\Fixtures\Hierarchy\Sessions;
use Tinvar\Tests\Tenancy\Fixtures\Hierarchy\Trace;

require_once __DIR__ . '/Fixtures/hierarchy_app.php';

function errorCode(\Tinvar\Http\Response $response): string
{
    return (string) (App::json($response)['error']['code'] ?? '');
}

describe('a panel reaching its tenant\'s subtree', function () {
    it('lets a member with the panel\'s tenant kind and permission read across it', function () {
        $app = new App();

        $response = $app->get('/r/scripts', 'reseller.platform.test', 'partner');

        expect($response->status)->toBe(200, $response->body())
            ->and(App::json($response))->toBe([
                'tenant' => 'reseller',
                'names' => ['acme-script', 'acme-west-script', 'globex-script', 'reseller-script'],
            ]);
    });

    it('refuses a member without the permission before anything reads', function () {
        $app = new App();

        expect($app->get('/r/scripts', 'reseller.platform.test', 'clerk')->status)->toBe(403)
            ->and($app->get('/r/scripts', 'acme.platform.test', 'partner')->status)->toBe(403)
            ->and($app->get('/r/scripts', 'reseller.platform.test')->status)->toBe(401);
    });

    // Widening is the membership's, in a panel that says so: a page in any
    // other panel that tries fails rather than reads.
    it('grants nothing to a page in a panel that does not reach the subtree', function () {
        $app = new App();

        $exact = $app->get('/c/scripts', 'reseller.platform.test', 'partner');
        $widened = $app->get('/c/widen', 'reseller.platform.test', 'partner');

        expect(App::json($exact))->toBe(['tenant' => 'reseller', 'names' => ['reseller-script']])
            ->and($widened->status)->toBe(500)
            ->and($app->log)->toContain(
                'withinSubtree() reads below the tenant in force, which only a membership grants: run it in a panel '
                . 'defined with `subtree: true`, whose membership check grants it.',
            );
    });

    it('is declared with the tenant kind and the permission its widening comes from', function () {
        expect(fn () => new PanelDefinition('reseller', permission: 'reseller.access', subtree: true))
            ->toThrow(\InvalidArgumentException::class, 'names both')
            ->and(fn () => new PanelDefinition('reseller', requires: 'reseller', subtree: true))
            ->toThrow(\InvalidArgumentException::class, 'names both');
    });

    it('is refused when the Kernel is built with no tenant tree to reach across', function () {
        expect(fn () => new App(null))->toThrow(\InvalidArgumentException::class, 'no tenant tree to reach across');
    });

    // "The panel and its effective scope" are in the query cache's key.
    it('keys a cached query apart for a request granted the subtree', function () {
        $panels = new PanelContext();
        $cache = new QueryCache(new ArrayStore(), new AuthContext(), $panels);
        $declaration = new Query(cache: '1m');
        $key = static fn (bool $subtree): string => $panels->within('reseller', static fn (): string => $panels->grantedWithin(
            new PanelBinding('reseller', subtree: $subtree),
            static fn (): string => $cache->key('stats', $declaration, [], 'reseller'),
        ));

        expect($key(true))->not->toBe($key(false))
            ->and($key(false))->toBe($panels->within('reseller', static fn (): string => $cache->key('stats', $declaration, [], 'reseller')));
    });
});

// "The admin panel is mapped only to platform-owned hosts." Held on the
// request, before the tenant and before the panel's guard -- whose answer
// to a visitor would be a sign-in page on the reseller's domain.
describe('a platform-only panel', function () {
    it('answers on the platform\'s own hosts', function () {
        $app = new App();

        expect($app->get('/admin/home', 'admin.platform.test', 'staff')->status)->toBe(200)
            ->and($app->get('/admin/home', 'reseller.platform.test', 'staff')->status)->toBe(200);
    });

    it('is not there on a tenant\'s custom domain, or a host the application does not know', function () {
        $app = new App();

        $custom = $app->get('/admin/home', 'reseller.example', 'staff');
        $unknown = $app->get('/admin/home', 'nowhere.test', 'staff');

        expect($custom->status)->toBe(404)
            ->and(errorCode($custom))->toBe('not_found')
            ->and($unknown->status)->toBe(404)
            ->and(Trace::$events)->toBe([]);
    });

    it('leaves a panel the platform does not keep to itself on a custom domain', function () {
        expect(new App()->get('/c/scripts', 'reseller.example', 'partner')->status)->toBe(200);
    });

    // A typo in the list would leave the real panel on every host.
    it('is refused when the Kernel is built with realms naming a panel nobody defined', function () {
        $realms = new Realms(Realm::platform('https://id.platform.test'), platformPanels: ['admn']);

        expect(fn () => new App(realms: $realms))->toThrow(\InvalidArgumentException::class, "panel 'admn'");
    });
});

describe('the tenant switcher', function () {
    /**
     * A switcher for $app, as $user on $host, with the session `old`.
     *
     * @return array{TenantSwitcher, Request, Sessions}
     */
    function switcher(App $app, string $host, ?string $user = 'partner', ?Realms $realms = null, bool $withRealms = true): array
    {
        $app->auth->login($user === null ? null : new Member($user), '1');
        $sessions = new Sessions();
        $switcher = new TenantSwitcher(
            $app->orm,
            $app->auth,
            $app->directory,
            $sessions,
            static fn (Request $r): string => 'old',
            $withRealms ? ($realms ?? App::realms()) : null,
        );

        return [$switcher, new Request('POST', '/switch', ['host' => $host]), $sessions];
    }

    // On the reseller's domain, the reseller's subtree: other is not in it,
    // and globex, in it, holds no membership of the user's.
    it('offers the tenants of the user\'s at or below the realm\'s, in the application\'s order', function () {
        $app = new App();

        [$onDomain, $domain] = switcher($app, 'reseller.example');
        [$onPlatform, $platform] = switcher($app, 'id.platform.test');

        expect($onDomain->choices($domain, 'customer'))->toBe(['acme', 'reseller', 'acme-west'])
            ->and($onPlatform->choices($platform, 'customer'))->toBe(['other', 'acme', 'reseller', 'acme-west']);
    });

    it('moves the session into a new one, running as the tenant chosen', function () {
        $app = new App();
        [$switcher, $request, $sessions] = switcher($app, 'reseller.example');

        expect($switcher->switch($request, 'acme-west', 'customer'))->toBe('session-1')
            ->and($sessions->switched)->toBe([['partner', 'acme-west']])
            // Asked as the tenant switched to, as the panel's own check is.
            ->and($app->directory->asked)->toBe([['acme-west', 'acme-west']]);
    });

    it('refuses a tenant outside the realm\'s subtree, one where the user is no member, and another panel\'s', function () {
        $app = new App();
        [$switcher, $request, $sessions] = switcher($app, 'reseller.example');

        $refusal = static function (\Closure $switch): string {
            try {
                $switch();
            } catch (HttpException $e) {
                return "{$e->status} {$e->code_}";
            }

            return 'switched';
        };

        expect($refusal(fn () => $switcher->switch($request, 'other', 'customer')))->toBe('403 tenant_switch_refused')
            ->and($refusal(fn () => $switcher->switch($request, 'globex', 'customer')))->toBe('403 tenant_switch_refused')
            ->and($refusal(fn () => $switcher->switch($request, '', 'customer')))->toBe('403 tenant_switch_refused')
            ->and($refusal(fn () => $switcher->switch($request, 'reseller', 'reseller')))->toBe('403 tenant_switch_refused')
            ->and($sessions->switched)->toBe([]);
    });

    it('is bounded by a tenant\'s subdomain as by its custom domain, and by nothing on a platform host naming none', function () {
        $app = new App();
        [$onAcme, $acme] = switcher($app, 'acme.platform.test');
        [$onPlatform, $platform] = switcher($app, 'id.platform.test');

        expect(fn () => $onAcme->switch($acme, 'reseller', 'customer'))->toThrow(HttpException::class)
            ->and($onPlatform->switch($platform, 'other', 'customer'))->toBe('session-1');
    });

    it('asks who is signed in, and where', function () {
        [$anonymous, $request] = switcher(new App(), 'reseller.example', null);
        [$lost, $nowhere] = switcher(new App(), 'nowhere.test');

        expect(fn () => $anonymous->switch($request, 'acme', 'customer'))->toThrow(HttpException::class, 'signed in')
            ->and(fn () => $anonymous->choices($request, 'customer'))->toThrow(HttpException::class, 'signed in')
            ->and(fn () => $lost->switch($nowhere, 'acme', 'customer'))->toThrow(HttpException::class, 'no realm');
    });

    // The CSRF token and a file URL name no tenant: kept, a stale tab's
    // form would post into the tenant switched to.
    it('fails the switch when the application keeps the session id instead of starting a new one', function () {
        $app = new App();
        [$switcher, $request, $sessions] = switcher($app, 'reseller.example');

        $sessions->answer = 'old';
        expect(fn () => $switcher->switch($request, 'acme', 'customer'))->toThrow(\LogicException::class, 'new session');

        $sessions->answer = '';
        expect(fn () => $switcher->switch($request, 'acme', 'customer'))->toThrow(\LogicException::class, 'new session');
    });

    it('is bounded by nothing but memberships for an application with one host', function () {
        $app = new App();
        [$switcher, $request] = switcher($app, 'anything.test', withRealms: false);

        expect($switcher->choices($request, 'customer'))->toBe(['other', 'acme', 'reseller', 'acme-west']);
    });
});
