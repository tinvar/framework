<?php

declare(strict_types=1);

/**
 * The shop's feature flag (ADR 0094): `shipping.estimate` puts the day an
 * order in flight is expected under its next step, read for the customer
 * asking -- on for one, off for another, from the same query.
 */

namespace Tinvar\Tests\Integration\ShopFeatureFlags;

use Shop\Data\ShippingUpdatesData;
use Shop\DemoAccount;
use Shop\Flags;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Models\Order;
use Shop\Queries\ShippingUpdates;
use Tinvar\Bench\Shop;
use Tinvar\Features\FlagRules;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\testing;

const KEY = 'shop-feature-flags-key-of-32-bytes!';

function boot(FlagRules $rules): void
{
    Testing::use(Kernel::fromEnvironment(sys_get_temp_dir() . '/tinvar-no-renderer', key: KEY, database: Shop::testDatabase(), flags: $rules));
}

/** @return array{Customer, Order} the demo customer and an order of theirs not yet delivered */
function inFlight(): array
{
    $orm = testing()->app->kernel()->orm;
    $customer = $orm->find(Customer::class, (string) $orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id']);
    \assert($customer instanceof Customer);
    $orm->connection()->execute("UPDATE orders SET status = 'placed' WHERE id = (SELECT id FROM orders WHERE customer_id = ? ORDER BY placed_at DESC LIMIT 1)", [$customer->id]);
    $order = $orm->find(Order::class, (string) $orm->connection()->select('SELECT id FROM orders WHERE customer_id = ? ORDER BY placed_at DESC LIMIT 1', [$customer->id])[0]['id']);
    \assert($order instanceof Order);

    return [$customer, $order];
}

afterEach(function () {
    Testing::forget();
});

it('shows the expected day where the flag is on for the customer', function () {
    boot(Flags::rules());
    [$customer, $order] = inFlight();

    actingAs($customer)->query(ShippingUpdates::class, order: $order)
        ->assertData(fn (ShippingUpdatesData $updates): bool => str_contains($updates->events[0]->detail, 'Expected by '));
});

it('leaves it out where the flag is off for them, whatever it is for everyone', function () {
    [$customer] = (static function (): array {
        boot(Flags::rules());

        return inFlight();
    })();
    Testing::forget();

    boot(Flags::rules()->forUser(Flags::ShippingEstimate->value, $customer->id, false));
    [$customer, $order] = inFlight();

    actingAs($customer)->query(ShippingUpdates::class, order: $order)
        ->assertData(fn (ShippingUpdatesData $updates): bool => $updates->events[0]->id === 'next'
            && !str_contains($updates->events[0]->detail, 'Expected by '));
});
