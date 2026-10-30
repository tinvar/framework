<?php

declare(strict_types=1);

/**
 * Identity across domains: a session moves from the platform host to
 * another realm only by a one-time code the target asked for, and arrives
 * as a new session there.
 *
 * Driven through the Kernel by a browser that keeps a cookie jar per host
 * and follows redirects between them, as a real one does -- no cookie is
 * ever shared between two hosts.
 */

namespace Tinvar\Tests\Identity\Handoff;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\HandoffCode;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Pkce;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Render\RouteToken;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenClaims;
use Tinvar\Support\Signer;
use Tinvar\Tests\Identity\Fixtures\App;
use Tinvar\Tests\Identity\Fixtures\Browser;
use Tinvar\Tests\Identity\Fixtures\ResellerOverAcme;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\DatabaseNonceStore;

require_once __DIR__ . '/Fixtures/app.php';

const KEY_OTHER = 'another-identity-test-key-of-thirty-two-bytes';

/** @return array<string, mixed> */
function json(Response $response): array
{
    $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    return \is_array($decoded) ? $decoded : [];
}

function errorCode(Response $response): string
{
    return (string) (json($response)['error']['code'] ?? '');
}

/**
 * The callback URL the platform host sends $browser to, for a handoff to
 * $origin it starts there -- confirming the consent screen a custom domain
 * is asked about with, and without following the callback.
 */
function codeFor(Browser $browser, string $origin, string $return = '/whoami'): string
{
    $start = $browser->get($origin . HandoffEndpoint::START . '?return=' . rawurlencode($return));
    expect($start->status)->toBe(303);

    $authorize = $browser->get((string) $start->header('location'));
    if (Browser::isConsent($authorize)) {
        $authorize = $browser->answer($authorize);
    }
    expect($authorize->status)->toBe(303, $authorize->body());

    $callback = (string) $authorize->header('location');
    expect($callback)->toStartWith($origin . HandoffEndpoint::CALLBACK . '?code=');

    return $callback;
}

