<?php

declare(strict_types=1);

/**
 * The router fetches with redirect: 'manual', which hides a redirect's
 * Location from it entirely. Untranslated, every redirect on a client
 * navigation became a full load of the URL it had asked for.
 */

use Tinvar\Http\Middleware\TranslateRedirects;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

function translated(Response $response, array $headers = ['RSC' => '1']): Response
{
    return new TranslateRedirects()->handle(
        Request::get('/racks', $headers),
        static fn (): Response => $response,
    );
}

it('turns a same-origin redirect into 409 + X-Redirect', function (int $status) {
    $response = translated(Response::redirect('/login?next=%2Fracks', $status));

    expect($response->status)->toBe(409)
        ->and($response->header('x-redirect'))->toBe('/login?next=%2Fracks')
        ->and($response->header('x-location'))->toBeNull()
        ->and($response->header('location'))->toBeNull()
        ->and($response->header('cache-control'))->toBe('private, no-store');
})->with([301, 302, 303, 307, 308]);

// An external target is a document load, never a fetch with RSC headers.
it('turns an external redirect into 409 + X-Location', function () {
    $response = translated(Response::location('https://sso.example/login'));

    expect($response->status)->toBe(409)
        ->and($response->header('x-location'))->toBe('https://sso.example/login')
        ->and($response->header('x-redirect'))->toBeNull();
});

// Built without Response::redirect(), so its checks never ran. Anything a
// browser could read as another host is a document load, not a fetch.
it('never hands the router a target that could be another host', function (string $location) {
    $response = translated(Response::make(302, '', ['location' => $location]));

    expect($response->header('x-redirect'))->toBeNull()
        ->and($response->header('x-location'))->toBe($location);
})->with(['//evil.example/x', '/\\evil.example/x', 'evil.example/x', 'javascript:alert(1)', '/..//evil.example']);

it('leaves a document request\'s redirect for the browser to follow', function () {
    $response = translated(Response::redirect('/login'), []);

    expect($response->status)->toBe(302)
        ->and($response->header('location'))->toBe('/login');
});

it('leaves everything that is not a redirect alone', function (int $status) {
    expect(translated(Response::make($status, 'x'))->status)->toBe($status);
})->with([200, 304, 404, 409]);

// A login's session cookie or a logout's Clear-Site-Data is part of what the
// redirect does; dropped, it would silently stop happening for client visits.
it('carries what the redirect does besides redirecting', function () {
    $response = translated(
        Response::redirect('/dashboard')
            ->withHeader('set-cookie', 'session=abc; HttpOnly; Secure')
            ->withHeader('clear-site-data', '"cache"')
            ->withHeader('x-route-token', 'v1.old'),
    );

    expect($response->header('set-cookie'))->toBe('session=abc; HttpOnly; Secure')
        ->and($response->header('clear-site-data'))->toBe('"cache"')
        ->and($response->header('x-route-token'))->toBeNull();
});
