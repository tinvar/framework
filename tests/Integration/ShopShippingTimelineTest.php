<?php

declare(strict_types=1);

/**
 * An order's shipping timeline says when each step happened: the moment
 * the order moved, recorded as it moved -- by the schedule, by staff, by
 * the customer cancelling -- and no time at all where none was recorded,
 * never one worked out from when the order was placed.
 */

namespace Tinvar\Tests\Integration\ShopShippingTimeline;

use Carbon\CarbonImmutable;
use Shop\Console\Tasks\Fulfilment\AdvanceShipments;
use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Orders\Order;
use Shop\Http\Channels\Orders\OrderChanges;
use Shop\Http\Data\Orders\ShipmentEventData;
use Shop\Http\Data\Orders\ShippingUpdatesData;
use Shop\Http\Queries\Orders\ShippingUpdates;
use Shop\Kernel;
use Shop\Support\DemoAccount;
use Tinvar\Bench\Shop;
use Tinvar\Orm\Orm;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;

const KEY = 'shop-shipping-timeline-key-of-32-bytes';

beforeEach(function () {
    Testing::use(Kernel::fromEnvironment('/tmp/tinvar-shop-shipping-timeline-none.sock', key: KEY, database: Shop::testDatabase()));
});

afterEach(function () {
    Testing::forget();
});

function orm(): Orm
{
    return Testing::client()->app->kernel()->orm;
}

function demo(): Customer
{
    $id = (string) orm()->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];
    $customer = orm()->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

/** A new order of the demo customer's, placed now. */
function placed(): Order
{
    $product = (string) orm()->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id'];
    $location = (string) actingAs(demo())->action('orders.place', product: $product, quantity: 1)->assertRedirect()->response->header('location');

    return order(substr($location, \strlen('/orders/')));
}

function order(string $id): Order
{
    Testing::client()->app->kernel()->scope->reset();
    $order = orm()->find(Order::class, $id);
    \assert($order instanceof Order);

    return $order;
}

/** @return array<string, ?string> each event's moment, by its id, to the second */
function timeline(Order $order): array
{
    $updates = null;
    actingAs(demo())->query(ShippingUpdates::class, order: $order)
        ->assertData(static function (ShippingUpdatesData $data) use (&$updates): bool {
            $updates = $data;

            return true;
        });
    \assert($updates instanceof ShippingUpdatesData);

    $times = [];
    foreach ($updates->events as $event) {
        \assert($event instanceof ShipmentEventData);
        $times[$event->id] = $event->at?->utc()->format('Y-m-d H:i:s');
    }

    return $times;
}

it('says each step happened when the order moved, not when its age says it should have', function () {
    $order = placed();
    // The schedule was off for a week: the order moves through every step
    // in the one run that catches up, and that run is when it moved.
    $caughtUp = $order->placedAt->addDays(7)->addMinutes(3);
    new AdvanceShipments()->advance(orm(), new OrderChanges(), $caughtUp);

    $at = $caughtUp->format('Y-m-d H:i:s');
    expect(timeline(order($order->id)))->toBe([
        'delivered' => $at,
        'shipped' => $at,
        'packed' => $at,
        'placed' => $order->placedAt->utc()->format('Y-m-d H:i:s'),
    ]);
});

it('gives the step still to come no time', function () {
    $order = placed();
    $packedAt = $order->placedAt->addHours(7);
    new AdvanceShipments()->advance(orm(), new OrderChanges(), $packedAt);

    expect(timeline(order($order->id)))->toBe([
        'next' => null,
        'packed' => $packedAt->format('Y-m-d H:i:s'),
        'placed' => $order->placedAt->utc()->format('Y-m-d H:i:s'),
    ]);
});

it('says an order was cancelled when it was', function () {
    $order = placed();
    $before = CarbonImmutable::now('UTC')->subSecond();
    actingAs(demo())->action('orders.cancel', order: $order)->assertOk();

    $times = timeline(order($order->id));
    expect(array_keys($times))->toBe(['cancelled', 'placed'])
        ->and($times['cancelled'] >= $before->format('Y-m-d H:i:s'))->toBeTrue()
        ->and($times['cancelled'] <= CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))->toBeTrue();
});

it('gives a step no time where none was recorded, rather than one made up', function () {
    $order = placed();
    // Moved before the shop recorded when: the status alone.
    orm()->connection()->execute("UPDATE orders SET status = 'shipped' WHERE id = ?", [$order->id]);

    expect(timeline(order($order->id)))->toBe([
        'next' => null,
        'shipped' => null,
        'packed' => null,
        'placed' => $order->placedAt->utc()->format('Y-m-d H:i:s'),
    ]);
});