describe('a handoff', function () {
    it('moves a user signed in on the platform host to a panel\'s own host, into a new session there', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'sam');
        $arrivedWith = $browser->sessionAt('admin.platform.test');

        $landed = $browser->follow('https://admin.platform.test/_handoff/start?return=%2Fwhoami');

        expect($landed->status)->toBe(200)
            ->and(json($landed))->toBe(['user' => 'sam'])
            ->and(array_map(static fn (string $url): string => (string) parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH), $browser->trail))->toBe([
                'admin.platform.test/_handoff/start',
                'id.platform.test/_handoff/authorize',
                'admin.platform.test/_handoff/callback',
                'admin.platform.test/whoami',
            ])
            // A new session, made for this host alone; the platform host's
            // is untouched, and no cookie crossed between them.
            ->and($browser->jar['admin.platform.test'])->not->toBe($arrivedWith)
            ->and($app->sessions->sessions[$browser->jar['admin.platform.test']])->toBe(['user' => 'sam', 'realm' => 'admin.platform.test', 'tenant' => ''])
            ->and($app->sessions->sessions[$browser->jar['id.platform.test']]['realm'])->toBe('id.platform.test');
    });

    it('carries a user to a tenant\'s custom domain, held to a membership there asked as that tenant', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');

        $landed = $browser->follow('https://acme.example/_handoff/start?return=%2Fwhoami');

        expect(json($landed))->toBe(['user' => 'alice'])
            // Asked before the screen, again at its confirm, and again at
            // the callback.
            ->and($app->directory->askedAs)->toBe(['acme', 'acme', 'acme']);
    });

    it('sends someone not signed in on the platform host to sign in there, and back to the handoff after', function () {
        $app = new App();
        $browser = new Browser($app);

        $start = $browser->get('https://acme.example/_handoff/start?return=%2Fwhoami');
        $authorizeUrl = (string) $start->header('location');
        $authorize = $browser->get($authorizeUrl);
        $next = (string) parse_url($authorizeUrl, PHP_URL_PATH) . '?' . parse_url($authorizeUrl, PHP_URL_QUERY);

        // On the platform host: credentials are never typed on the domain.
        expect($authorizeUrl)->toStartWith('https://id.platform.test/_handoff/authorize?')
            ->and($authorize->status)->toBe(302)
            ->and($authorize->header('location'))->toBe('/sign-in?next=' . rawurlencode($next));

        // Signed in there, the same handoff goes through.
        $browser->signedInAt('id.platform.test', 'alice');
        $landed = $browser->follow('https://id.platform.test' . $next);

        expect(json($landed))->toBe(['user' => 'alice']);
    });

    it('sends a visitor already signed in on the target straight on, with no code', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('acme.example', 'alice');

        $start = $browser->get('https://acme.example/_handoff/start?return=%2Fwhoami');

        expect($start->status)->toBe(303)
            ->and($start->header('location'))->toBe('/whoami');
    });

    it('keeps every step out of caches and Referers', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $start = $browser->get('https://acme.example/_handoff/start?return=%2Fwhoami');
        $screen = $browser->get((string) $start->header('location'));
        $confirm = $browser->answer($screen);
        $callback = $browser->get((string) $confirm->header('location'));

        foreach ([$start, $screen, $confirm, $callback] as $response) {
            expect($response->header('cache-control'))->toContain('no-store')
                ->and($response->header('referrer-policy'))->toBe('no-referrer');
        }
    });

    it('goes on only to a path on the target, never off it or round again', function () {
        $app = new App();
        $browser = new Browser($app);

        foreach (['https://evil.example/', '//evil.example', '/\\evil.example', '/_handoff/start?return=/x', ''] as $return) {
            $location = (string) $browser->get('https://acme.example/_handoff/start?return=' . rawurlencode($return))->header('location');
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            expect($query['return'] ?? null)->toBe('/');
        }

        // Edited between start and authorize: refused outright.
        $location = (string) $browser->get('https://acme.example/_handoff/start?return=%2Fwhoami')->header('location');
        $edited = str_replace('return=%2Fwhoami', 'return=%2F%2Fevil.example', $location);
        $response = new Browser($app)->signedInAt('id.platform.test', 'alice')->get($edited);

        expect($response->status)->toBe(400)
            ->and(errorCode($response))->toBe('handoff_malformed');
    });
});

