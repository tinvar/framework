<?php

declare(strict_types=1);

/**
 * The shop's Products resource (ADR 0152) through its actions: what staff
 * who keep the catalog save is checked against what the products table
 * holds to -- a price above nothing, a catalog number of its own -- and
 * refused as a field's error, never a 500 from the database.
 */

namespace Tinvar\Tests\Integration\ShopProductResource;

use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Catalog\Product;
use Shop\Http\Resources\Catalog\ProductResource;
use Shop\Kernel;
use Shop\Support\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Orm\Orm;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;

const KEY = 'shop-product-resource-key-of-32-bytes';

beforeEach(function () {
    $this->database = Shop::testDatabase();
    Testing::use(Kernel::fromEnvironment('/tmp/tinvar-shop-product-resource-none.sock', key: KEY, database: $this->database));
});

afterEach(function () {
    Testing::forget();
});

function orm(): Orm
{
    return Testing::client()->app->kernel()->orm;
}

function staff(): Customer
{
    $id = (string) orm()->connection()->select('SELECT id FROM customers WHERE email = ?', [StaffAccount::EMAIL])[0]['id'];
    $customer = orm()->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

/** @return list<Product> the first two products by catalog number */
function products(): array
{
    $rows = orm()->connection()->select(
        'SELECT id FROM products ORDER BY sku LIMIT 2',
    );

    return array_map(static function (array $row): Product {
        $product = orm()->find(Product::class, (string) $row['id']);
        \assert($product instanceof Product);

        return $product;
    }, $rows);
}

/**
 * The product's form as staff would post it, with $override typed over it.
 *
 * @param array<string, string> $override
 * @return array<string, string>
 */
function values(Product $product, array $override = []): array
{
    return [
        'name' => $product->name,
        'sku' => $product->sku,
        'category' => $product->category,
        'summary' => $product->summary,
        'description' => $product->description,
        'priceCents' => (string) $product->priceCents,
        'stock' => (string) $product->stock,
        ...$override,
    ];
}

it('saves a product staff changed within what the table holds to, keeping its own catalog number', function () {
    [$product] = products();

    actingAs(staff())->action([ProductResource::class, 'update'], record: $product->id, values: values($product, ['priceCents' => '7']))
        ->assertRedirect();
});

it('refuses a price of nothing as the price field\'s error, as the table would', function () {
    [$product] = products();

    actingAs(staff())->action([ProductResource::class, 'update'], record: $product->id, values: values($product, ['priceCents' => '0']))
        ->assertInvalid(['values.priceCents']);
});

it('refuses another product\'s catalog number as the field\'s error, on a changed product and a new one', function () {
    [$product, $other] = products();

    actingAs(staff())->action([ProductResource::class, 'update'], record: $product->id, values: values($product, ['sku' => $other->sku]))
        ->assertInvalid(['values.sku']);
    actingAs(staff())->action([ProductResource::class, 'store'], values: values($product, ['name' => 'Another']))
        ->assertInvalid(['values.sku']);
});
