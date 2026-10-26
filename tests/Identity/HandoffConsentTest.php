<?php

declare(strict_types=1);

/**
 * The consent a handoff to a custom domain waits on: a screen on the
 * platform host naming the domain and the account, whose confirm and
 * cancel are forms posting a ticket bound to the pending handoff -- and no
 * code until the confirm is posted.
 *
 * Driven through the Kernel by the same browser as the handoff's own tests:
 * a cookie jar per host, redirects followed across them.
 */

namespace Tinvar\Tests\Identity\Consent;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\ConsentTicket;
use Tinvar\Identity\HandoffCode;
use Tinvar\Identity\HandoffConsent;
use Tinvar\Identity\HandoffConsentShell;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenError;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Identity\Fixtures\App;
use Tinvar\Tests\Identity\Fixtures\Browser;

require_once __DIR__ . '/Fixtures/app.php';

const START = 'https://acme.example/_handoff/start?return=%2Fwhoami';

/** @return array<string, mixed> */
function json(Response $response): array
{
    $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    return \is_array($decoded) ? $decoded : [];
}

function errorCode(Response $response): string
{
    $decoded = json_decode($response->body(), true);
    if (\is_array($decoded)) {
        return (string) ($decoded['error']['code'] ?? '');
    }

    // A form's post is a document request: the code is in the bare page.
    return preg_match('#<small>([a-z_]+) #', $response->body(), $m) === 1 ? $m[1] : '';
}

/** The consent screen $browser is shown for a handoff to $start, signed in as $user. */
function screenFor(Browser $browser, string $start = START): Response
{
    $toAuthority = $browser->get($start);
    expect($toAuthority->status)->toBe(303);

    $screen = $browser->get((string) $toAuthority->header('location'));
    expect(Browser::isConsent($screen))->toBeTrue($screen->body());

    return $screen;
}

/** A browser signed in on the platform host as $user, not following past a consent screen. */
function asking(App $app, string $user = 'alice'): Browser
{
    $browser = new Browser($app)->signedInAt('id.platform.test', $user);
    $browser->consent = null;

    return $browser;
}

describe('a handoff to a custom domain', function () {
    it('issues no code until the person confirms it, on a screen naming the domain and the account', function () {
        $app = new App();
        $browser = asking($app);

        $screen = screenFor($browser);
        $stopped = $browser->follow(START);

        expect($screen->status)->toBe(200)
            ->and($screen->header('location'))->toBeNull()
            ->and($screen->header('content-type'))->toStartWith('text/html')
            ->and($screen->body())->toContain('acme.example')
            ->and($screen->body())->toContain('alice')
            ->and($screen->body())->not->toContain(HandoffEndpoint::CALLBACK)
            // Following redirects alone ends at the screen; the domain has
            // made no session for anyone.
            ->and(Browser::isConsent($stopped))->toBeTrue()
            ->and(json($browser->get('https://acme.example/whoami')))->toBe(['user' => ''])
            ->and(array_filter($app->sessions->sessions, static fn (array $row): bool => $row['realm'] === 'acme.example'))->toBe([]);
    });

    it('issues the code once the confirm is posted, and the person arrives signed in', function () {
        $app = new App();
        $browser = asking($app);

        $confirmed = $browser->answer(screenFor($browser));
        $browser->consent = HandoffConsent::CONFIRM;
        $landed = $browser->follow((string) $confirmed->header('location'));

        expect($confirmed->status)->toBe(303)
            ->and($confirmed->header('location'))->toStartWith('https://acme.example' . HandoffEndpoint::CALLBACK . '?code=')
            ->and(json($landed))->toBe(['user' => 'alice']);
    });

    it('hands the application\'s own screen the domain, the account and the forms\' fields', function () {
        $asked = [];
        $app = new App(consent: static function (Request $request, HandoffConsent $consent) use (&$asked): Response {
            $asked[] = $consent;

            return new HandoffConsentShell('Platform', account: static fn (Authenticatable $user): string => "{$user->authIdentifier()}@platform.test")
                ->respond($request, $consent);
        });
        $browser = asking($app);

        $screen = screenFor($browser);
        $consent = $asked[0] ?? null;

        expect($consent)->toBeInstanceOf(HandoffConsent::class)
            ->and($consent?->destination())->toBe('acme.example')
            ->and($consent?->user->authIdentifier())->toBe('alice')
            ->and($consent?->target->origin)->toBe('https://acme.example')
            ->and($consent?->action())->toBe(HandoffEndpoint::AUTHORIZE)
            ->and($consent?->fields(HandoffConsent::CANCEL))->toBe([
                HandoffConsent::CSRF => $app->csrf->for(new Request('GET', '/', cookies: ['__Host-sid' => $browser->jar['id.platform.test']])),
                HandoffConsent::TICKET => $consent?->ticket,
                HandoffConsent::DECISION => HandoffConsent::CANCEL,
            ])
            ->and($consent?->expiresAt)->toBe($app->now + ConsentTicket::TTL)
            ->and($screen->body())->toContain('alice@platform.test')
            ->and($screen->body())->toContain('not run by Platform');
    });

    it('names a domain with its port, and escapes what the application names the account', function () {
        $user = new \Tinvar\Tests\Identity\Fixtures\User('alice');
        $consent = new HandoffConsent($user, Realm::domain('https://acme.example:8443', 'acme', 'portal'), 'ticket', 'token', 1);
        $screen = new HandoffConsentShell('Platform', account: static fn (): string => '<img src=x onerror=alert(1)>')
            ->respond(Request::get('/'), $consent);

        expect($consent->destination())->toBe('acme.example:8443')
            ->and($screen->body())->toContain('&lt;img src=x onerror=alert(1)&gt;')
            ->and($screen->body())->not->toContain('<img')
            ->and(static fn () => $consent->fields('yes'))->toThrow(\InvalidArgumentException::class);
    });
});