describe('the code', function () {
    // A double click, a prefetch, a redirect followed twice: the same
    // browser, which is not a copy, gets the first answer again -- no new
    // session, and nothing ended.
    it('answers the same browser again as it did, from the session that redeemed it or with the cookie it arrived with', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');
        $arrivedWith = $browser->sessionAt('acme.example');

        expect($browser->get($callback)->status)->toBe(303);
        $arrived = $browser->sessionAt('acme.example');
        $sessions = $app->sessions->sessions;

        $again = $browser->get($callback);
        $sameCookie = $app->get($callback, ['__Host-sid' => $arrivedWith]);

        expect($again->status)->toBe(303)
            ->and($again->header('location'))->toBe('/whoami')
            ->and($sameCookie->status)->toBe(303)
            ->and($app->sessions->sessions)->toBe($sessions)
            ->and($browser->sessionAt('acme.example'))->toBe($arrived)
            ->and(json($browser->get('https://acme.example/whoami')))->toBe(['user' => 'alice']);
    });

    // Both requests of a double click carry the cookie the handoff started
    // with, and the second can land while the first is making its session.
    it('answers a double submit from the same browser without ending the session the first makes', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');
        $startedWith = $browser->sessionAt('acme.example');
        $second = null;
        $app->arrivals->beforeArrive = function () use ($app, $callback, $startedWith, &$second): void {
            $second = $app->get($callback, ['__Host-sid' => $startedWith]);
        };

        $first = $browser->get($callback);

        expect($second?->status)->toBe(303)
            ->and($first->status)->toBe(303)
            ->and($app->sessions->sessions)->toHaveKey($browser->sessionAt('acme.example'))
            ->and(json($browser->get('https://acme.example/whoami')))->toBe(['user' => 'alice']);
    });

    // RFC 6749, 4.1.2: a code used twice was copied, and the session its
    // first redemption made may be the copier's.
    it('ends the session its first redemption started when it is replayed, and no other', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');
        expect($browser->get($callback)->status)->toBe(303);
        $arrived = $browser->sessionAt('acme.example');

        // Another arrival of alice's, by a code of its own: untouched.
        $other = new Browser($app)->signedInAt('id.platform.test', 'alice');
        expect($other->get(codeFor($other, 'https://acme.example'))->status)->toBe(303);
        $kept = $other->sessionAt('acme.example');

        expect($app->sessions->sessions)->toHaveKey($arrived)
            ->and($app->arrivals->redemptions)->toHaveCount(2);

        // Replayed from a browser that never started the handoff.
        $replayed = $app->get($callback);

        expect($replayed->status)->toBe(403)
            ->and(errorCode($replayed))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('the session it started is ended')
            ->and($app->sessions->sessions)->not->toHaveKey($arrived)
            ->and($app->sessions->sessions)->toHaveKey($kept)
            ->and(json($browser->get('https://acme.example/whoami')))->not->toBe(['user' => 'alice']);
    });

    // A replay landing after the first redemption claimed the code and
    // before it made its session found nothing to end, and the session
    // made after it survived.
    it('ends the session its first redemption starts even when the replay lands before it is made', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');
        $replayed = null;
        $app->arrivals->beforeArrive = function () use ($app, $callback, &$replayed): void {
            $replayed = $app->get($callback);
        };

        $first = $browser->get($callback);

        expect($replayed?->status)->toBe(403)
            ->and($first->status)->toBe(403)
            ->and(errorCode($first))->toBe('handoff_refused')
            ->and($app->arrivals->redemptions)->toHaveCount(1)
            ->and($app->sessions->sessions)->not->toHaveKey((string) array_values($app->arrivals->redemptions)[0])
            ->and(json($browser->get('https://acme.example/whoami')))->not->toBe(['user' => 'alice']);
    });

    // A copy shown while the code's first redemption is still being checked
    // marks the code compromised for good: that redemption failing and
    // giving its claim back does not clear it, and a retry that then gets a
    // session has it ended at once.
    it('leaves a code a copy was shown of with no session, even when its redemption is retried', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');
        $copy = null;
        $app->directory->onFind = function () use ($app, $callback, &$copy): void {
            $copy = $app->get($callback);
            // And the first redemption is refused, giving its claim back.
            $app->directory->epochs['alice']++;
        };

        $first = $browser->get($callback);
        $app->directory->epochs['alice']--;
        $retry = $browser->get($callback);

        expect($copy?->status)->toBe(403)
            ->and($first->status)->toBe(403)
            ->and($retry->status)->toBe(403)
            ->and($app->lastLogged())->toContain('replayed while it was being redeemed')
            ->and($app->arrivals->redemptions)->toHaveCount(1)
            ->and($app->sessions->sessions)->not->toHaveKey((string) array_values($app->arrivals->redemptions)[0])
            ->and(json($browser->get('https://acme.example/whoami')))->not->toBe(['user' => 'alice']);
    });

    it('is refused at any origin but the one it was minted for: another domain of the tenant, another tenant\'s, the platform\'s', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');
        $session = $browser->sessionAt('acme.example');

        foreach (['shop.acme.example', 'globex.example', 'acme.platform.test', 'id.platform.test', 'admin.platform.test'] as $host) {
            $elsewhere = str_replace('https://acme.example/', "https://{$host}/", $callback);
            $response = $app->get($elsewhere, ['__Host-sid' => $session]);
            expect($response->status)->toBe(403, $host)
                ->and(errorCode($response))->toBe('handoff_refused')
                ->and($app->lastLogged())->toContain('minted for https://acme.example');
        }

        // Still good where it belongs: none of those spent it.
        expect($browser->get($callback)->status)->toBe(303);
    });

    it('is refused once it has expired', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');

        $app->now += HandoffCode::TTL;
        $response = $browser->get($callback);

        expect($response->status)->toBe(403)
            ->and(errorCode($response))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('expired');
    });

    it('is refused once its user\'s auth epoch has moved -- a sign-out or a role change while it was in flight', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');

        $app->directory->epochs['alice']++;
        $response = $browser->get($callback);

        expect($response->status)->toBe(403)
            ->and(errorCode($response))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('auth epoch has moved')
            ->and($app->sessions->sessions[$browser->jar['acme.example']] ?? null)->toBeNull();
    });

    it('is refused when its user\'s membership was taken away while it was in flight', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');

        $app->directory->members['alice'] = [];

        expect($browser->get($callback)->status)->toBe(403)
            ->and($app->lastLogged())->toContain('no membership');
    });

    it('is redeemed only by the browser session that started the handoff', function () {
        $app = new App();
        $victim = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($victim, 'https://acme.example');

        // Lifted from the victim -- a log, a Referer, a reseller's server --
        // and presented with a session of the attacker's own.
        $attacker = new Browser($app);
        $response = $attacker->get($callback);

        expect($response->status)->toBe(403)
            ->and(errorCode($response))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('not the one that started the handoff')
            // And the victim, who did start it, still can.
            ->and(json($victim->follow($callback)))->toBe(['user' => 'alice']);
    });

    // Login CSRF: an attacker's own code, planted in a victim's browser,
    // would sign the victim in as the attacker -- and what the victim then
    // types would be the attacker's to read.
    it('never signs a browser in as someone else by a code planted in it', function () {
        $app = new App();
        $attacker = new Browser($app)->signedInAt('id.platform.test', 'carol');
        $planted = codeFor($attacker, 'https://acme.example');

        $victim = new Browser($app);
        $response = $victim->get($planted);

        expect($response->status)->toBe(403)
            ->and(json($victim->follow('https://acme.example/whoami')))->toBe(['user' => '']);
    });

    it('never promotes the session the browser arrived with, even one an attacker planted', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $browser->jar['acme.example'] = 'planted-by-an-attacker';

        $landed = $browser->follow('https://acme.example/_handoff/start?return=%2Fwhoami');

        expect(json($landed))->toBe(['user' => 'alice'])
            ->and($browser->jar['acme.example'])->not->toBe('planted-by-an-attacker')
            ->and($app->sessions->sessions)->not->toHaveKey('planted-by-an-attacker')
            ->and(json($app->get('https://acme.example/whoami', ['__Host-sid' => 'planted-by-an-attacker'])))->toBe(['user' => '']);
    });

    it('fails the handoff when the application keeps the session id instead of starting a new one', function () {
        $app = new App();
        $app->arrivals->keepId = true;
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');

        $response = $browser->follow('https://acme.example/_handoff/start?return=%2Fwhoami');

        expect($response->status)->toBe(500)
            ->and($app->lastLogged())->toContain('must start a new session');
    });

    it('is refused when it is not a handoff code: forged, edited, or a token of another kind', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $callback = codeFor($browser, 'https://acme.example');
        $code = (string) substr($callback, (int) strpos($callback, 'code=') + 5);
        [$v, $payload, $mac] = explode('.', $code);

        $foreign = new Signer(App::class, HandoffCode::PURPOSE);
        $routeToken = SignedToken::encode($app->signer, RouteToken::PURPOSE, ['o' => 'https://acme.example']);
        $tampered = $v . '.' . Signer::encode(str_replace('"alice"', '"carol"', (string) Signer::decode($payload))) . '.' . $mac;

        foreach ([$tampered, $routeToken, str_replace($mac, Signer::encode($foreign->sign('x')), $code), 'garbage', str_repeat('a', 5000)] as $bad) {
            expect($browser->get('https://acme.example/_handoff/callback?code=' . rawurlencode($bad))->status)->toBe(403);
        }
    });
});

