<?php

declare(strict_types=1);

/**
 * The shop's previous URLs: a cookie bound to the session, set by the page
 * a visit renders, and the whole path -- the renderer included -- that
 * records a visit in it and reads it back to send a form posted without
 * script home. And none of it writes to the database (ADR 0050).
 */

namespace Tinvar\Tests\Integration\ShopPreviousUrl;

use Shop\Auth\Sessions\PreviousUrls;
use Shop\Auth\Sessions\SessionContext;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Support\Signer;

const KEY = 'shop-previous-url-test-key-32-bytes!';

/**
 * A request carrying a session cookie, a previous-URL cookie if given, and
 * the session context StartSession puts on every request.
 */
function from(string $session, ?string $previous = null): Request
{
    $cookies = [Kernel::SESSION_COOKIE => $session];
    if ($previous !== null) {
        $cookies[PreviousUrls::COOKIE] = $previous;
    }

    return new Request('GET', '/', cookies: $cookies)
        ->withAttribute(SessionContext::ATTRIBUTE, new SessionContext((string) Kernel::sessionFrom($session, KEY)));
}

/**
 * What put() left for StartSession to set: the cookie's value and Max-Age.
 *
 * @return array{string, int}|null
 */
function recorded(PreviousUrls $urls, string $session, string $url): ?array
{
    $request = from($session);
    $urls->put($request, $url);
    $context = $request->attribute(SessionContext::ATTRIBUTE);
    \assert($context instanceof SessionContext);

    return $context->visit();
}

describe('the store', function () {
    it('seals a URL into a cookie for its session, and reads it back', function () {
        $urls = new PreviousUrls(KEY);
        $session = Kernel::newSession(KEY);

        $visit = recorded($urls, $session, '/products/p-1?tab=specs');

        expect($visit)->not->toBeNull()
            ->and($urls->get(from($session, $visit[0] ?? null)))->toBe('/products/p-1?tab=specs')
            // The URL is the client's own, and readable to it; what keeps it
            // the session's is the MAC.
            ->and($visit[0] ?? '')->toStartWith(Signer::encode('/products/p-1?tab=specs') . '.');
    });

    // A copy of one session's cookie in another's jar names the wrong id.
    it('reads nothing from a cookie sealed for another session', function () {
        $urls = new PreviousUrls(KEY);
        $one = Kernel::newSession(KEY);
        $two = Kernel::newSession(KEY);

        $visit = recorded($urls, $one, '/orders');

        expect($urls->get(from($two, $visit[0] ?? null)))->toBeNull()
            ->and($urls->get(from($one, $visit[0] ?? null)))->toBe('/orders');
    });

    it('reads nothing from a cookie altered, re-spelled or sealed under another key', function () {
        $urls = new PreviousUrls(KEY);
        $session = Kernel::newSession(KEY);
        [$sealed] = recorded($urls, $session, '/orders') ?? [''];
        [, $mac] = explode('.', $sealed);
        $other = recorded(new PreviousUrls('another-key-entirely-of-32-bytes!!'), $session, '/orders');

        foreach ([
            Signer::encode('/account') . ".{$mac}",
            "{$sealed}.",
            "{$sealed}=",
            ".{$mac}",
            $other[0] ?? '',
            '',
        ] as $tampered) {
            expect($urls->get(from($session, $tampered)))->toBeNull();
        }
    });

    it('keeps nothing, and reads nothing, for a session cookie this key did not sign', function () {
        $urls = new PreviousUrls(KEY);
        $forged = Kernel::newSession('another-key-entirely-of-32-bytes!!');
        $genuine = recorded($urls, Kernel::newSession(KEY), '/orders');

        expect(recorded($urls, $forged, '/orders'))->toBeNull()
            ->and($urls->get(from($forged, $genuine[0] ?? null)))->toBeNull()
            ->and($urls->get(new Request('GET', '/')))->toBeNull();
    });

    // Outside the pipeline there is no response for the cookie to go on.
    it('keeps nothing for a request without a session context', function () {
        $urls = new PreviousUrls(KEY);
        $request = new Request('GET', '/', cookies: [Kernel::SESSION_COOKIE => Kernel::newSession(KEY)]);

        $urls->put($request, '/orders');

        expect($request->attribute(SessionContext::ATTRIBUTE))->toBeNull();
    });

    it('lasts as long as its session, and is forgotten once the session has expired', function () {
        $now = time();
        $urls = new PreviousUrls(KEY, static function () use (&$now): int {
            return $now;
        });
        $session = Kernel::newSession(KEY, $now - Kernel::SESSION_LIFETIME + 60);

        $visit = recorded($urls, $session, '/orders');

        expect($visit[1] ?? null)->toBe(60)
            ->and($urls->get(from($session, $visit[0] ?? null)))->toBe('/orders');

        $now += 61;

        expect($urls->get(from($session, $visit[0] ?? null)))->toBeNull();
    });

    it('sets nothing, and lets the old cookie go, when the session it was bound to ends', function () {
        $expired = PreviousUrls::forget(Response::make(200, ''))->cookies()[PreviousUrls::COOKIE] ?? '';

        expect($expired)->toStartWith(PreviousUrls::COOKIE . '=;')
            ->and($expired)->toContain('Max-Age=0')
            ->and($expired)->toContain('HttpOnly');
    });
});

