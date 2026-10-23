<?php

declare(strict_types=1);

/**
 * `X-Version` was read by nothing: BuildRegistry was reachable only from
 * inside a fragment token, so a client on a revoked build kept navigating,
 * calling actions and running queries indefinitely.
 */

use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Middleware\CheckVersion;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

function versioned(): CheckVersion
{
    return new CheckVersion((new BuildRegistry('b3', ['b1', 'b2']))->revoke('b1'));
}

function passes(): Closure
{
    return static fn (Request $r): Response => Response::make(200, 'ok');
}

function clientNav(string $path, ?string $version, array $query = []): Request
{
    $headers = ['RSC' => '1'];
    if ($version !== null) {
        $headers['X-Version'] = $version;
    }

    return Request::get($path, $headers, $query);
}

describe('a client navigation', function () {
    it('passes on the current build', function () {
        expect(versioned()->handle(clientNav('/racks/r-1', 'b3'), passes())->status)->toBe(200);
    });

    it('passes on a retained older build, which is what skew is for', function () {
        expect(versioned()->handle(clientNav('/racks/r-1', 'b2'), passes())->status)->toBe(200);
    });

    // A payload from build B refers to client modules build A does not have.
    // A full load is the only thing that replaces code the browser holds.
    it('answers 409 with X-Location on a revoked build', function () {
        $response = versioned()->handle(clientNav('/racks/r-1', 'b1'), passes());

        expect($response->status)->toBe(409)
            ->and($response->header('x-location'))->toBe('/racks/r-1');
    });

    it('answers 409 on a build it has never heard of', function () {
        expect(versioned()->handle(clientNav('/racks/r-1', 'b99'), passes())->status)->toBe(409);
    });

    // Missing is a mismatch, not a pass.
    it('answers 409 when the client states no version', function () {
        expect(versioned()->handle(clientNav('/racks/r-1', null), passes())->status)->toBe(409);
    });

    it('keeps the query string in the location', function () {
        $response = versioned()->handle(clientNav('/racks', 'b1', ['page' => '2']), passes());

        expect($response->header('x-location'))->toBe('/racks?page=2');
    });

    // Rebuilt from the parsed array, ?tag=a&tag=b came back as
    // ?tag%5B0%5D=a&tag%5B1%5D=b -- a different page.
    it('echoes the query string exactly as the browser sent it', function (string $raw) {
        $request = new Request('GET', '/racks', ['rsc' => '1', 'x-version' => 'b1'], ['parsed' => 'x'], rawQuery: $raw);

        expect(versioned()->handle($request, passes())->header('x-location'))->toBe('/racks?' . $raw);
    })->with(['tag=a&tag=b', 'q=a+b%20c', 'flag', 'tags[]=x&tags[]=y']);

    // It lands in a response header; anything a browser would have encoded
    // is not trusted to be a query string.
    it('falls back to the parsed query when the raw one is not plain ASCII', function (string $raw) {
        $request = new Request('GET', '/racks', ['rsc' => '1', 'x-version' => 'b1'], ['page' => '2'], rawQuery: $raw);

        expect(versioned()->handle($request, passes())->header('x-location'))->toBe('/racks?page=2');
    })->with(["a=1\r\nset-cookie: x=1", 'a=b c', "a=\u{e9}", 'a=1#frag', "a=1\n"]);

    // The location is the request's own path. Nothing a client sends can
    // turn this into a redirect somewhere else.
    it('never takes the location from anything but the request path', function () {
        $request = Request::get('/racks/r-1', [
            'RSC' => '1',
            'X-Version' => 'b1',
            'X-Location' => 'https://evil.example/',
            'Referer' => 'https://evil.example/',
        ]);

        expect(versioned()->handle($request, passes())->header('x-location'))->toBe('/racks/r-1');
    });

    it('does not let the refusal be cached', function () {
        $response = versioned()->handle(clientNav('/racks/r-1', 'b1'), passes());

        expect($response->header('cache-control'))->toBe('private, no-store');
    });
});

describe('what is never refused for its version', function () {
    // "Non-GET requests never get a version 409." A POST turned away for its
    // version would lose the user's input.
    it('lets a POST through on a revoked build', function (string $method) {
        $request = new Request($method, '/_action/x', ['rsc' => '1', 'x-version' => 'b1']);

        expect(versioned()->handle($request, passes())->status)->toBe(200);
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);

    // A full page load IS the remedy; refusing one would be a loop.
    it('lets a document load through whatever its version', function () {
        $request = Request::get('/racks/r-1', ['X-Version' => 'b1']);

        expect(versioned()->handle($request, passes())->status)->toBe(200);
    });
});

/**
 * "/\evil.example/x" echoed back as a relative X-Location resolves to
 * https://evil.example/x in a browser. The path is refused before it can be
 * reflected, as Response::assertSameOrigin already refuses it on redirects.
 */
it('never reflects a backslash path into X-Location', function (string $path) {
    expect(fn () => Request::get($path, ['RSC' => '1', 'X-Version' => 'b1']))
        ->toThrow(Tinvar\Http\NotFoundException::class);
})->with(['/\\evil.example/x', '/%5Cevil.example/x', '/racks/a\\b']);