describe('across tenants', function () {
    it('will not hand a user to a domain of a tenant they hold no membership in', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');

        $start = $browser->get('https://globex.example/_handoff/start?return=%2Fwhoami');
        $response = $browser->get((string) $start->header('location'));

        expect($response->status)->toBe(403)
            ->and(errorCode($response))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('no membership')
            ->and($app->directory->askedAs)->toBe(['globex']);
    });

    // A code that names this origin but another tenant -- a minting bug, a
    // record moved between tenants while the code was in flight -- is held
    // to the realm's tenant, not its own word.
    it('refuses a code naming this origin with another tenant', function () {
        $app = new App();
        $browser = new Browser($app);
        $session = $browser->sessionAt('globex.example');
        $state = Signer::encode(random_bytes(16));
        $code = HandoffCode::mint(
            $app->signer,
            Realm::domain('https://globex.example', 'acme', 'portal'),
            'bob',
            '1',
            Pkce::challenge(Pkce::verifier($app->signer, $session, $state)),
            $state,
            '/whoami',
            $app->now,
            'acme',
        );

        $response = $browser->get('https://globex.example/_handoff/callback?code=' . $code->encode($app->signer));

        expect($response->status)->toBe(403)
            ->and(errorCode($response))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('another tenant');
    });

    // ADR 0056: with tenancy registered a code names the realm's tenant, as
    // it always did; with none, the claim no tenant can be (TenantClaim). A
    // code minted in one mode never verifies in the other.
    it('is bound to the tenant claim of the mode it was minted in', function (bool $tenancy, string $claim, bool $accepted) {
        $app = new App(tenancy: $tenancy);
        $browser = new Browser($app);
        $session = $browser->sessionAt('globex.example');
        $state = Signer::encode(random_bytes(16));
        $code = HandoffCode::mint(
            $app->signer,
            Realm::domain('https://globex.example', 'globex', 'portal'),
            'bob',
            '1',
            Pkce::challenge(Pkce::verifier($app->signer, $session, $state)),
            $state,
            '/whoami',
            $app->now,
            $claim,
        );

        $response = $browser->get('https://globex.example/_handoff/callback?code=' . $code->encode($app->signer));

        expect($response->status)->toBe($accepted ? 303 : 403, $response->body());
        if (!$accepted) {
            expect($app->lastLogged())->toContain('another tenant');
        }
    })->with([
        'tenancy, the realm\'s tenant' => [true, 'globex', true],
        'tenancy, the no-tenancy claim' => [true, TenantClaim::NONE, false],
        'tenancy, no tenant' => [true, '', false],
        'no tenancy, the no-tenancy claim' => [false, TenantClaim::NONE, true],
        'no tenancy, the realm\'s tenant' => [false, 'globex', false],
        'no tenancy, no tenant' => [false, '', false],
    ]);

    it('keeps the session it makes to the host it was made on', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $browser->follow('https://acme.example/_handoff/start?return=%2Fwhoami');
        $made = $browser->jar['acme.example'];

        // Presented anywhere else -- the platform host above all -- it is a
        // session that signs nobody in.
        expect(json($app->get('https://id.platform.test/whoami', ['__Host-sid' => $made])))->toBe(['user' => ''])
            ->and(json($app->get('https://shop.acme.example/whoami', ['__Host-sid' => $made])))->toBe(['user' => '']);
    });
});

