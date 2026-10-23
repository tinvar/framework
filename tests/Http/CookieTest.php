<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\Cookies;

use Tinvar\Http\Middleware\TranslateRedirects;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Sapi;

/**
 * Cookies the framework writes.
 *
 * tinvar-spec.md, "HTTP": "Session and CSRF cookies use the `__Host-` prefix
 * and are `HttpOnly` (session), `Secure` and `SameSite=Lax`." A cookie
 * missing the prefix could be planted by a sibling subdomain; one missing
 * Secure could be planted over plain http. So the attributes are not left to
 * each caller.
 */
it('writes the attributes the spec requires', function () {
    $response = Response::make()
        ->withCookie('__Host-session', 'abc', maxAge: 60)
        ->withCookie('__Host-XSRF-TOKEN', 'tok', httpOnly: false);

    expect($response->cookies())->toBe([
        '__Host-session' => '__Host-session=abc; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=60',
        '__Host-XSRF-TOKEN' => '__Host-XSRF-TOKEN=tok; Path=/; Secure; SameSite=Lax',
    ]);
});

it('refuses a name without the __Host- prefix', function (string $name) {
    expect(fn () => Response::make()->withCookie($name, 'v'))->toThrow(\InvalidArgumentException::class, '__Host-');
})->with(['session', '__Secure-session', '__host-session', "__Host-a\n", '__Host-a b', '__Host-']);

it('refuses a value that could end the cookie or split the header', function (string $value) {
    expect(fn () => Response::make()->withCookie('__Host-a', $value))->toThrow(\InvalidArgumentException::class);
})->with(['a;Domain=evil.example', "a\r\nset-cookie: b=1", 'a b', 'a,b', '"a"', 'a\\b']);

it('refuses a negative Max-Age', function () {
    expect(fn () => Response::make()->withCookie('__Host-a', 'v', maxAge: -1))->toThrow(\InvalidArgumentException::class);
});

// A header map holds one value per name: the second cookie on a response
// replaced the first, so a page could not set a session and a CSRF token.
it('emits each cookie as a Set-Cookie line of its own', function () {
    $lines = [];
    Sapi::emit(
        Response::make(200, '', ['content-type' => 'text/html'])
            ->withCookie('__Host-a', '1')
            ->withCookie('__Host-b', '2'),
        static function (int $status): void {},
        static function (string $name, string $value) use (&$lines): void {
            $lines[] = "{$name}: {$value}";
        },
        static function (string $chunk): void {},
    );

    expect($lines)->toBe([
        'content-type: text/html',
        'set-cookie: __Host-a=1; Path=/; Secure; HttpOnly; SameSite=Lax',
        'set-cookie: __Host-b=2; Path=/; Secure; HttpOnly; SameSite=Lax',
    ]);
});

// A sign-in that redirects sets its cookie on the redirect. Translated for
// the client router, the 409 must still set it.
it('carries cookies through a translated redirect', function () {
    $translated = new TranslateRedirects()->handle(
        Request::get('/login', ['RSC' => '1']),
        static fn (): Response => Response::redirect('/dashboard')->withCookie('__Host-session', 'new'),
    );

    expect($translated->status)->toBe(409)
        ->and(array_keys($translated->cookies()))->toBe(['__Host-session']);
});
