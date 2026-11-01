<?php

declare(strict_types=1);

/**
 * Order numbers: each day's orders numbered one after another, from that
 * day's counter.
 *
 * The next number was read from the orders themselves, a scan of every
 * order number inside the write lock every order holds; the load test found
 * order placement topping out at 200 a second at 80,000 orders, and
 * collapsing past it.
 */

namespace Tinvar\Tests\Integration\ShopOrderNumbers;

use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Catalog\Product;
use Shop\Support\Pricing;
use Tinvar\Bench\Shop;
use Tinvar\Orm\Orm;
use Tinvar\Testing\RendererPool;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\testing;

beforeEach(function () {
    $pool = RendererPool::for(Shop::entry(), \dirname(Shop::root(), 2));
    Testing::use(Kernel::fromEnvironment($pool->path, key: 'shop-order-numbers-key-of-32-bytes', database: Shop::testDatabase()), $pool);
});

afterEach(function () {
    Testing::forget();
});

function orm(): Orm
{
    return testing()->app->kernel()->orm;
}

function placeOrder(): void
{
    $customer = orm()->find(Customer::class, (string) orm()->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id']);
    $product = orm()->find(Product::class, (string) orm()->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id']);
    \assert($customer instanceof Customer && $product instanceof Product);

    actingAs($customer)->action('orders.place', product: $product, quantity: 1)->assertRedirect();
}

/** @return list<string> today's order numbers, in the order they were given */
function today(string $prefix): array
{
    return array_column(orm()->connection()->select(
        'SELECT number FROM orders WHERE number >= ? AND number < ? ORDER BY length(number), number',
        [$prefix, $prefix . ':'],
    ), 'number');
}

/**
 * Today's prefix, with today holding one order numbered $sequence -- as a
 * day with orders seeded or placed before its counter does -- and no other.
 */
function startingFrom(int $sequence): string
{
    $prefix = Pricing::orderNumberPrefix(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $connection = orm()->connection();
    $connection->execute("UPDATE orders SET number = 'seeded-' || number WHERE number >= ? AND number < ?", [$prefix, $prefix . ':']);
    $connection->execute('UPDATE orders SET number = ? WHERE id = (SELECT id FROM orders ORDER BY id LIMIT 1)', [$prefix . \sprintf('%04d', $sequence)]);

    return $prefix;
}

it('numbers a day\'s orders on from the highest it already has, growing a digit past 9999', function () {
    $prefix = startingFrom(9998);

    placeOrder();
    placeOrder();

    expect(today($prefix))->toBe([$prefix . '9998', $prefix . '9999', $prefix . '10000']);
});

it('takes the next number from the day\'s counter, not from the orders', function () {
    $prefix = startingFrom(41);
    placeOrder();

    // A higher number given behind the counter's back: read from the orders,
    // the next would be 0051.
    $connection = orm()->connection();
    $connection->execute('UPDATE orders SET number = ? WHERE id = (SELECT id FROM orders ORDER BY id DESC LIMIT 1 OFFSET 5)', [$prefix . '0050']);
    placeOrder();

    expect(today($prefix))->toBe([$prefix . '0041', $prefix . '0042', $prefix . '0043', $prefix . '0050'])
        ->and($connection->select('SELECT last FROM order_numbers WHERE prefix = ?', [$prefix])[0]['last'])->toBe(43);
});

it('counts the day afresh when its counter is behind a number already taken', function () {
    $prefix = startingFrom(41);
    placeOrder();

    // 0043, the counter's next, taken behind its back.
    orm()->connection()->execute('UPDATE orders SET number = ? WHERE id = (SELECT id FROM orders ORDER BY id DESC LIMIT 1 OFFSET 5)', [$prefix . '0043']);
    placeOrder();

    expect(today($prefix))->toBe([$prefix . '0041', $prefix . '0042', $prefix . '0043', $prefix . '0044']);
});
