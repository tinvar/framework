<?php

declare(strict_types=1);

/**
 * The seeded orders agree with Shipping: each is at the step its age has
 * reached, so the first run of AdvanceShipments after a seed moves none of
 * them, and sends no "your order moved on" for an order nobody touched.
 */

use Carbon\CarbonImmutable;
use Shop\Database\Models\Orders\OrderStatus;
use Shop\Database\Seeders\Seeder;
use Shop\Support\Shipping;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\PrivateDirectory;

it('seeds every order at the shipping step its age has reached', function () {
    $now = CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC');
    $database = PrivateDirectory::create('tinvar-seeder') . '/shop.sqlite';
    $connection = PdoConnection::sqlite($database, 'shop', create: true);
    new Seeder($connection, now: $now->getTimestamp())->run(customers: 40);

    $wrong = [];
    $rows = $connection->select('SELECT number, status, placed_at FROM orders');
    foreach ($rows as $row) {
        $status = OrderStatus::from((string) $row['status']);
        if ($status === OrderStatus::Cancelled) {
            continue;
        }
        $placed = CarbonImmutable::parse((string) $row['placed_at'], 'UTC');
        $expected = OrderStatus::Placed;
        foreach (Shipping::STEPS as $step) {
            if (Shipping::at($placed, $step) <= $now) {
                $expected = $step;
            }
        }
        if ($status !== $expected) {
            $wrong[] = "{$row['number']}: {$status->value}, placed {$placed->diffInHours($now)}h ago, should be {$expected->value}";
        }
    }

    expect(\count($rows))->toBeGreaterThan(40)
        ->and($wrong)->toBe([]);
});