describe('the pre-trusted path', function () {
    // The platform runs its own hosts: a session made there reaches nobody
    // the person needs warning about, so the code goes at once.
    it('never asks about the platform\'s own hosts: a panel\'s host, or a tenant\'s subdomain of the platform', function () {
        $app = new App();

        foreach (['https://admin.platform.test' => 'sam', 'https://acme.platform.test' => 'alice'] as $origin => $user) {
            $browser = asking($app, $user);
            $toAuthority = $browser->get($origin . '/_handoff/start?return=%2Fwhoami');
            $authorize = $browser->get((string) $toAuthority->header('location'));

            expect($authorize->status)->toBe(303, $origin)
                ->and($authorize->header('location'))->toStartWith($origin . HandoffEndpoint::CALLBACK . '?code=')
                ->and(json($browser->follow((string) $authorize->header('location'))))->toBe(['user' => $user]);
        }
    });

    it('asks about every custom domain, another of the same tenant\'s included', function () {
        $app = new App();

        foreach (['https://acme.example', 'https://shop.acme.example'] as $origin) {
            expect(Browser::isConsent(screenFor(asking($app), $origin . '/_handoff/start?return=%2Fwhoami')))->toBeTrue($origin);
        }
    });
});

describe('the consent screen', function () {
    it('is never framed, never kept, and never told on', function () {
        $app = new App();
        $screen = screenFor(asking($app));

        expect($screen->header('x-frame-options'))->toBe('DENY')
            ->and($screen->header('content-security-policy'))->toContain("frame-ancestors 'none'")
            ->and($screen->header('cache-control'))->toContain('no-store')
            ->and($screen->header('referrer-policy'))->toBe('no-referrer')
            // Its forms post with the session's token, so it sets the cookie
            // the token is checked against.
            ->and($screen->cookies())->toHaveKey('__Host-XSRF-TOKEN');
    });

    // An application's own policy is kept, and the framing rule added as a
    // policy of its own; a header saying framing is fine is overruled.
    it('holds an application\'s own screen to the same headers, beside its own policy', function () {
        $app = new App(consent: static fn (Request $request, HandoffConsent $consent): Response => new HandoffConsentShell()
            ->respond($request, $consent)
            ->withHeader('content-security-policy', "script-src 'self'")
            ->withHeader('x-frame-options', 'SAMEORIGIN')
            ->withHeader('cache-control', 'public, max-age=600'));

        $screen = screenFor(asking($app));

        expect($screen->header('x-frame-options'))->toBe('DENY')
            ->and($screen->header('content-security-policy'))->toBe("script-src 'self', frame-ancestors 'none'")
            ->and($screen->header('cache-control'))->toContain('no-store')
            ->and($screen->header('cache-control'))->not->toContain('public');
    });

    it('allows no form-action rule, which would stop the redirect to the domain a confirm is answered with', function () {
        $screen = screenFor(asking(new App()));

        expect($screen->header('content-security-policy'))->not->toContain('form-action');
    });
});

