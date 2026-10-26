<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "modulepreload for the current
 * page's client references, capped so Link headers never overflow server
 * buffers."
 */

namespace Tinvar\Tests\Render\ModulePreload;

use Tinvar\Render\ModulePreload;
use Tinvar\Render\RenderResult;

/** @param list<array<string, mixed>> $meta */
function result(array $meta): RenderResult
{
    return new RenderResult($meta, 0, 0, 0, 0, 0.0, 0.0, 0.0, 0.0);
}

it('preloads what the head preloads, in its order', function () {
    $header = ModulePreload::header(result([
        ['t' => 'meta', 'preload' => ['/assets/index-a1.js', '/assets/RackGrid-b2.js']],
        ['t' => 'meta', 'preload' => ['/assets/RackGrid-b2.js', '/assets/PowerChart-c3.js']],
    ]));

    expect($header)->toBe(
        '</assets/index-a1.js>; rel=modulepreload, </assets/RackGrid-b2.js>; rel=modulepreload, '
        . '</assets/PowerChart-c3.js>; rel=modulepreload',
    );
});

it('sends no header for a page that preloads nothing', function () {
    expect(ModulePreload::header(result([])))->toBeNull()
        ->and(ModulePreload::header(result([['t' => 'meta', 'title' => 'x']])))->toBeNull();
});

// A page with a hundred client components must not write a header past what
// a proxy buffers for all of a response's headers: it stops at the cap,
// keeping the first, which the page needs first.
it('stops at the count and the byte budget, keeping the head\'s first entries', function () {
    $modules = array_map(static fn (int $i): string => "/assets/Component{$i}-" . str_repeat('a', 40) . '.js', range(1, 100));
    $header = (string) ModulePreload::header(result([['t' => 'meta', 'preload' => $modules]]));

    expect(\strlen($header))->toBeLessThanOrEqual(ModulePreload::MAX_BYTES)
        ->and(substr_count($header, 'rel=modulepreload'))->toBeLessThanOrEqual(ModulePreload::MAX_ENTRIES)
        ->and($header)->toStartWith('</assets/Component1-');

    $few = (string) ModulePreload::header(result([['t' => 'meta', 'preload' => $modules]]), maxEntries: 3);
    expect(substr_count($few, 'rel=modulepreload'))->toBe(3);

    $small = (string) ModulePreload::header(result([['t' => 'meta', 'preload' => $modules]]), maxBytes: 200);
    expect(\strlen($small))->toBeLessThanOrEqual(200);
});

// The renderer runs third-party npm code: nothing it names reaches a header
// unless it is a path on this origin.
it('writes only paths on this origin into the header', function (mixed $href) {
    expect(ModulePreload::modulesOf([['t' => 'meta', 'preload' => [$href, '/assets/ok.js']]]))->toBe(['/assets/ok.js']);
})->with([
    'another origin' => ['https://evil.example/x.js'],
    'scheme-relative' => ['//evil.example/x.js'],
    'a header break' => ["/x.js>\r\nSet-Cookie: a=b"],
    'another link' => ['/x.js>; rel=preload, </y.js'],
    'a space' => ['/x .js'],
    'not a string' => [['/x.js']],
    'relative' => ['assets/x.js'],
]);

// Read after the render has returned, so past its deadline and its overall
// cap: a renderer naming the 29,000 paths a meta frame's 256 KB holds had
// each checked against every one kept before it, a second and a third of
// this process's time for a header of sixteen.
it('reads every path the renderer names once, however many it names', function () {
    $paths = array_map(static fn (int $i): string => "/a/{$i}.js", range(1, 29_000));
    $meta = [['t' => 'meta', 'preload' => $paths]];

    $started = hrtime(true);
    $header = (string) ModulePreload::header(result($meta));
    $modules = ModulePreload::modulesOf([...$meta, ['t' => 'meta', 'preload' => ['/a/1.js', '/a/2.js']]]);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(0.3)
        ->and(substr_count($header, 'rel=modulepreload'))->toBe(ModulePreload::MAX_ENTRIES)
        ->and($modules)->toBe($paths);
});

it('takes the dev server\'s module paths as they are', function () {
    expect(ModulePreload::modulesOf([['t' => 'meta', 'preload' => ['/_vite/@id/__x00__virtual:vite-rsc/entry-browser']]]))
        ->toBe(['/_vite/@id/__x00__virtual:vite-rsc/entry-browser']);
});
