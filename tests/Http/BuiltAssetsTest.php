<?php

declare(strict_types=1);

/**
 * The build's assets served through PHP where nothing in front of it does,
 * for a year, immutable; nothing but a hashed name the build wrote; and the
 * CDN origin a build records (ADR 0097).
 */

namespace Tinvar\Tests\Http\BuiltAssets;

use Tinvar\Http\BuiltAssets;

/** The builds a test made, removed after it. */
final class Builds
{
    /** @var list<string> */
    public static array $made = [];
}

afterEach(function () {
    foreach (Builds::$made as $root) {
        exec('rm -rf ' . escapeshellarg($root));
    }
    Builds::$made = [];
});

/** A build: client/assets with an entry, a stylesheet and a font, and a secret beside them. */
function build(?string $origin = null, bool $record = true): string
{
    $root = sys_get_temp_dir() . '/tinvar-assets-' . bin2hex(random_bytes(6));
    Builds::$made[] = $root;
    mkdir("{$root}/client/assets", 0o777, true);
    mkdir("{$root}/rsc", 0o777, true);
    file_put_contents("{$root}/client/assets/index-Tlw29lvo.js", 'console.log(1)');
    file_put_contents("{$root}/client/assets/index-DP4hYcT0.css", 'body{}');
    file_put_contents("{$root}/client/assets/Actions--Y4_0rlG.js", 'export {}');
    file_put_contents("{$root}/client/assets/geist-latin-wght-normal-XN7g48iV.woff2", 'wOF2');
    file_put_contents("{$root}/client/assets/index-Tlw29lvo.js.map", '{"sources":["/app/src"]}');
    file_put_contents("{$root}/client/assets/.env-12345678.js", 'secret');
    file_put_contents("{$root}/client/assets/notes.txt", 'notes');
    file_put_contents("{$root}/rsc/secret-ABCDEFGH.js", 'server code');
    symlink("{$root}/rsc/secret-ABCDEFGH.js", "{$root}/client/assets/linked-ABCDEFGH.js");
    if ($record) {
        file_put_contents("{$root}/rsc/assets.json", json_encode(['format' => 1, 'origin' => $origin]));
    }

    return $root;
}

it('serves a built asset for a year, immutable, typed, readable from a CDN', function () {
    $response = new BuiltAssets(build())->respond('GET', '/assets/index-Tlw29lvo.js');

    expect($response->status)->toBe(200)
        ->and($response->body())->toBe('console.log(1)')
        ->and($response->header('cache-control'))->toBe('public, max-age=31536000, immutable')
        ->and($response->header('content-type'))->toBe('text/javascript; charset=utf-8')
        ->and($response->header('content-length'))->toBe('14')
        ->and($response->header('x-content-type-options'))->toBe('nosniff')
        ->and($response->header('access-control-allow-origin'))->toBe('*')
        ->and($response->file())->toEndWith('/client/assets/index-Tlw29lvo.js')
        ->and($response->cookies())->toBe([]);
});

it('serves stylesheets and fonts by their type, a HEAD with no body, and refuses a write', function () {
    $assets = new BuiltAssets(build());

    expect($assets->respond('GET', '/assets/index-DP4hYcT0.css')->header('content-type'))->toBe('text/css; charset=utf-8')
        ->and($assets->respond('GET', '/assets/geist-latin-wght-normal-XN7g48iV.woff2')->header('content-type'))->toBe('font/woff2')
        ->and($assets->respond('GET', '/assets/Actions--Y4_0rlG.js')->status)->toBe(200)
        ->and($assets->respond('HEAD', '/assets/index-Tlw29lvo.js')->body())->toBe('')
        ->and($assets->respond('HEAD', '/assets/index-Tlw29lvo.js')->header('content-length'))->toBe('14')
        ->and($assets->respond('POST', '/assets/index-Tlw29lvo.js')->status)->toBe(405);
});

it('answers 404 for anything but a hashed name the build wrote', function (string $path) {
    $response = new BuiltAssets(build())->respond('GET', $path);

    expect($response->status)->toBe(404)
        ->and($response->body())->not->toContain('server code')
        ->and($response->header('cache-control'))->toBe('no-store');
})->with([
    'a traversal' => ['/assets/../rsc/secret-ABCDEFGH.js'],
    'an encoded traversal' => ['/assets/%2e%2e/rsc/secret-ABCDEFGH.js'],
    'an encoded slash' => ['/assets/..%2frsc%2fsecret-ABCDEFGH.js'],
    'a backslash' => ['/assets/..\\rsc\\secret-ABCDEFGH.js'],
    'a subdirectory' => ['/assets/x/index-Tlw29lvo.js'],
    'a source map' => ['/assets/index-Tlw29lvo.js.map'],
    'a dotfile' => ['/assets/.env-12345678.js'],
    'no hash' => ['/assets/notes.txt'],
    'a link out of the directory' => ['/assets/linked-ABCDEFGH.js'],
    'a name never built' => ['/assets/index-AAAAAAAA.js'],
    'a NUL' => ["/assets/index-Tlw29lvo.js\0.css"],
    'the directory' => ['/assets/'],
]);

it('takes a front controller\'s request for an asset, and leaves every other to the Kernel', function () {
    $assets = new BuiltAssets(build());

    expect($assets->match(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/assets/index-Tlw29lvo.js?v=2'])?->status)->toBe(200)
        ->and($assets->match(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/assets/nope'])?->status)->toBe(404)
        ->and($assets->match(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/catalog']))->toBeNull()
        ->and($assets->match(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/assetsx/index-Tlw29lvo.js']))->toBeNull();
});

it('reads the CDN origin a build records, and refuses a record naming anything else', function () {
    expect(new BuiltAssets(build('https://cdn.pellwick.test'))->origin())->toBe('https://cdn.pellwick.test')
        ->and(new BuiltAssets(build())->origin())->toBeNull()
        ->and(new BuiltAssets(build(record: false))->origin())->toBeNull()
        ->and(fn () => new BuiltAssets(build("https://cdn.example; script-src *"))->origin())->toThrow(\RuntimeException::class)
        ->and(fn () => new BuiltAssets(build('http://cdn.example'))->origin())->toThrow(\RuntimeException::class);
});
