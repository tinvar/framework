<?php

declare(strict_types=1);

/**
 * The storefront's catalog search (ADR 0150), through the shop's Kernel:
 * the search page's loader, the index the seeder built and every product
 * write keeps, and `tinvar search:reindex` on the shop's console.
 */

namespace Tinvar\Tests\Integration\ShopSearch;

use Shop\Database\Models\Catalog\Product;
use Shop\Kernel;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\get;

const KEY = 'shop-search-test-key-of-32-bytes!!!!';

beforeEach(function () {
    $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-search-none.sock', key: KEY, database: Shop::testDatabase());
    Testing::use($this->kernel);
});

afterEach(function () {
    Testing::forget();
});

/** @return array<string, mixed> what the search page's loader handed the renderer */
function results(string $query, int $page = 1): array
{
    return get('/search?' . http_build_query(['q' => $query, 'page' => $page]))->assertOk()->recorded()->encoded['page'];
}

it('finds the products matching every word, best first, the last word as a prefix', function () {
    $mug = results('mug');
    $tea = results('tea');

    expect(array_column($mug['products'], 'name'))->toBe(['Bottomless mug'])
        ->and($mug['total'])->toBe(1)
        ->and($mug['query'])->toBe('mug')
        ->and(array_column($tea['products'], 'name'))->toContain('Left-handed teacup')
        ->and(array_column(results('PW-1024')['products'], 'name'))->toBe(['Spare Tuesday']);
});

it('pages results twelve at a time', function () {
    $first = results('a');
    $all = results('the');

    expect(\count($all['products']))->toBeLessThanOrEqual(12)
        ->and($all['lastPage'])->toBe(max(1, (int) ceil($all['total'] / 12)))
        ->and($first['page'])->toBe(1)
        ->and(results('the', 999)['products'])->toBe([]);
});

it('answers nothing for nothing typed, and reads query syntax as words', function () {
    expect(results('')['products'])->toBe([])
        ->and(results('')['total'])->toBe(0);

    foreach (['"', 'mug* OR 1=1', "'; DROP TABLE products; --", 'NEAR(mug teacup)', 'tenant_id:x', str_repeat('mug ', 200)] as $typed) {
        expect(results($typed))->toHaveKey('products');
    }
    expect(array_column(results('"bottomless" mug')['products'], 'name'))->toBe(['Bottomless mug'])
        ->and(\strlen(results(str_repeat('x', 500))['query']))->toBe(100);
});

it('finds a product written through the ORM once its write commits, and loses one deleted', function () {
    $orm = $this->kernel->orm;
    $product = new Product();
    $product->sku = 'PW-9001';
    $product->name = 'Velvet hammer';
    $product->summary = 'Soft on nails.';
    $product->description = 'A hammer with a velvet face.';
    $product->category = 'Curiosities';
    $product->priceCents = 1900;
    $product->stock = 3;
    $orm->transaction(fn () => $orm->save($product));

    expect(array_column(results('velvet')['products'], 'name'))->toBe(['Velvet hammer']);

    $orm->delete($product);
    expect(results('velvet')['products'])->toBe([]);
});

it('rebuilds the index with search:reindex on the shop\'s console', function () {
    $lines = [];
    $status = $this->kernel->console->run('search:reindex', ['--force'], new Output(
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    ));

    expect($status)->toBe(0)
        ->and($lines)->toBe(['indexed 24 Product rows'])
        ->and(array_column(results('mug')['products'], 'name'))->toBe(['Bottomless mug']);
});
