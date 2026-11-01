<?php

declare(strict_types=1);

/**
 * The shop's endpoints (ADR 0067) through its whole request path: the
 * receipt download behind the session and OrderPolicy, and the stock JSON
 * anyone may ask for.
 */

namespace Tinvar\Tests\Integration\ShopEndpoints;

use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Orders\Order;
use Tinvar\Bench\Shop;
use Tinvar\Orm\Orm;
use Tinvar\Testing\RendererPool;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\get;
use function Tinvar\Testing\testing;

beforeEach(function () {
    $pool = RendererPool::for(Shop::entry(), \dirname(Shop::root(), 2));
    Testing::use(Kernel::fromEnvironment($pool->path, key: 'shop-endpoints-key-of-32-bytes!!', database: Shop::testDatabase()), $pool);
});

afterEach(function () {
    Testing::forget();
});

function orm(): Orm
{
    return testing()->app->kernel()->orm;
}

function customer(string $where, string $email = DemoAccount::EMAIL): Customer
{
    $id = (string) orm()->connection()->select("SELECT id FROM customers WHERE email {$where} ? ORDER BY id LIMIT 1", [$email])[0]['id'];
    $customer = orm()->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

function orderOf(Customer $customer): Order
{
    $id = (string) orm()->connection()->select('SELECT id FROM orders WHERE customer_id = ? ORDER BY placed_at DESC LIMIT 1', [$customer->id])[0]['id'];
    $order = orm()->find(Order::class, $id);
    \assert($order instanceof Order);

    return $order;
}

it('hands a customer the receipt of their own order, as a file', function () {
    $demo = customer('=');
    $order = orderOf($demo);

    $response = actingAs($demo)->get("/orders/{$order->id}/receipt.txt")->assertOk();

    expect($response->header('content-disposition'))->toBe("attachment; filename=\"receipt-{$order->number}.txt\"")
        ->and($response->header('cache-control'))->toBe('private, no-store')
        ->and($response->header('cross-origin-resource-policy'))->toBe('same-origin')
        ->and($response->response->body())->toContain("Receipt for order {$order->number}");
});

it('refuses another customer\'s receipt, and anyone signed out', function () {
    $order = orderOf(customer('='));

    actingAs(customer('!='))->get("/orders/{$order->id}/receipt.txt")->assertForbidden();
    get("/orders/{$order->id}/receipt.txt")->assertUnauthenticated();
});

it('answers a product\'s stock as JSON, to anyone', function () {
    $id = (string) orm()->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id'];

    $response = get("/products/{$id}/stock")->assertOk();

    expect($response->header('content-type'))->toBe('application/json')
        ->and(json_decode($response->response->body(), true))->toMatchArray(['product' => $id]);
});

// ADR 0074: a bucket of its own, inside the one every endpoint spends.
it('spends the stock reading\'s own bucket, which a caller paces itself by', function () {
    $id = (string) orm()->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id'];

    expect(get("/products/{$id}/stock")->assertOk()->header('x-ratelimit-limit'))->toBe('60');
});