// "A session created on it can only hold memberships within that domain's
// tenant subtree": with a hierarchy, a domain's realm is its tenant's
// subtree, and a member of a tenant below it is handed over -- into a
// session running as their own tenant, never one outside the subtree.
describe('a tenant\'s subtree', function () {
    it('hands a member of a tenant below the domain\'s to it, into a session running as their own tenant', function () {
        $app = new App(new ResellerOverAcme());
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');

        $landed = $browser->follow('https://reseller.example/_handoff/start?return=%2Fwhoami');

        expect(json($landed))->toBe(['user' => 'alice'])
            ->and($app->sessions->sessions[$browser->jar['reseller.example']])
            ->toBe(['user' => 'alice', 'realm' => 'reseller.example', 'tenant' => 'acme'])
            // Asked as the domain's tenant, then confirmed as acme -- before
            // the consent screen, at its confirm, and again at the callback.
            ->and($app->directory->askedAs)->toBe(['reseller', 'acme', 'reseller', 'acme', 'reseller', 'acme']);
    });

    it('starts the session in the domain\'s own tenant when the user is a member there', function () {
        $app = new App(new ResellerOverAcme());
        $app->directory->members['carol']['portal'] = ['acme', 'reseller'];
        $browser = new Browser($app)->signedInAt('id.platform.test', 'carol');

        $browser->follow('https://reseller.example/_handoff/start?return=%2Fwhoami');

        expect($app->sessions->sessions[$browser->jar['reseller.example']]['tenant'] ?? null)->toBe('reseller');
    });

    it('refuses a member of a tenant outside the subtree, and one listed where they are not a member', function () {
        $app = new App(new ResellerOverAcme());
        $bob = new Browser($app)->signedInAt('id.platform.test', 'bob');

        $refused = $bob->follow('https://reseller.example/_handoff/start?return=%2Fwhoami');

        // A listing naming acme for someone Memberships says is no member
        // there is not trusted: each tenant listed is asked again.
        $lying = new App(new ResellerOverAcme());
        $lying->directory->listed['bob']['portal'] = ['globex', 'acme'];
        $listed = new Browser($lying)->signedInAt('id.platform.test', 'bob')
            ->follow('https://reseller.example/_handoff/start?return=%2Fwhoami');

        expect($refused->status)->toBe(403)
            ->and(errorCode($refused))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('in its tenant or below it')
            ->and($listed->status)->toBe(403)
            ->and($lying->directory->askedAs)->toBe(['reseller', 'acme']);
    });

    it('keeps a domain\'s realm to its tenant alone with no hierarchy', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');

        $response = $browser->follow('https://reseller.example/_handoff/start?return=%2Fwhoami');

        expect($response->status)->toBe(403)
            ->and(errorCode($response))->toBe('handoff_refused');
    });

    // The code is still bound to the realm's own tenant exactly: a code for
    // the reseller's domain naming acme -- the tenant the session would run
    // as -- is not one the domain takes.
    it('binds the code to the domain\'s own tenant, not the one the session lands in', function () {
        $app = new App(new ResellerOverAcme());
        $browser = new Browser($app);
        $session = $browser->sessionAt('reseller.example');
        $state = Signer::encode(random_bytes(16));
        $code = HandoffCode::mint(
            $app->signer,
            Realm::domain('https://reseller.example', 'acme', 'portal'),
            'alice',
            '1',
            Pkce::challenge(Pkce::verifier($app->signer, $session, $state)),
            $state,
            '/whoami',
            $app->now,
            'acme',
        );

        $response = $browser->get('https://reseller.example/_handoff/callback?code=' . $code->encode($app->signer));

        expect($response->status)->toBe(403)
            ->and($app->lastLogged())->toContain('another tenant');
    });
});

