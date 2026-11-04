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
 * The restock bulk action, as staff run it on the products list. Through
 * the client's own action(), whose first parameter is the action, so a
 * field named `action` cannot be passed to it by name: its body here.
 *
 * @param list<string> $ids
 */
function restock(array $ids): \Tinvar\Testing\ActionResponse
{
    $client = actingAs(staff());
    $call = \Closure::bind(function (array $ids): \Tinvar\Testing\ActionResponse {
        $kernel = $this->app->kernel();
        $name = self::actionName($kernel, [ProductResource::class, 'bulk']);
        $compiled = $kernel->actions->get($name);
        $pattern = $compiled->host->pattern ?? $kernel->entry->panelDomainFor($compiled->panels, $compiled->class);
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'];
        if ($kernel->domains !== null && $pattern !== null) {
            $headers['host'] = (string) preg_replace('#^https?://#', '', $kernel->domains->origin($pattern));
        }
        $request = $this->request('POST', \Tinvar\Http\Endpoints\ActionEndpoint::PREFIX . $name, $headers);
        $body = json_encode(['action' => 'restock', 'ids' => $ids], JSON_THROW_ON_ERROR);
        $failure = null;
        try {
            $response = $this->within(static fn () => $kernel->actions_->handle($request, $body), null);
        } catch (\Throwable $e) {
            $failure = $e;
            $response = new \Tinvar\Http\ExceptionHandler(static function (): void {})->handle($e, $request);
        } finally {
            $kernel->scope->reset();
        }

        return new \Tinvar\Testing\ActionResponse($name, $response, $failure);
    }, $client, \Tinvar\Testing\Client::class);

    return $call($ids);
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

// A restock adds to what is there, so ticked again and again it would pass
// any count the warehouse or a stock file may set. It stops at the most a
// product holds, all or nothing: one product past it, and none is changed.
it('restocks in bulk up to the most a product holds, and none past it', function () {
    [$product, $other] = products();
    $stock = static fn (Product $p): int => (int) orm()->connection()->select('SELECT stock FROM products WHERE id = ?', [$p->id])[0]['stock'];
    $set = static function (Product $p, int $n): void {
        orm()->connection()->execute('UPDATE products SET stock = ? WHERE id = ?', [$n, $p->id]);
        // Read again by the action, not from the identity map products() filled.
        Testing::client()->app->kernel()->scope->reset();
    };
    $set($product, ProductResource::MAX_STOCK - ProductResource::RESTOCK_BY + 1);
    $set($other, 3);

    restock([$product->id, $other->id])->assertInvalid(['ids']);
    expect($stock($product))->toBe(ProductResource::MAX_STOCK - ProductResource::RESTOCK_BY + 1)
        ->and($stock($other))->toBe(3);

    $set($product, ProductResource::MAX_STOCK - ProductResource::RESTOCK_BY);
    restock([$product->id, $other->id])->assertOk();
    expect($stock($product))->toBe(ProductResource::MAX_STOCK)
        ->and($stock($other))->toBe(3 + ProductResource::RESTOCK_BY);

    // The form holds to the same most, as a stock file and the warehouse do.
    actingAs(staff())->action([ProductResource::class, 'update'], record: $other->id, values: values($other, ['stock' => (string) (ProductResource::MAX_STOCK + 1)]))
        ->assertInvalid(['values.stock']);
});
