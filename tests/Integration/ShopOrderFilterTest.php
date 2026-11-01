<?php

declare(strict_types=1);

/**
 * The shop's orders filters, a backed enum: what the query string may name,
 * which statuses each takes in, and every other value read as all orders.
 */

namespace Tinvar\Tests\Integration\ShopOrderFilter;

use Shop\Database\Models\Orders\OrderStatus;
use Shop\Http\Data\Orders\OrderFilter;

it('reads the filter the query string names, and every other value as all orders', function (mixed $value, OrderFilter $filter) {
    expect(OrderFilter::fromQuery($value))->toBe($filter);
})->with([
    'open' => ['open', OrderFilter::Open],
    'delivered' => ['delivered', OrderFilter::Delivered],
    'cancelled' => ['cancelled', OrderFilter::Cancelled],
    'none' => [null, OrderFilter::All],
    'unknown' => ['shipped', OrderFilter::All],
    'a list' => [['open'], OrderFilter::All],
]);

it('takes in the statuses each filter names, all of them for all', function () {
    expect(OrderFilter::All->statuses())->toBe(OrderStatus::cases())
        ->and(OrderFilter::Open->statuses())->toBe([OrderStatus::Placed, OrderStatus::Packed, OrderStatus::Shipped])
        ->and(OrderFilter::Delivered->statuses())->toBe([OrderStatus::Delivered])
        ->and(OrderFilter::Cancelled->statuses())->toBe([OrderStatus::Cancelled]);
});
