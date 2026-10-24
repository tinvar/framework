<?php

declare(strict_types=1);

/**
 * The whole path, for real: PHP loaders -> Unix socket -> Node RSC + SSR ->
 * HTML streamed back, with the renderer calling queries the other way.
 *
 * Skipped rather than failed when the JavaScript dependencies the build
 * needs are absent, so the unit suite still runs on a machine without them.
 */

namespace Tinvar\Tests\Integration\RenderPath;

use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Codec\JsonLinesCodec;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\Request;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderResult;

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** @return array{Kernel, Renderer} */
function shopKernel(string $codec = 'msgpack'): array
{
    $renderer = Renderer::start($codec, "test-{$codec}");
    $kernel = Kernel::fromEnvironment(
        $renderer->socketPath,
        $codec === 'json-lines' ? new JsonLinesCodec() : new MessagePackCodec(),
        debug: true,
        database: Shop::database(),
    );

    return [$kernel, $renderer];
}

/** @return array{string, RenderResult} */
function renderCatalog(Kernel $kernel): array
{
    $html = '';
    $result = $kernel->render(Request::get('/', ['accept' => 'text/html']), function (string $bytes) use (&$html): void {
        $html .= $bytes;
    });

    return [$html, $result];
}

it('renders the catalog end to end', function () {
    [$kernel, $renderer] = shopKernel();

    try {
        [$html, $result] = renderCatalog($kernel);
    } finally {
        $renderer->stop();
    }

    expect($result->chunks)->toBeGreaterThan(1, 'the response streams in several flushes')
        ->and($result->bytes)->toBeGreaterThan(10_000);

    // The document, its stylesheet, and the layout rendered with its own
    // loader's data.
    expect($html)->toStartWith('<!DOCTYPE html>')
        ->toMatch('#<link rel="stylesheet"[^>]* href="/assets/index-[\w-]+\.css"#')
        ->toContain('Pellwick Supply')
        ->toContain('">Catalog · Pellwick Supply</title>');
    // One title: the page's, through the shop's template, PHP's fallback
    // and the default given way to it.
    expect(substr_count($html, '<title'))->toBe(1);

    // What the head preloads, reported over the socket for the Link header:
    // the client entry, then the client components' chunk.
    $preloaded = \Tinvar\Render\ModulePreload::modulesOf($result->meta);
    expect($preloaded)->not->toBe([])
        ->and($preloaded[0])->toMatch('#^/assets/index-[\w-]+\.js$#');
    foreach ($preloaded as $module) {
        expect($html)->toContain("href=\"{$module}\"");
    }

    // A card per product, each a link, and a client chunk referenced.
    expect(substr_count($html, 'data-product-name=""'))->toBe(24)
        ->and($html)->toMatch('#assets/index-[\w-]+\.js#');

    // Each card's availability: a fallback in the shell, the line in a later
    // flush once the batched query answered.
    expect($html)->toContain('<!--$?-->')
        ->toContain('$RC(')
        ->and(substr_count($html, 'data-availability='))->toBe(24);

    // The RSC payload travels inline, as the spec's rendered output describes.
    expect($html)->toContain('self.__rsc');

    // The router's starting point: a document load cannot read headers, so
    // the page's URL and build travel in the document itself, slashes
    // escaped, ahead of the payload rows.
    expect(preg_match('#<script type="application/json" id="tinvar-page">(.*?)</script>#s', $html, $m))->toBe(1);
    $meta = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    expect($meta['url'])->toBe('/')
        ->and($meta['version'])->toBe('dev')
        ->and(strpos($html, 'tinvar-page'))->toBeLessThan(strpos($html, 'self.__rsc'));
});

