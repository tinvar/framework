<?php

declare(strict_types=1);

/**
 * A partial reload through the whole render path: PHP picks the segments,
 * runs only the loaders they need, and the shop's RSC entry renders those
 * segments alone through composeRoute() -- with the queries of the segments
 * left out never called.
 *
 * Skipped, as the other renderer tests are, when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\PartialReload;

use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\Request;
use Tinvar\Render\RenderResult;

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** The layout's footer: in the payload only when the layout is rendered. */
const FOOTER = 'Pellwick Supply is a demo store';

/**
 * The catalog's payload, as a client asks for it.
 *
 * @param array<string, string> $headers
 * @return array{string, RenderResult}
 */
function catalog(Kernel $kernel, array $headers = []): array
{
    $payload = '';
    $result = $kernel->render(
        Request::get('/', ['RSC' => '1', 'X-Version' => 'dev', ...$headers]),
        static function (string $bytes) use (&$payload): void {
            $payload .= $bytes;
        },
    );

    return [$payload, $result];
}

/** @param \Closure(Kernel): void $test */
function withShop(\Closure $test): void
{
    $renderer = Renderer::start('msgpack', 'partial-reload');
    try {
        $test(Kernel::fromEnvironment($renderer->socketPath, new MessagePackCodec(), debug: true, database: Shop::database()));
    } finally {
        $renderer->stop();
    }
}

it('renders the whole page when nothing partial is asked for', function () {
    withShop(function (Kernel $kernel): void {
        [$payload, $result] = catalog($kernel);

        expect($payload)->toContain(FOOTER)
            ->and(substr_count($payload, '"data-product-name":""'))->toBe(24)
            // And the header's read of who is signed in (ADR 0060).
            ->and($result->queryCalls)->toBe(25);
    });
});

it('renders only the page for X-Only: page, and the layout\'s queries and markup stay out', function () {
    withShop(function (Kernel $kernel): void {
        [$payload, $result] = catalog($kernel, ['X-Page' => 'shop/catalog', 'X-Only' => 'page']);

        expect($payload)->not->toContain(FOOTER)
            ->and($payload)->not->toContain('<html')
            ->and(substr_count($payload, '"data-product-name":""'))->toBe(24)
            ->and($result->queryCalls)->toBe(24, 'the cards\' availability is part of the page, and the header\'s shared read is not');
    });
});

it('renders only the layout for X-Only: shop, and none of the page\'s queries run', function () {
    withShop(function (Kernel $kernel): void {
        [$payload, $result] = catalog($kernel, ['X-Page' => 'shop/catalog', 'X-Only' => 'shop']);

        expect($payload)->toContain(FOOTER)
            ->and($payload)->not->toContain('"data-product-name":""')
            ->and($result->queryCalls)->toBe(1, 'the header\'s read of who is signed in, and nothing of the page');
    });
});

// "Partial requests apply only to the same page."
it('renders the whole page when the client holds another', function () {
    withShop(function (Kernel $kernel): void {
        [$payload] = catalog($kernel, ['X-Page' => 'shop/product', 'X-Only' => 'page']);

        expect($payload)->toContain(FOOTER)
            ->and(substr_count($payload, '"data-product-name":""'))->toBe(24);
    });
});

it('says which page a payload is, and which payload is partial', function () {
    withShop(function (Kernel $kernel): void {
        $whole = $kernel->handle(Request::get('/', ['RSC' => '1', 'X-Version' => 'dev']));
        $part = $kernel->handle(Request::get('/', ['RSC' => '1', 'X-Version' => 'dev', 'X-Page' => 'shop/catalog', 'X-Only' => 'page']));

        expect($whole->status)->toBe(200)
            ->and($whole->header('x-page'))->toBe('shop/catalog')
            ->and($whole->header('x-partial'))->toBeNull()
            ->and($part->header('x-partial'))->toBe('1')
            ->and($part->header('cache-control'))->toBe('private, no-store');
    });
});

// The first page cannot read headers, so its view travels in the document.
it('writes the first page\'s view into the page metadata', function () {
    withShop(function (Kernel $kernel): void {
        $html = $kernel->handle(Request::get('/', ['accept' => 'text/html']))->body();

        expect(preg_match('#<script type="application/json" id="tinvar-page">(.*?)</script>#s', $html, $m))->toBe(1)
            ->and(json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['view'])->toBe('shop/catalog');
    });
});
