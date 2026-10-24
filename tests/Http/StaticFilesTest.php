<?php

declare(strict_types=1);

/**
 * Which requests a front controller answers with a built page, before
 * anything boots: a plain document GET or HEAD, with no query, from a visitor
 * without the session cookie. Everyone else goes to PHP.
 */

namespace Tinvar\Tests\Http\StaticFiles;

use Tinvar\Http\StaticFiles;

const COOKIE = '__Host-app_session';

/** A built directory with the home page and one doc, removed when the test process ends. */
function built(): string
{
    $root = sys_get_temp_dir() . '/tinvar-static-files-' . bin2hex(random_bytes(4));
    mkdir("{$root}/docs/intro", 0o755, true);
    file_put_contents("{$root}/index.html", '<!DOCTYPE html>home');
    file_put_contents("{$root}/docs/intro/index.html", '<!DOCTYPE html>intro');
    file_put_contents(\dirname($root) . '/' . basename($root) . '-secret.txt', 'outside');
    $owner = getmypid();
    register_shutdown_function(static function () use ($root, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg("{$root}-secret.txt"));
        }
    });

    return $root;
}

/** @param array<string, string> $server */
function server(string $uri, string $method = 'GET', array $server = []): array
{
    return ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, ...$server];
}

it('serves a built page to a visitor the server has not met', function (string $uri, string $file) {
    $root = built();

    expect(StaticFiles::match($root, server($uri), [], COOKIE))->toBe(realpath("{$root}/{$file}"));
})->with([
    'the root' => ['/', 'index.html'],
    'a page' => ['/docs/intro', 'docs/intro/index.html'],
    'a page, with a trailing slash' => ['/docs/intro/', 'docs/intro/index.html'],
    'an empty query' => ['/?', 'index.html'],
]);

it('serves HEAD as GET', function () {
    expect(StaticFiles::match(built(), server('/', 'HEAD'), [], COOKIE))->not->toBeNull();
});

it('sends everything else to PHP', function (array $server, array $cookies) {
    expect(StaticFiles::match(built(), $server, $cookies, COOKIE))->toBeNull();
})->with([
    // A visitor PHP has met: signed in perhaps, a flash waiting.
    'a session cookie' => [server('/'), [COOKIE => 'abc']],
    // The client router asking for a payload, which a document is not.
    'a client navigation' => [server('/', server: ['HTTP_RSC' => '1']), []],
    'a query the build never rendered' => [server('/?page=2'), []],
    'a POST' => [server('/', 'POST'), []],
    'a page that was not built' => [server('/docs/other'), []],
    'the file itself' => [server('/index.html'), []],
    'a climb out of the directory' => [server('/../' . 'x'), []],
    'an escaped climb' => [server('/%2e%2e/x'), []],
]);

it('leaves another cookie alone, which says nothing about the visitor', function () {
    expect(StaticFiles::match(built(), server('/'), ['theme' => 'dark'], COOKIE))->not->toBeNull();
});

it('sends a built page so no shared cache hands it to a visitor with a session', function () {
    $root = built();
    $response = StaticFiles::response("{$root}/index.html");

    expect($response->status)->toBe(200)
        ->and($response->body())->toBe('<!DOCTYPE html>home')
        ->and($response->header('content-type'))->toBe('text/html; charset=utf-8')
        ->and($response->header('cache-control'))->toBe('no-cache')
        ->and($response->header('vary'))->toBe('Cookie, RSC');
});
