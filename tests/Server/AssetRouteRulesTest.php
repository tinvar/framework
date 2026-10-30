<?php

declare(strict_types=1);

/**
 * `tinvar check`'s asset-route rule (ADR 0108): where an app's Caddyfile
 * serves the build's client directory, it serves the hashed names a build
 * writes alone, as PHP does -- not a source map, not whatever else is there.
 */

namespace Tinvar\Tests\Server\AssetRouteRules;

use Tinvar\Http\BuiltAssets;
use Tinvar\Server\AssetRouteRules;

const ROOT = __DIR__ . '/../../../..';

/** @return list<string> */
function checked(string $contents): array
{
    $directory = sys_get_temp_dir() . '/tinvar-asset-route-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $path = "{$directory}/Caddyfile";
    file_put_contents($path, $contents);
    try {
        return AssetRouteRules::check($path);
    } finally {
        unlink($path);
        rmdir($directory);
    }
}

/** A site serving the build's assets under $matcher. */
function site(string $matcher, string $handle = 'handle @assets'): string
{
    return <<<CADDY
        http://:8080 {
        	{$matcher}
        	{$handle} {
        		root * {\$TINVAR_BUILD_DIR:/nonexistent/tinvar-build}/client
        		file_server
        	}
        }
        CADDY;
}

it("passes the shop's Caddyfile, the skeleton's and the docs'", function () {
    expect(AssetRouteRules::check(ROOT . '/examples/shop/Caddyfile'))->toBe([])
        ->and(AssetRouteRules::check(ROOT . '/packages/skeleton/Caddyfile'))->toBe([])
        ->and(AssetRouteRules::check(ROOT . '/examples/docs/Caddyfile'))->toBe([]);
});

it('passes a route matched by the names PHP serves, quoted or not', function () {
    expect(checked(site('@assets path_regexp "' . BuiltAssets::routePattern() . '"')))->toBe([])
        ->and(checked(site('@assets path_regexp assets ' . BuiltAssets::routePattern())))->toBe([]);
});

it('fails a route that serves anything under the directory a path names', function (string $matcher, string $handle) {
    $violations = checked(site($matcher, $handle));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('Caddyfile:3: the build\'s client directory is served to any path under it')
        ->and($violations[0])->toContain(BuiltAssets::routePattern());
})->with([
    'a path prefix, as before' => ['@assets path /assets/*', 'handle @assets'],
    'a wider pattern' => ['@assets path_regexp ^/assets/.*$', 'handle @assets'],
    'a pattern with a source map in it' => ['@assets path_regexp "' . str_replace('wasm)', 'wasm|map)', BuiltAssets::routePattern()) . '"', 'handle @assets'],
    'an inline path' => ['', 'handle /assets/*'],
    'no matcher at all' => ['', 'handle'],
    'a matcher defined nowhere' => ['', 'handle @assets'],
]);

it('fails a matcher defined twice, or with more conditions, which could widen it', function () {
    $pattern = '"' . BuiltAssets::routePattern() . '"';

    expect(checked(site("@assets path_regexp {$pattern}\n\t@assets path /assets/*")))->toHaveCount(1)
        ->and(checked(site("@assets {\n\t\tpath_regexp {$pattern}\n\t}")))->toHaveCount(1);
});

it('leaves a file server of another root alone, and refuses what it cannot read', function () {
    expect(checked("http://:8080 {\n\t@brand path /brand/*.png\n\thandle @brand {\n\t\troot * /srv/public\n\t\tfile_server\n\t}\n}"))->toBe([])
        ->and(checked("http://:8080 {\n\thandle {\n"))->toBe(['Caddyfile: a block is never closed.'])
        ->and(AssetRouteRules::check('/nonexistent/Caddyfile'))->toHaveCount(1);
});