describe('a confirm', function () {
    it('is refused when posted again, or answered the other way after it', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);

        $first = $browser->answer($screen);
        $again = $browser->answer($screen);
        expect($app->lastLogged())->toContain('answered before');
        $cancelAfter = $browser->answer($screen, HandoffConsent::CANCEL);

        expect($first->status)->toBe(303)
            ->and($again->status)->toBe(403)
            ->and(errorCode($again))->toBe('handoff_refused')
            ->and($again->header('location'))->toBeNull()
            ->and($cancelAfter->status)->toBe(403)
            ->and($app->lastLogged())->toContain('answered before');
    });

    it('is redeemed only for the domain it named: a ticket edited, or a field added to the post, sends no code elsewhere', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        [, $fields] = Browser::form($screen, HandoffConsent::CONFIRM);
        [$v, $payload, $mac] = explode('.', $fields[HandoffConsent::TICKET]);
        $swapped = $v . '.' . Signer::encode(str_replace('acme.example', 'globex.example', (string) Signer::decode($payload))) . '.' . $mac;

        $edited = $browser->answer($screen, edit: [HandoffConsent::TICKET => $swapped]);
        expect($app->lastLogged())->toContain('signature does not verify');

        // What the post says beside the ticket is not read.
        $added = $browser->answer($screen, edit: ['origin' => 'https://globex.example', 'return' => '//globex.example']);

        expect($edited->status)->toBe(403)
            ->and(errorCode($edited))->toBe('handoff_refused')
            ->and($added->status)->toBe(303)
            ->and($added->header('location'))->toStartWith('https://acme.example' . HandoffEndpoint::CALLBACK . '?code=');

        $location = (string) $added->header('location');
        $code = HandoffCode::decode(rawurldecode(substr($location, (int) strpos($location, 'code=') + 5)), $app->signer, $app->now);
        expect($code->origin)->toBe('https://acme.example')
            ->and($code->returnTo)->toBe('/whoami');
    });

    it('is refused when the domain it named is no longer the realm it was: moved to another tenant, another panel, or gone', function () {
        foreach ([
            Realm::domain('https://acme.example', 'globex', 'portal'),
            Realm::domain('https://acme.example', 'acme', 'billing'),
            null,
        ] as $now) {
            $app = new App();
            $browser = asking($app);
            $screen = screenFor($browser);

            $app->moved['acme.example'] = $now;
            $response = $browser->answer($screen);

            expect($response->status)->toBe(403)
                ->and(errorCode($response))->toBe('handoff_refused')
                ->and($app->lastLogged())->toContain('no longer the realm it named');
        }
    });

    it('carries the challenge of the handoff it was shown for, so its code redeems only in the browser that started that one', function () {
        $app = new App();
        $victim = asking($app);
        $screen = screenFor($victim);
        $confirmed = $victim->answer($screen);

        // The code the confirm sent, lifted and presented elsewhere.
        $attacker = new Browser($app);
        $response = $attacker->get((string) $confirmed->header('location'));

        expect($response->status)->toBe(403)
            ->and($app->lastLogged())->toContain('not the one that started the handoff');
    });

    it('is refused when who is signed in has changed since the screen was shown', function () {
        // Their epoch moved: a sign-out elsewhere, a role change.
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        $app->directory->epochs['alice']++;
        $moved = $browser->answer($screen);
        expect($app->lastLogged())->toContain('has changed since it was shown');

        // Signed out, or somebody else signed in on the same session.
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        $app->sessions->sessions[$browser->jar['id.platform.test']] = ['user' => 'carol', 'realm' => 'id.platform.test'];
        $other = $browser->answer($screen);
        unset($app->sessions->sessions[$browser->jar['id.platform.test']]);
        $signedOut = $browser->answer($screen);

        expect($moved->status)->toBe(403)
            ->and($other->status)->toBe(403)
            ->and($signedOut->status)->toBe(403)
            ->and(errorCode($signedOut))->toBe('handoff_refused');
    });

    it('is refused when the membership it was asked for was taken away while the screen was open', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);

        $app->directory->members['alice'] = [];
        $response = $browser->answer($screen);

        expect($response->status)->toBe(403)
            ->and($app->lastLogged())->toContain('no membership');
    });

    it('is refused for a ticket shown to another session, even with that session\'s own CSRF token', function () {
        $app = new App();
        $victim = asking($app);
        [, $fields] = Browser::form(screenFor($victim), HandoffConsent::CONFIRM);

        // The victim's ticket, posted by the attacker's browser -- signed in
        // as the victim on another session -- with its own valid token.
        $attacker = asking($app);
        [, $own] = Browser::form(screenFor($attacker), HandoffConsent::CONFIRM);
        $response = $attacker->post('https://id.platform.test/_handoff/authorize', [...$own, HandoffConsent::TICKET => $fields[HandoffConsent::TICKET]]);

        expect($response->status)->toBe(403)
            ->and($app->lastLogged())->toContain('shown to another session');
    });

    it('is refused once the screen has been open longer than a ticket lives', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);

        $app->now += ConsentTicket::TTL - 1;
        $inTime = $browser->answer($screen);

        $late = asking($app);
        $lateScreen = screenFor($late);
        $app->now += ConsentTicket::TTL;
        $expired = $late->answer($lateScreen);

        expect($inTime->status)->toBe(303)
            ->and($expired->status)->toBe(403)
            ->and(errorCode($expired))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('expired')
            ->and($expired->header('location'))->toBeNull();
    });

    it('is not taken without a decision, or with one that is neither', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        [, $fields] = Browser::form($screen, HandoffConsent::CONFIRM);
        unset($fields[HandoffConsent::DECISION]);

        $none = $browser->post('https://id.platform.test/_handoff/authorize', $fields);
        $other = $browser->answer($screen, edit: [HandoffConsent::DECISION => 'yes']);
        $noTicket = $browser->answer($screen, edit: [HandoffConsent::TICKET => '']);

        expect($none->status)->toBe(400)
            ->and($other->status)->toBe(400)
            ->and($noTicket->status)->toBe(403)
            // Still good: none of those spent it.
            ->and($browser->answer($screen)->status)->toBe(303);
    });
});

