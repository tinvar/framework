<?php

declare(strict_types=1);

/**
 * What a Link header may name of the build's assets, and with which digest
 * (ADR 0108): the page's preloads after it renders, and the 103 Early
 * Hints before.
 *
 * A header's preload is fetched before the document's import map is read.
 * Chromium put a module preloaded without a digest in its module map as it
 * came, and a later import never asked the map for one -- so a build held
 * to its digests names nothing in a header without its own.
 */

namespace Tinvar\Tests\Http\AssetLinks;

use Tinvar\Http\AssetLinks;
use Tinvar\Http\BuiltAssets;
use Tinvar\Http\Sapi;
use Tinvar\Render\ModulePreload;
use Tinvar\Render\RenderResult;

const ENTRY = 'sha384-' . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const SHEET = 'sha384-' . 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
const FORM = 'sha384-' . 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

/** @param array<string, mixed> $record */
function built(array $record): BuiltAssets
{
    $root = sys_get_temp_dir() . '/tinvar-links-' . bin2hex(random_bytes(6));
    mkdir("{$root}/rsc", 0o777, true);
    file_put_contents("{$root}/rsc/assets.json", json_encode($record));
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    return new BuiltAssets($root);
}

/** @param list<string> $preload */
function rendered(array $preload): RenderResult
{
    return new RenderResult([['t' => 'meta', 'preload' => $preload]], 0, 0, 0, 0, 0.0, 0.0, 0.0, 0.0);
}

it('writes each preload with its digest from a build that has them', function () {
    $links = AssetLinks::fromBuild(built([
        'format' => 1,
        'origin' => null,
        'integrity' => ['/assets/index-a1b2c3d4.js' => ENTRY, '/assets/Form-a1b2c3d4.js' => FORM],
    ]));

    expect(ModulePreload::header(rendered(['/assets/index-a1b2c3d4.js', '/assets/Form-a1b2c3d4.js']), assets: $links))->toBe(
        '</assets/index-a1b2c3d4.js>; rel=modulepreload; integrity="' . ENTRY . '", '
        . '</assets/Form-a1b2c3d4.js>; rel=modulepreload; integrity="' . FORM . '"',
    );
});

// The renderer names which modules a page loaded; the digests are the
// build's. A module the build has none for is not sent unchecked.
it('leaves out a module the build has no digest for', function () {
    $links = AssetLinks::fromBuild(built(['format' => 1, 'origin' => null, 'integrity' => ['/assets/index-a1b2c3d4.js' => ENTRY]]));

    expect(ModulePreload::header(rendered(['/assets/Planted-a1b2c3d4.js', '/assets/index-a1b2c3d4.js']), assets: $links))
        ->toBe('</assets/index-a1b2c3d4.js>; rel=modulepreload; integrity="' . ENTRY . '"');
});

// ADR 0097 sent no header for a CDN build. It sends one again, each entry
// on the CDN with its digest -- and nothing on another host.
it("names a CDN build's modules on its origin with their digests, and no other host's", function () {
    $cdn = 'https://cdn.example.com';
    $links = AssetLinks::fromBuild(built([
        'format' => 1,
        'origin' => $cdn,
        'integrity' => ["{$cdn}/assets/index-a1b2c3d4.js" => ENTRY],
    ]));

    expect(ModulePreload::header(rendered([
        "{$cdn}/assets/index-a1b2c3d4.js",
        'https://evil.example/assets/index-a1b2c3d4.js',
        "{$cdn}.evil.example/assets/index-a1b2c3d4.js",
        "{$cdn}/assets/Unknown-a1b2c3d4.js",
    ]), assets: $links))->toBe("<{$cdn}/assets/index-a1b2c3d4.js>; rel=modulepreload; integrity=\"" . ENTRY . '"');

    // A CDN build made without digests names no module of the CDN's.
    $unchecked = AssetLinks::fromBuild(built(['format' => 1, 'origin' => $cdn]));
    expect(ModulePreload::header(rendered(["{$cdn}/assets/index-a1b2c3d4.js"]), assets: $unchecked))->toBeNull();
});

it('keeps paths on this origin as they were for a build with no digests', function () {
    $links = AssetLinks::fromBuild(built(['format' => 1, 'origin' => null]));

    expect(ModulePreload::header(rendered(['/assets/index-a1b2c3d4.js', '//evil.example/x.js']), assets: $links))
        ->toBe('</assets/index-a1b2c3d4.js>; rel=modulepreload')
        ->and(ModulePreload::header(rendered(['/assets/index-a1b2c3d4.js'])))->toBe('</assets/index-a1b2c3d4.js>; rel=modulepreload');
});

it('drops a digest that is not one, and a record that is not a record', function () {
    $links = AssetLinks::fromBuild(built([
        'format' => 1,
        'origin' => null,
        'integrity' => ['/assets/index-a1b2c3d4.js' => 'sha384-x" , </evil.js>; rel=modulepreload', '/assets/ok-a1b2c3d4.js' => ENTRY],
    ]));

    expect($links->integrity)->toBe(['/assets/ok-a1b2c3d4.js' => ENTRY])
        ->and(AssetLinks::fromBuild(new BuiltAssets('/nonexistent/tinvar-build'))->integrity)->toBe([]);
});

it('hints the stylesheets every page links, then the entry and its imports, with their digests', function () {
    $links = AssetLinks::fromBuild(built([
        'format' => 1,
        'origin' => null,
        'integrity' => ['/assets/index-a1b2c3d4.js' => ENTRY, '/assets/index-b1b2c3d4.css' => SHEET],
        'hints' => [
            'modules' => ['/assets/index-a1b2c3d4.js', '/assets/Planted-a1b2c3d4.js', 42],
            'styles' => ['/assets/index-b1b2c3d4.css'],
        ],
    ]));

    expect($links->earlyHints())->toBe(
        '</assets/index-b1b2c3d4.css>; rel=preload; as=style; crossorigin; integrity="' . SHEET . '", '
        . '</assets/index-a1b2c3d4.js>; rel=modulepreload; integrity="' . ENTRY . '"',
    )
        ->and($links->earlyHints(maxEntries: 1))->toStartWith('</assets/index-b1b2c3d4.css>')
        ->and(AssetLinks::none()->earlyHints())->toBeNull();
});

it('sends an Early Hint only where the server can, and never a header that could end the line', function () {
    $sent = [];
    $send = static function (string $link) use (&$sent): bool {
        $sent[] = $link;

        return true;
    };

    expect(Sapi::earlyHints('</assets/a-a1b2c3d4.js>; rel=modulepreload', $send))->toBeTrue()
        ->and(Sapi::earlyHints("</a.js>\r\nSet-Cookie: x=1", $send))->toBeFalse()
        ->and(Sapi::earlyHints('', $send))->toBeFalse()
        ->and($sent)->toBe(['</assets/a-a1b2c3d4.js>; rel=modulepreload'])
        // The CLI is no FrankenPHP: nothing to send it with.
        ->and(Sapi::earlyHints('</assets/a-a1b2c3d4.js>; rel=modulepreload'))->toBeFalse();
});