it('batches every availability query into one frame and no statement', function () {
    [$kernel, $renderer] = shopKernel();
    $statements = null;

    try {
        $kernel->orm->connection()->resetStatementCount();
        // Read as the render streams, since the request's teardown resets
        // the count once it is done; the last flush comes after every query.
        $result = $kernel->render(
            Request::get('/', ['accept' => 'text/html']),
            static function () use ($kernel, &$statements): void {
                $statements = $kernel->orm->connection()->statementCount();
            },
        );
    } finally {
        $renderer->stop();
    }

    expect($result->queryCalls)->toBe(24, 'one availability call per card')
        ->and($result->queryBatches)->toBe(1, '24 calls, one frame')
        // One: the products. The 24 availability calls resolve their
        // products from the identity map the loader filled, so the batch
        // costs no statement at all.
        ->and($statements)->toBe(1, 'the products, once');
});

it('lets no model column reach the browser', function () {
    [$kernel, $renderer] = shopKernel();

    try {
        [$html] = renderCatalog($kernel);
    } finally {
        $renderer->stop();
    }

    // stock and price_cents are real columns; the stock count is on no Data
    // object, only the availability query's reading of it.
    expect($html)->not->toContain('price_cents')
        ->not->toContain('"stock"')
        ->not->toContain('password');
});

it('renders identically over the json-lines codec', function () {
    [$kernel, $renderer] = shopKernel('json-lines');

    try {
        [$html, $result] = renderCatalog($kernel);
    } finally {
        $renderer->stop();
    }

    expect(substr_count($html, 'data-product-name=""'))->toBe(24)
        ->and(substr_count($html, 'data-availability='))->toBe(24)
        ->and($result->queryBatches)->toBe(1);
});

it('reports a clear error when the renderer is not there', function () {
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-no-such-renderer.sock', database: Shop::database());

    expect(fn () => renderCatalog($kernel))->toThrow(RendererException::class, 'Cannot reach the renderer');
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
    [$kernel, $renderer] = shopKernel();

    try {
        $document = $kernel->handle(Request::get('/', ['accept' => 'text/html']));
        // With the build the page metadata names, as the router sends it:
        // once fragment support is on, the version check is too.
        $payload = $kernel->handle(Request::get('/', ['RSC' => '1', 'X-Version' => 'dev']));
    } finally {
        $renderer->stop();
    }

    expect($document->status)->toBe(200)
        ->and($document->header('content-type'))->toStartWith('text/html')
        ->and($document->body())->toContain('tinvar-page');

    expect($payload->status)->toBe(200)
        ->and($payload->header('content-type'))->toBe('text/x-component')
        ->and($payload->body())->not->toContain('self.__rsc')
        ->and($payload->body())->toContain('Pellwick Supply');
});

// A failed document request is a page in the shop's own look, under the
// same policy, with the status the failure had and a reference to quote.
it('renders a designed page for a document that is not found', function () {
    [$kernel, $renderer] = shopKernel();

    try {
        $missing = $kernel->handle(Request::get('/no-such-page', ['accept' => 'text/html']));
    } finally {
        $renderer->stop();
    }

    expect($missing->status)->toBe(404)
        ->and($missing->header('content-security-policy'))->toStartWith("script-src 'nonce-")
        ->and($missing->body())->toContain('Nothing lives at this address')
        ->toContain('Browse the catalog')
        ->toMatch('#Reference <span[^>]*>[0-9a-f]{16}</span>#');
});

// "PHP sets a strict CSP with a per-request nonce and strict-dynamic ...
// Script injected into the HTML stream doesn't run."
it('sends a CSP, and puts its nonce on every script in the document', function () {
    [$kernel, $renderer] = shopKernel();

    try {
        $document = $kernel->handle(Request::get('/', ['accept' => 'text/html']));
        $payload = $kernel->handle(Request::get('/', ['RSC' => '1', 'X-Version' => 'dev']));
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

    // Including the ones React writes itself: the bootstrap module, and the
    // inline scripts that reveal the Suspense boundaries.
    expect(array_filter($scripts, static fn (string $tag): bool => str_contains($tag, 'type="module"')))->toHaveCount(1)
        ->and($document->body())->toContain('$RC(');

    // A payload is not a document, and gets no policy.
    expect($payload->header('content-security-policy'))->toBeNull();
});