describe('CSRF', function () {
    it('refuses a confirm posted without the session\'s token, or with somebody else\'s', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        $attacker = asking($app, 'carol');
        [, $theirs] = Browser::form(screenFor($attacker), HandoffConsent::CONFIRM);

        $without = $browser->answer($screen, edit: [HandoffConsent::CSRF => '']);
        $foreign = $browser->answer($screen, edit: [HandoffConsent::CSRF => $theirs[HandoffConsent::CSRF]]);

        $noCookie = new Browser($app);
        $noCookie->jar = $browser->jar;
        [, $fields] = Browser::form($screen, HandoffConsent::CONFIRM);
        $cookieless = $noCookie->post('https://id.platform.test/_handoff/authorize', $fields);

        expect($without->status)->toBe(419)
            ->and($foreign->status)->toBe(419)
            ->and($cookieless->status)->toBe(419)
            // Refused before the ticket was read: it is still good.
            ->and($browser->answer($screen)->status)->toBe(303);
    });

    // Another page posting the form in the person's browser: its cookies
    // go with it, but it cannot read the token the form needs.
    it('refuses a confirm another site makes the person\'s browser post', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        [, $fields] = Browser::form($screen, HandoffConsent::CONFIRM);
        unset($fields[HandoffConsent::CSRF]);

        $response = $browser->post('https://id.platform.test/_handoff/authorize', $fields);

        expect($response->status)->toBe(419)
            ->and($response->header('location'))->toBeNull();
    });

    it('refuses a Kernel that would mount a handoff asking consent without CSRF tokens', function () {
        $app = new App();

        expect(static fn () => new Kernel(
            orm: $app->orm,
            routes: new RouteRegistry(),
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: $app->auth,
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            handoff: $app->endpoint,
        ))->toThrow(\InvalidArgumentException::class, 'CsrfTokens');
    });

    it('takes a POST at the authorize step alone, and on the platform host alone', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        [, $fields] = Browser::form($screen, HandoffConsent::CONFIRM);

        $atStart = $app->kernel->handle(new Request('POST', HandoffEndpoint::START, ['host' => 'acme.example', 'accept' => 'application/json']), static fn (): Response => Response::make(500));

        // Past the domain's own CSRF check, with its own session's token.
        $token = (string) $app->csrf->for(new Request('GET', '/', cookies: ['__Host-sid' => $browser->sessionAt('acme.example')]));
        $browser->xsrf['acme.example'] = $token;
        $onDomain = $browser->post('https://acme.example/_handoff/authorize', [...$fields, HandoffConsent::CSRF => $token]);

        expect($atStart->status)->toBe(405)
            ->and($atStart->header('allow'))->toBe('GET')
            ->and($onDomain->status)->toBe(404)
            ->and(errorCode($onDomain))->toBe('handoff_unknown');
    });
});

