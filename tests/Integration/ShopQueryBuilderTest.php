<?php

declare(strict_types=1);

/**
 * The shop's reads through the query builder (ADR 0057): the catalog's
 * category and sort, read from the query string into closed sets, and a
 * customer's orders a page at a time -- through the whole request path, to
 * the data the renderer would have been handed.
 */

namespace Tinvar\Tests\Integration\ShopQueryBuilder;

use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Http\Pages\Orders\Orders;
use Tinvar\Bench\Shop;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\get;

const KEY = 'shop-query-builder-test-key-32-bytes!!';

beforeEach(function () {
    Testing::use(Kernel::fromEnvironment('/tmp/tinvar-shop-builder-none.sock', key: KEY, database: Shop::testDatabase()));
});

afterEach(function () {
    Testing::forget();
});

/** @return array<string, mixed> what the page's loader handed the renderer */
function data(string $url): array
{
    return get($url)->assertOk()->recorded()->encoded['page'];
}

/** @param array<string, mixed> $page @return list<mixed> */
function column(array $page, string $field): array
{
    return array_column($page['products'], $field);
}

describe('the catalog', function () {
    it('lists every product by catalog number, with the categories there are, in two statements', function () {
        $page = data('/');
        $skus = column($page, 'sku');
        $sorted = $skus;
        sort($sorted);

        expect($page['products'])->toHaveCount(24)
            ->and($skus)->toBe($sorted)
            ->and([$page['sort'], $page['order'], $page['category']])->toBe(['sku', 'asc', null])
            ->and($page['categories'])->toContain('Kitchen', 'Curiosities');

        get('/')->assertQueryBudget(2);
    });

    it('sorts by a field the cards show, either way', function () {
        $prices = column(data('/?sort=priceCents&order=desc'), 'priceCents');
        $sorted = $prices;
        rsort($sorted);
        $names = column(data('/?sort=name'), 'name');
        $byName = $names;
        sort($byName);

        expect($prices)->toBe($sorted)->and($names)->toBe($byName);
    });

    // Stock is a column of the product no card shows: sorting by it would
    // hand a visitor the order of every product's stock.
    it('reads a sort it does not offer as the default, a hidden column\'s included', function (string $query) {
        $page = data("/?{$query}");

        expect([$page['sort'], $page['order']])->toBe(['sku', 'asc'])
            ->and(column($page, 'sku'))->toBe(column(data('/'), 'sku'));
    })->with([
        'stock' => 'sort=stock',
        'a column name' => 'sort=price_cents',
        'SQL' => 'sort=' . rawurlencode('sku; DROP TABLE products'),
        'a direction' => 'order=' . rawurlencode('desc, (SELECT 1)'),
        'a list' => 'sort[]=name',
    ]);

    it('shows one category, and reads one there is not as none', function () {
        $kitchen = data('/?category=Kitchen');
        $unknown = data('/?category=' . rawurlencode("Kitchen' OR '1'='1"));

        // The categories read with distinct(): each once, in order, the
        // same list the unfiltered page reads from its products.
        expect(array_unique(column($kitchen, 'category')))->toBe(['Kitchen'])
            ->and($kitchen['categories'])->toBe(data('/')['categories'])
            ->and(\count($kitchen['categories']))->toBeGreaterThan(1)
            ->and($kitchen['category'])->toBe('Kitchen')
            ->and($unknown['category'])->toBeNull()
            ->and($unknown['products'])->toHaveCount(24);

        get('/?category=Kitchen')->assertQueryBudget(3);
    });
});

describe('a customer\'s orders', function () {
    it('reads a page of them by number, each with its items counted from its lines in one statement', function () {
        $orm = Testing::client()->app->kernel()->orm;
        $id = (string) $orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];
        $demo = $orm->find(Customer::class, $id);
        \assert($demo instanceof Customer);
        $expected = $orm->connection()->select(
            'SELECT o.number, (SELECT COALESCE(SUM(l.quantity), 0) FROM order_lines l WHERE l.order_id = o.id) AS items'
            . ' FROM orders o WHERE o.customer_id = ? ORDER BY o.placed_at DESC, o.id DESC',
            [$id],
        );

        $response = actingAs($demo)->get('/orders')->assertOk();
        $orders = $response->recorded()->encoded['page']['orders'];
        $shown = \array_slice($expected, 0, Orders::PER_PAGE);

        expect(array_column($orders['items'], 'number'))->toBe(array_column($shown, 'number'))
            ->and(array_column($orders['items'], 'itemCount'))->toBe(array_map('intval', array_column($shown, 'items')))
            ->and([$orders['page'], $orders['total'], $orders['perPage']])->toBe([1, \count($expected), Orders::PER_PAGE]);

        $response->assertNoLazyLoadsOutsideBatch();
    });
});