describe('staff', function () {
    it('are never handed to a custom domain, member or not', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'sam');

        $start = $browser->get('https://acme.example/_handoff/start?return=%2Fwhoami');
        $response = $browser->get((string) $start->header('location'));

        expect($response->status)->toBe(403)
            ->and(errorCode($response))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('staff accounts')
            // A tenant's host the platform owns is another matter.
            ->and(json(new Browser($app)->signedInAt('id.platform.test', 'sam')->follow('https://acme.platform.test/_handoff/start?return=%2Fwhoami')))->toBe(['user' => 'sam']);
    });

    it('are refused at the custom domain too, if made staff while the code was in flight', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'carol');
        $callback = codeFor($browser, 'https://acme.example');
        $app->directory->staff[] = 'carol';

        expect($browser->get($callback)->status)->toBe(403)
            ->and($app->lastLogged())->toContain('staff accounts');
    });
});

describe('hosts', function () {
    it('answers only on hosts that are realms, and each step only where it belongs', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');

        expect($browser->get('https://nobody.example/_handoff/start')->status)->toBe(404)
            // A custom domain record carrying the admin panel is no realm at all.
            ->and($browser->get('https://evil.example/_handoff/start')->status)->toBe(404)
            // The platform host signs in itself.
            ->and($browser->get('https://id.platform.test/_handoff/start')->status)->toBe(404)
            // Only the platform host authorizes.
            ->and(errorCode($browser->get('https://acme.example/_handoff/authorize?origin=https%3A%2F%2Facme.example')))->toBe('handoff_unknown')
            ->and($browser->get('https://id.platform.test/_handoff/authorize?origin=https%3A%2F%2Fnobody.example&state=' . str_repeat('a', 22) . '&challenge=' . str_repeat('b', 43) . '&return=%2F')->status)->toBe(404)
            // The origin exactly: another scheme or port is not the realm.
            ->and($browser->get('https://id.platform.test/_handoff/authorize?origin=https%3A%2F%2Facme.example%3A8443&state=' . str_repeat('a', 22) . '&challenge=' . str_repeat('b', 43) . '&return=%2F')->status)->toBe(404);
    });

    // A guard on the target sends a client navigation to start a handoff;
    // the router reads the next host as a document load, not a fetch.
    it('answers a client router\'s navigation with a document load of the platform host', function () {
        $app = new App();

        $response = $app->kernel->handle(
            new Request('GET', HandoffEndpoint::START, ['host' => 'acme.example', 'rsc' => '1'], ['return' => '/whoami'], [], ['__Host-sid' => 'session-1']),
            static fn (): Response => Response::make(500),
        );

        expect($response->status)->toBe(409)
            ->and((string) $response->header('x-location'))->toStartWith('https://id.platform.test/_handoff/authorize?');
    });

    it('is followed with GET alone', function () {
        $app = new App();

        $response = $app->kernel->handle(
            new Request('POST', HandoffEndpoint::CALLBACK, ['host' => 'acme.example', 'accept' => 'application/json']),
            static fn (): Response => Response::make(500),
        );

        expect($response->status)->toBe(405);
    });
});