describe('a cancel', function () {
    it('returns to the domain with an error, and issues no code', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);

        $cancelled = $browser->answer($screen, HandoffConsent::CANCEL);
        $arrived = $browser->get((string) $cancelled->header('location'));

        expect($cancelled->status)->toBe(303)
            ->and($cancelled->header('location'))->toBe('https://acme.example' . HandoffEndpoint::CALLBACK . '?error=access_denied')
            ->and($cancelled->header('location'))->not->toContain('code=')
            ->and($arrived->status)->toBe(403)
            ->and(errorCode($arrived))->toBe('handoff_cancelled')
            ->and(json($browser->get('https://acme.example/whoami')))->toBe(['user' => ''])
            ->and(array_filter($app->sessions->sessions, static fn (array $row): bool => $row['realm'] === 'acme.example'))->toBe([])
            // And the ticket is spent: no confirm after it.
            ->and($browser->answer($screen)->status)->toBe(403);
    });

    it('is taken even once the membership is gone: it hands nothing over', function () {
        $app = new App();
        $browser = asking($app);
        $screen = screenFor($browser);
        $app->directory->members['alice'] = [];

        expect($browser->answer($screen, HandoffConsent::CANCEL)->status)->toBe(303);
    });

    it('reads no code arriving beside the error', function () {
        $app = new App();
        $browser = new Browser($app)->signedInAt('id.platform.test', 'alice');
        $browser->consent = HandoffConsent::CONFIRM;
        $toAuthority = $browser->get(START);
        $confirmed = $browser->answer($browser->get((string) $toAuthority->header('location')));

        $both = $browser->get($confirmed->header('location') . '&error=access_denied');

        expect($both->status)->toBe(403)
            ->and(errorCode($both))->toBe('handoff_cancelled')
            ->and(json($browser->get('https://acme.example/whoami')))->toBe(['user' => '']);
    });
});

describe('a ticket\'s tenant', function () {
    // ADR 0056: the realm's tenant with tenancy registered, as it always
    // was; with none, the claim no tenant can be (TenantClaim). A ticket
    // carrying the other mode's claim is refused as one whose destination
    // is no longer the realm it named.
    it('is the claim of the mode the screen was shown in, and refused in the other', function (bool $tenancy) {
        $app = new App(tenancy: $tenancy);
        $browser = asking($app);
        $screen = screenFor($browser);
        [, $fields] = Browser::form($screen, HandoffConsent::CONFIRM);
        $ticket = ConsentTicket::decode((string) $fields[HandoffConsent::TICKET], $app->signer, $app->now);
        $flipped = new ConsentTicket(
            sessionHash: $ticket->sessionHash,
            authHash: $ticket->authHash,
            origin: $ticket->origin,
            tenant: $tenancy ? TenantClaim::NONE : 'acme',
            panel: $ticket->panel,
            challenge: $ticket->challenge,
            state: $ticket->state,
            returnTo: $ticket->returnTo,
            id: $ticket->id,
            issuedAt: $ticket->issuedAt,
            expiresAt: $ticket->expiresAt,
        )->encode($app->signer);

        expect($ticket->tenant)->toBe($tenancy ? 'acme' : TenantClaim::NONE);

        $refused = $browser->answer($screen, edit: [HandoffConsent::TICKET => $flipped]);

        expect($refused->status)->toBe(403)
            ->and(errorCode($refused))->toBe('handoff_refused')
            ->and($app->lastLogged())->toContain('no longer the realm it named');
    })->with(['tenancy' => [true], 'no tenancy' => [false]]);

    it('lands the person signed in, in either mode', function (bool $tenancy) {
        $app = new App(tenancy: $tenancy);
        $browser = asking($app);

        $confirmed = $browser->answer(screenFor($browser));
        $browser->consent = HandoffConsent::CONFIRM;
        $landed = $browser->follow((string) $confirmed->header('location'));

        expect($confirmed->status)->toBe(303)
            ->and(json($landed))->toBe(['user' => 'alice']);
    })->with(['tenancy' => [true], 'no tenancy' => [false]]);
});

