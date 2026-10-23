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

    // The router's starting point: a document load cannot read headers, so
    // the page's URL and build travel in the document itself, slashes
    // escaped, ahead of the payload rows.
    expect(preg_match('#<script type="application/json" id="tinvar-page">(.*?)</script>#s', $html, $m))->toBe(1);
    $meta = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    expect($meta['url'])->toBe('/racks/' . $seeded['racks'][0])
        ->and($meta['version'])->toBe('dev')
        ->and($m[1])->not->toContain('"/racks')
        ->and(strpos($html, 'tinvar-page'))->toBeLessThan(strpos($html, 'self.__rsc'));
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
        // Three: sites, racks, and the units relation. The 42 unitLoad calls
        // resolve their units from the identity map, which already holds them
        // from the page's own data -- so the batch costs no statement at all.
        ->and($statements)->toBe(3, 'sites, racks, and the units relation');
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

// The renderer is the "runs third-party npm code" tier; the spec says its
// stacks and hints never reach the browser. fromErrorFrame() copied its
// message verbatim with no debug gate.
it('does not carry the renderer own error text by default', function () {
    $e = RendererException::fromErrorFrame([
        'code' => 'render_failed',
        'digest' => 'abc123',
        'message' => 'TypeError: undefined is not a function at /app/node_modules/evil/index.js:42',
    ]);

    expect($e->getMessage())->toBe('The renderer reported an error.')
        ->and($e->getMessage())->not->toContain('node_modules')
        ->and($e->code_)->toBe('render_failed')
        ->and($e->digest)->toBe('abc123', 'the structured handle survives');
});

it('includes the renderer message when debug is on', function () {
    $e = RendererException::fromErrorFrame(['message' => 'TypeError: boom'], debug: true);

    expect($e->getMessage())->toBe('TypeError: boom');
});

// The front controller's path: one Request in, the document or -- for a
// client navigation -- the payload alone out.
it('serves the document, or the payload alone for a client navigation', function () {
    [$kernel, $renderer, $seeded] = racksKernel();

    try {
        $path = '/racks/' . $seeded['racks'][0];
        $document = $kernel->handle(Tinvar\Http\Request::get($path));
        // With the build the page metadata names, as the router sends it:
        // once fragment support is on, the version check is too.
        $payload = $kernel->handle(Tinvar\Http\Request::get($path, ['RSC' => '1', 'X-Version' => 'dev']));
    } finally {
        $renderer->stop();
    }

    expect($document->status)->toBe(200)
        ->and($document->header('content-type'))->toStartWith('text/html')
        ->and($document->body())->toContain('tinvar-page');

    expect($payload->status)->toBe(200)
        ->and($payload->header('content-type'))->toBe('text/x-component')
        ->and($payload->body())->not->toContain('<html')
        ->and($payload->body())->not->toContain('self.__rsc')
        ->and($payload->body())->toContain('LAX1');
});

// "PHP sets a strict CSP with a per-request nonce and strict-dynamic ...
// Script injected into the HTML stream doesn't run." Neither half was there:
// no policy was sent, and React was never given the nonce, so its bootstrap
// module and its inline Suspense reveals carried none -- under the policy,
// the page would not hydrate.
it('sends a CSP, and puts its nonce on every script in the document', function () {
    [$kernel, $renderer, $seeded] = racksKernel();

    try {
        $path = '/racks/' . $seeded['racks'][0];
        $document = $kernel->handle(Tinvar\Http\Request::get($path));
        $payload = $kernel->handle(Tinvar\Http\Request::get($path, ['RSC' => '1', 'X-Version' => 'dev']));
    } finally {
        $renderer->stop();
    }

    $policy = (string) $document->header('content-security-policy');
    expect(preg_match(
        "#^script-src 'nonce-([A-Za-z0-9+/]{22}==)' 'strict-dynamic'; object-src 'none'; base-uri 'none'\$#",
        $policy,
        $m,
    ))->toBe(1, "the policy sent: '{$policy}'");
    $nonce = $m[1];

    // Every script element, except the page metadata: a data block is never
    // executed, so it is not a script the policy governs.
    preg_match_all('#<script\b[^>]*>#i', $document->body(), $tags);
    $scripts = array_values(array_filter(
        $tags[0],
        static fn (string $tag): bool => !str_contains($tag, 'type="application/json"'),
    ));

    expect($scripts)->not->toBeEmpty()
        ->and(array_values(array_filter(
            $scripts,
            static fn (string $tag): bool => !str_contains($tag, " nonce=\"{$nonce}\""),
        )))->toBe([], 'every script carries the nonce');

    // Including the ones React writes itself, which were the ones without
    // it: the bootstrap module, and the inline script that reveals the
    // Suspense boundary.
    expect(array_filter($scripts, static fn (string $tag): bool => str_contains($tag, 'type="module"')))->toHaveCount(1)
        ->and($document->body())->toContain('$RC("B:0"');

    // A payload is not a document, and gets no policy.
    expect($payload->header('content-security-policy'))->toBeNull();
});
