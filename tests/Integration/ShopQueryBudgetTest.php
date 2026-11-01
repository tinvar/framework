<?php

declare(strict_types=1);

/**
 * `#[QueryBudget]` on the shop's page tests (ADR 0095): the catalog and a
 * customer's orders, each held to the statements its loaders take today,
 * so a loader whose batching regresses fails here.
 */

namespace Tinvar\Tests\Integration\ShopQueryBudget;

use PHPUnit\Framework\AssertionFailedError;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Tinvar\Bench\Shop;
use Tinvar\Testing\QueryBudget;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\get;
use function Tinvar\Testing\testing;

const KEY = 'shop-query-budget-key-of-32-bytes!!';

beforeEach(function () {
    Testing::use(Kernel::fromEnvironment(sys_get_temp_dir() . '/tinvar-no-renderer', key: KEY, database: Shop::testDatabase()));
});

afterEach(function () {
    Testing::forget();
});

it('lists the catalog within its budget', #[QueryBudget(4)] function () {
    get('/')->assertOk();
});

it('lists a customer\'s orders within theirs', #[QueryBudget(11)] function () {
    $orm = testing()->app->kernel()->orm;
    $customer = $orm->find(Customer::class, (string) $orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id']);
    \assert($customer instanceof Customer);

    actingAs($customer)->get('/orders')->assertOk();
});

it('fails a page over its budget', #[QueryBudget(0)] function () {
    expect(fn () => get('/'))->toThrow(AssertionFailedError::class, 'over its budget of 0');
});