/**
 * The served shop, renderer and all, with a browser of one. Skipped when the
 * shop cannot be built.
 */
describe('the shop', function () {
    beforeEach(function () {
        $unbuilt = Shop::ensureBuilt();
        if ($unbuilt !== null) {
            test()->markTestSkipped($unbuilt);
        }
    });

    /**
     * The request through the shop, its response's cookies kept as a browser
     * keeps them: set, replaced, or let go at Max-Age=0.
     *
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    function send(Kernel $kernel, string $method, string $url, array &$cookies, array $headers = ['accept' => 'text/html'], string $body = ''): Response
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        parse_str((string) $query, $parsed);
        $form = [];
        if (($headers['content-type'] ?? '') === 'application/x-www-form-urlencoded') {
            parse_str($body, $form);
        }
        $response = $kernel->handle(new Request($method, $path, $headers, $parsed, $form, $cookies, $query, '127.0.0.1'), $body);
        foreach ($response->cookies() as $name => $line) {
            if (str_contains($line, 'Max-Age=0')) {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
            }
        }

        return $response;
    }

    /** @param array<string, string> $cookies */
    function previous(array $cookies): ?string
    {
        return new PreviousUrls(KEY)->get(new Request('GET', '/', cookies: $cookies));
    }

    /**
     * How many commits the database has seen from connections other than
     * this one: it moves whenever another connection commits a write.
     */
    function commits(\PDO $watcher): int
    {
        return (int) $watcher->query('PRAGMA data_version')?->fetchColumn();
    }

    function client(): array
    {
        return ['rsc' => '1', 'accept' => 'text/x-component', 'x-version' => Kernel::build()];
    }

    it('records each page a session loads, the first among them, and not the one a prefetch asked for', function () {
        $renderer = Renderer::start('msgpack', 'previous-url');
        $database = Shop::database();
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database);
        $cookies = [];

        try {
            // Bound to the session, so kept out of shared caches, as the
            // CSRF cookie is.
            $landed = send($kernel, 'GET', '/', $cookies);
            expect($landed->status)->toBe(200)
                ->and($landed->cookies()[PreviousUrls::COOKIE] ?? '')->toContain('HttpOnly')
                ->and($landed->header('cache-control'))->toBe('private, no-store')
                ->and($landed->header('vary'))->toContain('Cookie')
                ->and(previous($cookies))->toBe('/');

            expect(send($kernel, 'GET', '/sign-in?next=%2Forders', $cookies)->status)->toBe(200)
                ->and(previous($cookies))->toBe('/sign-in?next=%2Forders');

            $prefetch = send($kernel, 'GET', '/', $cookies, [...client(), 'purpose' => 'prefetch']);
            expect($prefetch->status)->toBe(200)
                ->and($prefetch->cookies())->not->toHaveKey(PreviousUrls::COOKIE)
                ->and(previous($cookies))->toBe('/sign-in?next=%2Forders');

            expect(send($kernel, 'GET', '/', $cookies, client())->status)->toBe(200)
                ->and(previous($cookies))->toBe('/');

            // A reload of the page already recorded sets nothing.
            expect(send($kernel, 'GET', '/', $cookies, client())->cookies())->not->toHaveKey(PreviousUrls::COOKIE);
        } finally {
            $renderer->stop();
        }
    });

    // What held RSC navigations to their ceiling and 20,000 sessions to 380
    // page views a second: a write transaction per page view (ADR 0050).
    it('writes nothing to the database for a signed-in customer\'s page views', function () {
        $renderer = Renderer::start('msgpack', 'previous-url-writes');
        $database = Shop::database();
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database);
        $customer = $kernel->orm->whereIn(\Shop\Database\Models\Account\Customer::class, 'email', [DemoAccount::EMAIL])[0] ?? null;
        \assert($customer instanceof \Shop\Database\Models\Account\Customer);
        $cookies = $kernel->signedIn($customer, new Request('GET', '/', ['host' => '127.0.0.1']));
        $watcher = new \PDO("sqlite:{$database}");
        $before = commits($watcher);

        try {
            foreach (['/orders', '/', '/account', '/orders'] as $i => $url) {
                $response = send($kernel, 'GET', $url, $cookies, $i % 2 === 0 ? ['accept' => 'text/html', 'host' => '127.0.0.1'] : [...client(), 'host' => '127.0.0.1']);
                expect($response->status)->toBe(200, $kernel->lastFailure()?->getMessage() ?? $url)
                    ->and(previous($cookies))->toBe($url);
            }
        } finally {
            $renderer->stop();
        }

        expect(commits($watcher))->toBe($before);
    });

    // Back is the Referer; a browser that sends none is sent back to the
    // page its session last loaded -- the form's own.
    it('sends a sign-in posted without script and without a Referer back to the form', function () {
        $renderer = Renderer::start('msgpack', 'previous-url-form');
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database());
        $cookies = [];

        try {
            send($kernel, 'GET', '/', $cookies);
            send($kernel, 'GET', '/sign-in?next=%2Forders', $cookies);

            $token = (string) new CsrfTokens(
                new Signer(KEY, CsrfTokens::PURPOSE),
                static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
            )->for(new Request('GET', '/', cookies: $cookies));
            $fields = ['email' => DemoAccount::EMAIL, 'password' => 'not-the-password', 'next' => '/orders', '_token' => $token];

            $response = send(
                $kernel,
                'POST',
                '/_action/account.signIn',
                $cookies,
                ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html'],
                http_build_query($fields),
            );
        } finally {
            $renderer->stop();
        }

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/sign-in?next=%2Forders');
    });

    // A sign-in starts a new session, with no previous URL; the old one's
    // cookie is let go rather than left to ride on every request.
    it('starts a signed-in session with no previous URL', function () {
        $renderer = Renderer::start('msgpack', 'previous-url-sign-in');
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database());
        $cookies = [];

        try {
            send($kernel, 'GET', '/sign-in?next=%2Forders', $cookies);
            $old = $cookies[PreviousUrls::COOKIE] ?? null;
            $token = $cookies['__Host-XSRF-TOKEN'] ?? '';

            $response = send(
                $kernel,
                'POST',
                '/_action/account.signIn',
                $cookies,
                ['content-type' => 'application/json', 'accept' => 'application/json', 'rsc' => '1', 'x-xsrf-token' => $token],
                json_encode(['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/orders'], JSON_THROW_ON_ERROR),
            );
            $signedIn = $cookies;
            $signedIn[PreviousUrls::COOKIE] = (string) $old;
        } finally {
            $renderer->stop();
        }

        expect($response->status)->toBe(409, $kernel->lastFailure()?->getMessage() ?? '')
            ->and($old)->not->toBeNull()
            ->and($response->cookies()[PreviousUrls::COOKIE] ?? '')->toContain('Max-Age=0')
            ->and($cookies)->not->toHaveKey(PreviousUrls::COOKIE)
            // And the old cookie, kept by a browser that ignored that, names
            // the session that ended.
            ->and(previous($signedIn))->toBeNull();
    });
});