describe('realms', function () {
    it('are https origins in the one spelling a browser serializes, or http on localhost', function () {
        foreach (['https://shop.test', 'https://shop.test:8443', 'http://localhost:8080', 'http://admin.localhost'] as $origin) {
            expect(Realm::hostOf($origin))->not->toBeNull($origin);
        }
        foreach ([
            'http://shop.test', 'https://shop.test:443', 'http://localhost:80', 'https://Shop.test', 'https://shop.test.',
            'https://shop.test/', 'https://shop.test/path', 'https://127.0.0.1', 'https://[::1]', 'https://user@shop.test',
            'shop.test', 'https://shop.test:0', 'https://shop.test:65536', 'https://shop.test:08443',
        ] as $origin) {
            expect(Realm::hostOf($origin))->toBeNull($origin);
        }
    });

    it('make a custom domain name its tenant and its panel', function () {
        expect(static fn () => Realm::domain('https://acme.example', '', 'portal'))->toThrow(\InvalidArgumentException::class)
            ->and(static fn () => Realm::domain('https://acme.example', 'acme', ''))->toThrow(\InvalidArgumentException::class);
    });

    it('list the platform\'s own hosts once each, and look the rest up', function () {
        $authority = Realm::platform('https://id.platform.test');

        expect(static fn () => new Realms(Realm::domain('https://acme.example', 'acme', 'portal')))->toThrow(\InvalidArgumentException::class)
            ->and(static fn () => new Realms($authority, [Realm::domain('https://acme.example', 'acme', 'portal')]))->toThrow(\InvalidArgumentException::class)
            ->and(static fn () => new Realms($authority, [Realm::platform('https://id.platform.test:8443')]))->toThrow(\InvalidArgumentException::class);

        // A lookup can never stand in for a listed host, nor answer for
        // another host than the one asked about.
        $realms = new Realms($authority, [], static fn (string $host): Realm => Realm::domain('https://acme.example', 'acme', 'portal'));

        expect($realms->forHost('id.platform.test'))->toBe($authority)
            ->and($realms->forHost('acme.example')?->tenant)->toBe('acme')
            ->and($realms->forHost('globex.example'))->toBeNull();
    });

    it('make a handoff that may reach a custom domain say who is staff, and hold users to memberships', function () {
        $app = new App();
        $build = static fn (bool $staff, bool $memberships) => new HandoffEndpoint(
            realms: App::realms(),
            signer: $app->signer,
            nonces: new ArrayNonceStore(),
            sessionId: static fn (Request $r): string => '',
            auth: $app->auth,
            orm: $app->orm,
            users: $app->directory,
            sessions: $app->arrivals,
            signIn: static fn (string $back): Response => Response::make(401),
            memberships: $memberships ? $app->directory : null,
            staff: $staff ? static fn (): bool => false : null,
        );

        expect(static fn () => $build(false, true))->toThrow(\InvalidArgumentException::class)
            ->and(static fn () => $build(true, false))->toThrow(\InvalidArgumentException::class)
            ->and($build(true, true))->toBeInstanceOf(HandoffEndpoint::class);
    });

    // A claim left to SQLite's next checkpoint is lost to an OS crash before
    // it, and the code or ticket it spent works again until it expires.
    it('claim codes and tickets in a database only durably', function () {
        $app = new App();
        $connection = \Tinvar\Orm\PdoConnection::sqlite(':memory:');
        $build = static fn (bool $durable) => new HandoffEndpoint(
            realms: App::realms(),
            signer: $app->signer,
            nonces: new DatabaseNonceStore($connection, 'handoff_nonces', durable: $durable),
            sessionId: static fn (Request $r): string => '',
            auth: $app->auth,
            orm: $app->orm,
            users: $app->directory,
            sessions: $app->arrivals,
            signIn: static fn (string $back): Response => Response::make(401),
            memberships: $app->directory,
            staff: static fn (): bool => false,
        );

        expect(static fn () => $build(false))->toThrow(\InvalidArgumentException::class, 'durable')
            ->and($build(true))->toBeInstanceOf(HandoffEndpoint::class);
    });

    it('refuse a Kernel built with a handoff reading another AuthContext', function () {
        $app = new App();
        $endpoint = new HandoffEndpoint(
            realms: new Realms(Realm::platform('https://id.platform.test')),
            signer: $app->signer,
            nonces: new ArrayNonceStore(),
            sessionId: static fn (Request $r): string => '',
            auth: new AuthContext(),
            orm: $app->orm,
            users: $app->directory,
            sessions: $app->arrivals,
            signIn: static fn (string $back): Response => Response::make(401),
        );

        expect(static fn () => new Kernel(
            orm: $app->orm,
            routes: new \Tinvar\Routing\RouteRegistry(),
            queries: new \Tinvar\Query\QueryRegistry(),
            actions: new \Tinvar\Actions\ActionRegistry(),
            auth: $app->auth,
            policies: new \Tinvar\Auth\PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            handoff: $endpoint,
        ))->toThrow(\InvalidArgumentException::class, 'AuthContext');
    });
});

