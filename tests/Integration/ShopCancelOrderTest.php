<?php

declare(strict_types=1);

/**
 * The shop's one action that revalidates its page in place (ADR 0121): a
 * customer cancels an order not yet packed, from the order's own page. The
 * answer is no redirect but the page re-rendered, with the order's tag among
 * those invalidated, so the page's <Deferred> shipping updates fetch
 * themselves again. Only the customer's own order, and only while it is
 * placed: the policy asks first, and the move is from placed alone.
 */

namespace Tinvar\Tests\Integration\ShopCancelOrder;

use Shop\Http\Actions\Orders\OrderActions;
use Shop\Auth\Sessions\AuthEpochs;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Orders\Order;
use Shop\Database\Models\Orders\OrderStatus;
use Tinvar\Bench\Shop;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;

const KEY = 'shop-cancel-order-test-key-of-32-bytes';

beforeEach(function () {
    $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-cancel-none.sock', key: KEY, database: Shop::testDatabase());
    Testing::use($this->kernel);
});

afterEach(function () {
    Testing::forget();
});

function customer(Kernel $kernel, string $email): Customer
{
    $id = (string) $kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = $kernel->orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

/** One of $customer's orders, put in $status. */
function orderOf(Kernel $kernel, Customer $customer, string $status): string
{
    $id = (string) $kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id = ? ORDER BY id LIMIT 1', [$customer->id])[0]['id'];
    $kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [$status, $id]);

    return $id;
}

function statusOf(Kernel $kernel, string $order): string
{
    return (string) $kernel->orm->connection()->select('SELECT status FROM orders WHERE id = ?', [$order])[0]['status'];
}

function signedIn(Kernel $kernel, Customer $customer): \Tinvar\Testing\Client
{
    return actingAs($customer, new AuthEpochs($kernel->orm)->of($customer->id));
}

it('cancels a placed order of the customer\'s, revalidating its page and the tags its shipping updates read', function () {
    $owner = customer($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Placed->value);

    signedIn($this->kernel, $owner)->action('orders.cancel', order: $order)
        ->assertOk()
        ->assertRevalidated('orders.show')
        ->assertRevalidatedTags(OrderActions::ORDERS_TAG, OrderActions::customerTag($owner->id), OrderActions::orderTag($order))
        ->assertFlash('message');

    expect(statusOf($this->kernel, $order))->toBe(OrderStatus::Cancelled->value);
});

it('refuses an order that has been packed, and leaves it packed', function () {
    $owner = customer($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Packed->value);

    signedIn($this->kernel, $owner)->action('orders.cancel', order: $order)->assertForbidden();

    expect(statusOf($this->kernel, $order))->toBe(OrderStatus::Packed->value);
});

// An order id is in URLs and support emails: knowing one cancels nothing.
it('refuses another customer\'s order, and a visitor who is not signed in', function () {
    $owner = customer($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Placed->value);
    $other = customer($this->kernel, 'customer1@shop.test');

    signedIn($this->kernel, $other)->action('orders.cancel', order: $order)->assertForbidden();
    Testing::client()->action('orders.cancel', order: $order)->assertUnauthenticated();

    expect(statusOf($this->kernel, $order))->toBe(OrderStatus::Placed->value);
});
