<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\Csrf;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\HttpException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Auth\Fixtures\User as AuthUser;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Routing\Fixtures as R;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

/**
 * CSRF, from the cookie being issued to a POST that carries it.
 *
 * tinvar-spec.md, "HTTP": "Requests from JavaScript use the CSRF cookie and
 * header", and "Session and CSRF cookies use the `__Host-` prefix". Nothing
 * issued the cookie, so every POST endpoint answered 419; and the check
 * accepted any pair that matched itself, an empty one included.
 */
const SESSION_COOKIE = '__Host-test_session';

function tokens(): CsrfTokens
{
    return new CsrfTokens(
        new Signer(str_repeat('k', 32), CsrfTokens::PURPOSE),
        static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '',
    );
}

/** @param array<string, string> $cookies */
function post(array $cookies, ?string $header, string $path = '/_action/RackActions.moveUnit'): Request
{
    return new Request('POST', $path, $header === null ? [] : ['x-xsrf-token' => $header], cookies: $cookies);
}

function passes(VerifyCsrfToken $middleware, Request $request): bool
{
    try {
        return $middleware->handle($request, static fn (): Response => Response::make(200))->status === 200;
    } catch (HttpException $e) {
        expect($e->status)->toBe(419);

        return false;
    }
}

describe('the check', function () {
    // An empty cookie equals an empty header, and the comparison took them
    // as a matching pair: `Cookie: __Host-XSRF-TOKEN=` was a valid token.
    it('refuses an empty token', function (Request $request) {
        expect(passes(new VerifyCsrfToken(), $request))->toBeFalse()
            ->and(passes(new VerifyCsrfToken(tokens: tokens()), $request))->toBeFalse();
    })->with([
        'header' => [post([VerifyCsrfToken::COOKIE => ''], '')],
        'form field' => [new Request('POST', '/_action/x', [], [], ['_token' => ''], [VerifyCsrfToken::COOKIE => ''])],
    ]);

    it('accepts the session own token, in the header or the form field', function () {
        $cookies = [SESSION_COOKIE => 'session-a'];
        $token = tokens()->for(new Request('GET', '/', cookies: $cookies));
        $cookies[VerifyCsrfToken::COOKIE] = $token;

        expect(passes(new VerifyCsrfToken(tokens: tokens()), post($cookies, $token)))->toBeTrue()
            ->and(passes(
                new VerifyCsrfToken(tokens: tokens()),
                new Request('POST', '/_action/x', [], [], ['_token' => $token], $cookies),
            ))->toBeTrue();
    });

    // A matching pair is only as good as the cookie's origin: whoever can
    // plant the cookie also picks the header. Bound to the session, a pair
    // made up -- or lifted from another session -- is refused.
    it('refuses a pair that matches but is not the session own', function (string $token) {
        $cookies = [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => $token];

        expect(passes(new VerifyCsrfToken(tokens: tokens()), post($cookies, $token)))->toBeFalse();
    })->with([
        'made up' => ['attacker-chosen'],
        // Lazily, so a missing class fails this case rather than the file.
        'another session' => [fn (): string => (string) tokens()->for(new Request('GET', '/', cookies: [SESSION_COOKIE => 'session-b']))],
    ]);

    it('refuses every token when there is no session to bind it to', function () {
        expect(passes(new VerifyCsrfToken(tokens: tokens()), post([VerifyCsrfToken::COOKIE => 'x'], 'x')))->toBeFalse()
            ->and(tokens()->for(new Request('GET', '/')))->toBeNull();
    });

    // Its own key: a token signed for another purpose with the same
    // application key is not a CSRF token.
    it('derives the token under its own purpose', function () {
        $request = new Request('GET', '/', cookies: [SESSION_COOKIE => 'session-a']);
        $other = Signer::encode(new Signer(str_repeat('k', 32), 'fragment-token')->sign('csrf:session-a'));

        expect(tokens()->for($request))->not->toBe($other)
            ->and(tokens()->verify($request, $other))->toBeFalse();
    });
});

/** @return array{0: Kernel, 1: string, 2: string, 3: \Closure(): void} the kernel, a page path, a rack id, and a sign-in */
function kernel(): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 2, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $auth = new AuthContext();
    $auth->login(new AuthUser('u-1'));

    $routes = new RouteRegistry();
    $routes->registerAll([R\RootLayout::class, R\SiteLayout::class, R\RackShowPage::class]);

    $actions = new ActionRegistry();
    $actions->register(A\RackActions::class);

    $policies = (new PolicyRegistry())->registerAll([
        Rack::class => A\RecordingRackPolicy::class,
        Site::class => A\RecordingSitePolicy::class,
    ]);
    A\RecordingRackPolicy::reset();

    $kernel = new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: $policies,
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        csrf: tokens(),
    );

    // The reset contract signs the user out after every request.
    $signIn = static function () use ($auth): void {
        $auth->login(new AuthUser('u-1'));
    };

    return [
        $kernel,
        '/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0],
        $fixture->ids['racks'][0],
        $signIn,
    ];
}

function page(): \Closure
{
    return static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200, '<html></html>', [
        'content-type' => 'text/html; charset=utf-8',
    ]);
}

describe('through the Kernel', function () {
    it('issues the cookie on a page, and a POST carrying it succeeds', function () {
        [$kernel, $path, $rack, $signIn] = kernel();
        $session = [SESSION_COOKIE => 'session-a'];

        $document = $kernel->handle(new Request('GET', $path, cookies: $session), page());

        expect($document->status)->toBe(200)
            ->and($document->cookies())->toHaveKey(VerifyCsrfToken::COOKIE);

        // Readable by script, which copies it into the header; otherwise as
        // the spec's cookie rule says.
        $line = $document->cookies()[VerifyCsrfToken::COOKIE];
        expect($line)->toMatch('/^__Host-XSRF-TOKEN=[A-Za-z0-9_-]{43}; Path=\/; Secure; SameSite=Lax$/')
            ->and($document->header('cache-control'))->toBe('private, no-store')
            ->and($document->header('vary'))->toContain('Cookie');

        // What a browser sends back: both cookies, and the token in the
        // header the client copies it into.
        $token = substr(explode(';', $line)[0], \strlen(VerifyCsrfToken::COOKIE) + 1);
        $signIn();
        $response = $kernel->handle(
            post([...$session, VerifyCsrfToken::COOKIE => $token], $token),
            page(),
            json_encode(['rack' => $rack, 'position' => 12]),
        );

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['flash'])->toBe(['message' => 'Moved to 12']);
    });

    it('does not re-send a cookie the request already carries', function () {
        [$kernel, $path] = kernel();
        $session = [SESSION_COOKIE => 'session-a'];
        $token = (string) tokens()->for(new Request('GET', '/', cookies: $session));

        $again = $kernel->handle(
            new Request('GET', $path, cookies: [...$session, VerifyCsrfToken::COOKIE => $token]),
            page(),
        );

        expect($again->status)->toBe(200)
            ->and($again->cookies())->toBe([]);
    });

    it('refuses a POST carrying another session token', function () {
        [$kernel, , $rack] = kernel();
        $theirs = (string) tokens()->for(new Request('GET', '/', cookies: [SESSION_COOKIE => 'session-b']));

        $response = $kernel->handle(
            post([SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => $theirs], $theirs),
            page(),
            json_encode(['rack' => $rack, 'position' => 12]),
        );

        expect($response->status)->toBe(419);
    });

    it('issues nothing to a visitor with no session', function () {
        [$kernel, $path] = kernel();

        expect($kernel->handle(new Request('GET', $path), page())->cookies())->toBe([]);
    });
});