describe('a code\'s claims', function () {
    it('carry the epoch only as a keyed hash, and verify only under their own purpose', function () {
        $app = new App();
        $code = HandoffCode::mint($app->signer, Realm::domain('https://acme.example', 'acme', 'portal'), 'alice', '7', str_repeat('c', 43), str_repeat('s', 22), '/', $app->now, 'acme');
        $encoded = $code->encode($app->signer);

        expect(HandoffCode::decode($encoded, $app->signer, $app->now))->toEqual($code)
            ->and($code->authHash)->toBe(TokenClaims::hashAuth($app->signer, 'alice', '7'))
            ->and((string) Signer::decode(explode('.', $encoded)[1]))->not->toContain('"7"')
            ->and(static fn () => HandoffCode::decode($encoded, new Signer(KEY_OTHER, HandoffCode::PURPOSE), $app->now))->toThrow(\Tinvar\Render\TokenError::class);
    });

    it('are refused living longer than a code is minted for, or issued in the future', function () {
        $app = new App();
        $realm = Realm::domain('https://acme.example', 'acme', 'portal');
        $long = new HandoffCode($realm->origin, 'acme', 'portal', 'alice', 'h', str_repeat('c', 43), str_repeat('s', 22), '/', str_repeat('j', 22), $app->now, $app->now + HandoffCode::TTL + 1);
        $ahead = new HandoffCode($realm->origin, 'acme', 'portal', 'alice', 'h', str_repeat('c', 43), str_repeat('s', 22), '/', str_repeat('j', 22), $app->now + 120, $app->now + 150);

        expect(static fn () => HandoffCode::decode($long->encode($app->signer), $app->signer, $app->now))->toThrow(\Tinvar\Render\TokenError::class)
            ->and(static fn () => HandoffCode::decode($ahead->encode($app->signer), $app->signer, $app->now))->toThrow(\Tinvar\Render\TokenError::class);
    });
});