describe('a ticket\'s claims', function () {
    it('carry the session and the epoch only as keyed hashes, and verify only as a ticket', function () {
        $app = new App();
        $realm = Realm::domain('https://acme.example', 'acme', 'portal');
        $ticket = ConsentTicket::mint($app->signer, 'session-id-1', 'alice', '7', $realm, str_repeat('c', 43), str_repeat('s', 22), '/', $app->now, $realm->tenant);
        $encoded = $ticket->encode($app->signer);
        $payload = (string) Signer::decode(explode('.', $encoded)[1]);
        $code = HandoffCode::mint($app->signer, $realm, 'alice', '7', str_repeat('c', 43), str_repeat('s', 22), '/', $app->now, $realm->tenant)->encode($app->signer);

        expect(ConsentTicket::decode($encoded, $app->signer, $app->now))->toEqual($ticket)
            ->and($ticket->belongsTo($app->signer, 'session-id-1'))->toBeTrue()
            ->and($ticket->belongsTo($app->signer, 'session-id-2'))->toBeFalse()
            ->and($ticket->belongsTo($app->signer, ''))->toBeFalse()
            ->and($ticket->isFor($app->signer, 'alice', '7'))->toBeTrue()
            ->and($ticket->isFor($app->signer, 'alice', '8'))->toBeFalse()
            ->and($payload)->not->toContain('session-id-1')
            ->and($payload)->not->toContain('alice')
            // A code is no ticket, and a ticket no code, under one signer.
            ->and(static fn () => ConsentTicket::decode($code, $app->signer, $app->now))->toThrow(TokenError::class)
            ->and(static fn () => HandoffCode::decode($encoded, $app->signer, $app->now))->toThrow(TokenError::class);
    });

    it('are refused living longer than a ticket is issued for, issued in the future, or bound to nobody', function () {
        $app = new App();
        $hash = str_repeat('a', 32);
        $long = new ConsentTicket($hash, $hash, 'https://acme.example', 'acme', 'portal', str_repeat('c', 43), str_repeat('s', 22), '/', str_repeat('j', 22), $app->now, $app->now + ConsentTicket::TTL + 1);
        $ahead = new ConsentTicket($hash, $hash, 'https://acme.example', 'acme', 'portal', str_repeat('c', 43), str_repeat('s', 22), '/', str_repeat('j', 22), $app->now + 120, $app->now + 150);
        $realm = Realm::domain('https://acme.example', 'acme', 'portal');

        expect(static fn () => ConsentTicket::decode($long->encode($app->signer), $app->signer, $app->now))->toThrow(TokenError::class)
            ->and(static fn () => ConsentTicket::decode($ahead->encode($app->signer), $app->signer, $app->now))->toThrow(TokenError::class)
            ->and(static fn () => ConsentTicket::decode(SignedToken::encode($app->signer, ConsentTicket::PURPOSE, ['s' => 'x']), $app->signer, $app->now))->toThrow(TokenError::class)
            ->and(static fn () => ConsentTicket::mint($app->signer, '', 'alice', '1', $realm, str_repeat('c', 43), str_repeat('s', 22), '/', $app->now, $realm->tenant))->toThrow(\InvalidArgumentException::class);
    });
});
