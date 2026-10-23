<?php

declare(strict_types=1);

use Racks\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Codec\JsonLinesCodec;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Render\RendererException;

/**
 * The whole path, for real: PHP loaders -> Unix socket -> Node RSC + SSR ->
 * HTML streamed back, with the renderer calling queries the other way.
 *
 * Skipped rather than failed when the prerequisites are absent, so the unit
 * suite still runs on a machine with no database or no build.
 */
$root = \dirname(__DIR__, 4);
$seedPath = "{$root}/examples/racks/database/seeded.json";
$appEntry = "{$root}/examples/racks/dist/rsc/index.js";

beforeAll(function () use ($seedPath, $appEntry) {
    if (!is_file($appEntry)) {
        test()->markTestSkipped('The Racks app is not built (npm run build -w @tinvar/example-racks).');
    }
    if (!is_file($seedPath)) {
        test()->markTestSkipped('No seed data (php examples/racks/database/seed.php).');
    }
});

function racksKernel(string $codec = 'msgpack'): array
{
    $root = \dirname(__DIR__, 4);
    $seeded = json_decode((string) file_get_contents("{$root}/examples/racks/database/seeded.json"), true);

    $renderer = Renderer::start($codec, "test-{$codec}");
    $kernel = Kernel::fromEnvironment(
        $renderer->socketPath,
        $codec === 'json-lines' ? new JsonLinesCodec() : new MessagePackCodec(),
        debug: true,
    );

    return [$kernel, $renderer, $seeded];
}

function renderRack(Kernel $kernel, string $rackId): array
{
    $html = '';
    $result = $kernel->showRack($rackId, function (string $bytes) use (&$html): void {
        $html .= $bytes;
    });

    return [$html, $result];
}

it('renders the Racks Show page end to end', function () {
    [$kernel, $renderer, $seeded] = racksKernel();

    try {
        [$html, $result] = renderRack($kernel, $seeded['racks'][0]);
    } finally {
        $renderer->stop();
    }

    expect($result->chunks)->toBeGreaterThan(1, 'the response streams in several flushes')
        ->and($result->bytes)->toBeGreaterThan(10_000);

    // The nested layout rendered, with its own loader's data.
    expect($html)->toContain('site-sidebar')->toContain('LAX1');

    // The 'use client' island: one button per unit, and its chunk referenced.
    expect(substr_count($html, 'class="unit"'))->toBe(42)
        ->and($html)->toMatch('#assets/index-[\w-]+\.js#');

    // The Suspense boundary: fallback in the shell, content in a later flush.
    expect($html)->toContain('stats-skeleton')
        ->toContain('<!--$?-->')
        ->toContain('$RC("B:0"')
        ->and(substr_count($html, 'class="watts"'))->toBe(42);

    // The RSC payload travels inline, as the spec's rendered output describes.
    expect($html)->toContain('self.__rsc');
});

it('batches every unit query into one frame and one statement', function () {
    [$kernel, $renderer, $seeded] = racksKernel();

    try {
        $kernel->orm->connection()->resetStatementCount();
        [, $result] = renderRack($kernel, $seeded['racks'][0]);
        $statements = $kernel->orm->connection()->statementCount();
    } finally {
        $renderer->stop();
    }

    expect($result->queryCalls)->toBe(42, 'one UnitLoad per unit')
        ->and($result->queryBatches)->toBe(1, '42 calls, one frame')
        ->and($statements)->toBe(4, 'sites, racks, the units relation, and one batched unitLoad');
});

it('lets no model column reach the browser', function () {
    [$kernel, $renderer, $seeded] = racksKernel();

    try {
        [$html] = renderRack($kernel, $seeded['racks'][0]);
    } finally {
        $renderer->stop();
    }

    // rated_watts is a real column on units and is on no Data object.
    expect($html)->not->toContain('rated_watts')
        ->not->toContain('ratedWatts')
        ->not->toContain('height_u');
});

it('renders identically over the json-lines codec', function () {
    [$kernel, $renderer, $seeded] = racksKernel('json-lines');

    try {
        [$html, $result] = renderRack($kernel, $seeded['racks'][0]);
    } finally {
        $renderer->stop();
    }

    expect(substr_count($html, 'class="unit"'))->toBe(42)
        ->and(substr_count($html, 'class="watts"'))->toBe(42)
        ->and($result->queryBatches)->toBe(1);
});

it('reports a clear error when the renderer is not there', function () {
    $root = \dirname(__DIR__, 4);
    $seeded = json_decode((string) file_get_contents("{$root}/examples/racks/database/seeded.json"), true);
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-no-such-renderer.sock');

    expect(fn () => renderRack($kernel, $seeded['racks'][0]))
        ->toThrow(RendererException::class, 'Cannot reach the renderer');
});
