<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\ExternalOriginsTest;

use Tinvar\Http\ExternalOrigins;

/**
 * The allowlist ActionResult::location() is held to: "External URLs go
 * through location() with an allowlist." An https origin matched exactly --
 * scheme, host and port -- and anything that cannot be read the way a
 * browser would read it, refused.
 */
describe('a URL', function () {
    it('is allowed on an allowlisted origin, wherever on it it points', function (string $url) {
        expect(new ExternalOrigins('https://pay.example', 'https://sso.example:8443')->allows($url))->toBeTrue();
    })->with([
        'the origin' => ['https://pay.example'],
        'a path and query' => ['https://pay.example/checkout?order=7&back=%2Forders'],
        'a fragment' => ['https://pay.example/#done'],
        'the default port spelled out' => ['https://pay.example:443/checkout'],
        'another port, allowlisted' => ['https://sso.example:8443/login'],
        // As Tenancy\Host normalizes a host: one origin, one spelling.
        'the host in capitals' => ['https://PAY.Example/checkout'],
        'the host with a trailing dot' => ['https://pay.example./checkout'],
        'the scheme in capitals' => ['HTTPS://pay.example/checkout'],
    ]);

    it('is refused anywhere else', function (string $url) {
        expect(new ExternalOrigins('https://pay.example', 'https://sso.example:8443')->allows($url))->toBeFalse();
    })->with([
        'another host' => ['https://evil.example/'],
        'a subdomain' => ['https://evil.pay.example/'],
        'a parent domain' => ['https://example/'],
        'a host ending like it' => ['https://notpay.example/'],
        'plain http' => ['http://pay.example/checkout'],
        'another port' => ['https://pay.example:8443/'],
        'the default port, where another is allowlisted' => ['https://sso.example/login'],
        'a port of zero' => ['https://pay.example:0/'],
        'a port past the last' => ['https://pay.example:65536/'],
        // `pay.example` is the userinfo; evil.example is the host.
        'userinfo' => ['https://pay.example@evil.example/'],
        'userinfo with a password' => ['https://pay.example:443@evil.example/'],
        // Read as a slash by a browser, and so as the end of the host.
        'a backslash' => ['https://pay.example\\@evil.example/'],
        'a backslash in the path' => ['https://pay.example/\\evil.example'],
        'a space' => ['https://pay.example /'],
        'a newline' => ["https://pay.example/\r\nSet-Cookie: x=1"],
        'a tab in the host' => ["https://pay.\texample/"],
        'scheme-relative' => ['//pay.example/'],
        'a path' => ['/checkout'],
        'javascript' => ['javascript:alert(1)'],
        'an address' => ['https://192.0.2.1/'],
        'an IPv6 address' => ['https://[::1]/'],
        'a percent-encoded host' => ['https://p%61y.example/'],
        'an empty host' => ['https:///pay.example'],
        'nothing' => [''],
    ]);

    it('is refused everywhere with no allowlist', function () {
        expect(new ExternalOrigins()->allows('https://pay.example/'))->toBeFalse();
    });

    // A refusal is logged, so it names the origin and not the URL, whose
    // query may carry a token.
    it('is refused by checked() naming the origin, never the URL', function () {
        $origins = new ExternalOrigins('https://pay.example');

        expect($origins->checked('https://pay.example/checkout'))->toBe('https://pay.example/checkout')
            ->and(fn () => $origins->checked('https://evil.example/cb?token=secret'))
            ->toThrow(\InvalidArgumentException::class, 'given a URL on https://evil.example:443, which the application has not allowlisted')
            ->and(fn () => $origins->checked('http://pay.example/?token=secret'))
            ->toThrow(\InvalidArgumentException::class, 'is not https:// on a host name');

        try {
            $origins->checked('https://evil.example/cb?token=secret');
        } catch (\InvalidArgumentException $e) {
            expect($e->getMessage())->not->toContain('secret');
        }
    });
});

describe('an allowlisted origin', function () {
    it('is refused unless it is an https origin and nothing more', function (string $origin) {
        expect(fn () => new ExternalOrigins($origin))
            ->toThrow(\InvalidArgumentException::class, "is https:// and a host name, with a port only when it is not 443, and nothing after it; '{$origin}' is not one.");
    })->with([
        'plain http' => ['http://pay.example'],
        'a path' => ['https://pay.example/checkout'],
        'a query' => ['https://pay.example?x=1'],
        'a wildcard' => ['https://*.pay.example'],
        'userinfo' => ['https://user@pay.example'],
        'a bare host' => ['pay.example'],
        'an address' => ['https://192.0.2.1'],
        'nothing' => [''],
    ]);

    it('may be written with a trailing slash', function () {
        expect(new ExternalOrigins('https://pay.example/')->allows('https://pay.example/checkout'))->toBeTrue();
    });
});
