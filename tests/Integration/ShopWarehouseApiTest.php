<?php

declare(strict_types=1);

/**
 * The shop's warehouse stock API: a #[WithoutSession] endpoint its caller
 * authenticates to with a bearer token (ADR 0075). The shop issues it no
 * session -- no Set-Cookie of any kind, no session row -- and a browser's
 * cookie neither signs it in nor is needed.
 */

namespace Tinvar\Tests\Integration\ShopWarehouseApi;

use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Queries\Availability;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\Topics;
use Tinvar\Support\Signer;

const KEY = 'shop-warehouse-api-key-of-32-bytes!!';
const TOKEN = 'warehouse-token-of-at-least-32-characters';

beforeEach(function () {
    $this->kernel = Kernel::fromEnvironment(
        '/tmp/tinvar-shop-warehouse-none.sock',
        key: KEY,
        database: Shop::testDatabase(),
        warehouseToken: TOKEN,
    );
    $this->product = (string) $this->kernel->orm->connection()->select('SELECT id FROM products ORDER BY id LIMIT 1')[0]['id'];
});

/**
 * @param array<string, string> $headers
 * @param array<string, string> $cookies
 */
function put(Kernel $kernel, string $product, int $stock, array $headers = [], array $cookies = []): Response
{
    $body = json_encode(['stock' => $stock], JSON_THROW_ON_ERROR);

    return $kernel->handle(
        new Request('PUT', "/api/warehouse/products/{$product}/stock", [
            'host' => '127.0.0.1',
            'accept' => 'application/json',
            'content-type' => 'application/json',
            ...$headers,
        ], [], ['stock' => $stock], $cookies, remoteAddress: '127.0.0.1'),
        $body,
    );
}

function sessionRows(Kernel $kernel): int
{
    return (int) $kernel->orm->connection()->select('SELECT COUNT(*) AS n FROM sessions')[0]['n'];
}

function stockOf(Kernel $kernel, string $product): int
{
    return (int) $kernel->orm->connection()->select('SELECT stock FROM products WHERE id = ?', [$product])[0]['stock'];
}

it('sets a product\'s stock for the warehouse\'s token, and issues no session', function () {
    $rows = sessionRows($this->kernel);

    $response = put($this->kernel, $this->product, 41, ['authorization' => 'Bearer ' . TOKEN]);

    expect($response->status)->toBe(200)
        ->and(json_decode($response->body(), true))->toMatchArray(['product' => $this->product, 'state' => 'in_stock'])
        ->and(stockOf($this->kernel, $this->product))->toBe(41)
        // No session cookie, no CSRF cookie, nothing per visitor at all.
        ->and($response->cookies())->toBe([])
        ->and($response->header('set-cookie'))->toBeNull()
        ->and(sessionRows($this->kernel))->toBe($rows);
});

// ADR 0075: a write outside an action invalidates what it declares. The
// catalog's availability reads the tag; every page showing it is told.
it('tells every page showing stock, and drops every hold on it, once it has set one', function () {
    $hub = new MemoryHub();
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-warehouse-none.sock', key: KEY, database: Shop::testDatabase(), hub: $hub, warehouseToken: TOKEN);
    $before = $kernel->kernel()->sharedTags?->stamp([Availability::TAG], '');

    expect(put($kernel, $this->product, 9, ['authorization' => 'Bearer ' . TOKEN])->status)->toBe(200)
        ->and(array_column($hub->published, 'topic'))->toContain(new Topics(new Signer(KEY, Topics::PURPOSE))->for('', Availability::TAG))
        ->and($kernel->kernel()->sharedTags?->stamp([Availability::TAG], ''))->not->toBe($before);
});

it('tells nobody when it was refused', function () {
    $hub = new MemoryHub();
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-warehouse-none.sock', key: KEY, database: Shop::testDatabase(), hub: $hub, warehouseToken: TOKEN);

    expect(put($kernel, $this->product, 9)->status)->toBe(401)
        ->and($hub->published)->toBe([]);
});

it('refuses a wrong token or none with a 401, and issues no session for that either', function (?string $authorization) {
    $before = stockOf($this->kernel, $this->product);
    $headers = $authorization === null ? [] : ['authorization' => $authorization];

    $response = put($this->kernel, $this->product, 3, $headers);

    expect($response->status)->toBe(401)
        ->and(stockOf($this->kernel, $this->product))->toBe($before)
        ->and($response->cookies())->toBe([]);
})->with([
    'none' => [null],
    'another' => ['Bearer ' . str_repeat('x', 40)],
    'the token, not as a bearer' => [TOKEN],
]);

// A signed-in customer's browser cookie is no credential here, and the
// shop neither reads nor replaces it.
it('is not signed in by a customer\'s session cookie', function () {
    $id = (string) $this->kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];
    $customer = $this->kernel->orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);
    $cookies = $this->kernel->signedIn($customer, new Request('GET', '/', ['host' => '127.0.0.1']));

    $response = put($this->kernel, $this->product, 5, [], $cookies);

    expect($response->status)->toBe(401)
        ->and($response->cookies())->toBe([]);
});

it('takes no token at all when the shop was given none', function () {
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-warehouse-none.sock', key: KEY, database: Shop::testDatabase(), warehouseToken: '');

    expect(put($kernel, $this->product, 3, ['authorization' => 'Bearer '])->status)->toBe(401);
});

// Everything else still has a session issued, as before.
it('still issues a session to a page request', function () {
    $response = $this->kernel->handle(new Request('GET', '/products/' . $this->product . '/stock', ['host' => '127.0.0.1', 'accept' => 'application/json'], remoteAddress: '127.0.0.1'));

    expect($response->status)->toBe(200)
        ->and(array_keys($response->cookies()))->toContain(Kernel::SESSION_COOKIE);
});
