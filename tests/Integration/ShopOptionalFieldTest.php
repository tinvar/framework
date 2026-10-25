<?php

declare(strict_types=1);

/**
 * The shop's #[Optional] field (ADR 0045): a signed-in customer's own orders
 * of a product, which the product page sends with no render until its
 * "Show your orders" names `page.yourOrders` in a partial reload.
 *
 * "Lazy, not hidden": any client allowed on the page may ask, so what is
 * held to account here is what asking gets -- the asker's own orders, none
 * for a visitor, never another customer's -- and that asking runs the
 * page's whole chain first. Through the testing layer (ADR 0043): the
 * Kernel, the session, the binder, #[Authorize], the loader and the
 * encoder, without the renderer.
 */

namespace Tinvar\Tests\Integration\ShopOptionalField;

use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Pages\ProductPage;
use Tinvar\Bench\Shop;
use Tinvar\Data\Encoder;
use Tinvar\Jobs\Envelope;
use Tinvar\Orm\Orm;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\testing;

const KEY = 'shop-optional-field-key-of-32-bytes!';

/** What a partial reload sends back to ask for the field: the page's view, and the field's path. */
const ASK = ['rsc' => '1', 'accept' => 'text/x-component', 'x-page' => 'shop/product', 'x-only' => 'page.yourOrders'];

beforeEach(function () {
    $mail = new MailFake();
    Testing::use(Kernel::fromEnvironment(
        '/tmp/tinvar-shop-optional-none.sock',
        key: KEY,
        database: Shop::testDatabase(),
        hub: new HubFake(),
        mailTransport: $mail,
        mailRenderer: $mail,
        queue: new QueueFake(new Signer(KEY, Envelope::PURPOSE)),
    ));
});

afterEach(function () {
    Testing::forget();
});

function orm(): Orm
{
    return testing()->app->kernel()->orm;
}

function customer(string $email): Customer
{
    $id = (string) orm()->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = orm()->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

/**
 * A product $customer has ordered, and one they have not.
 *
 * @return array{string, string}
 */
function productsOf(Customer $customer): array
{
    $ordered = (string) orm()->connection()->select(
        'SELECT l.product_id FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE o.customer_id = ? ORDER BY o.placed_at DESC LIMIT 1',
        [$customer->id],
    )[0]['product_id'];
    $not = (string) orm()->connection()->select(
        'SELECT id FROM products WHERE id NOT IN (SELECT l.product_id FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE o.customer_id = ?) LIMIT 1',
        [$customer->id],
    )[0]['id'];

    return [$ordered, $not];
}

/**
 * $customer's orders with $product in them, as the field encodes them:
 * newest first, at most the page's ten -- read here without the page.
 *
 * @return list<array{id: string, number: string, status: string, placedAt: string, quantity: int}>
 */
function ordersOf(Customer $customer, string $product): array
{
    return array_map(
        static fn (array $row): array => [
            'id' => (string) $row['id'],
            'number' => (string) $row['number'],
            'status' => (string) $row['status'],
            'placedAt' => (string) $row['placed_at'],
            'quantity' => (int) $row['quantity'],
        ],
        orm()->connection()->select(
            'SELECT o.id, o.number, o.status, o.placed_at, SUM(l.quantity) AS quantity FROM orders o JOIN order_lines l ON l.order_id = o.id '
            . 'WHERE o.customer_id = ? AND l.product_id = ? GROUP BY o.id ORDER BY o.placed_at DESC, o.id DESC LIMIT ' . ProductPage::YOUR_ORDERS,
            [$customer->id, $product],
        ),
    );
}

describe('a customer\'s orders of a product', function () {
    it('are left out of the page on every render that does not name them: its resolver never runs', function () {
        $demo = customer(DemoAccount::EMAIL);
        [$ordered] = productsOf($demo);

        // A document, and a client navigation's payload.
        foreach ([actingAs($demo)->get("/products/{$ordered}"), actingAs($demo)->navigate("/products/{$ordered}")] as $response) {
            $response->assertOk()->assertPage(ProductPage::class, fn (AssertablePage $page) => $page
                ->unrequested('yourOrders')
                ->where('yourOrders', [Encoder::OPTIONAL_KEY => 'page.yourOrders'])
                ->where('signedIn', true)
                ->etc());
        }

        // Asking for the page segment whole is not asking for the field.
        actingAs($demo)->get("/products/{$ordered}")->assertPage(ProductPage::class, fn (AssertablePage $page) => $page
            ->reloadOnly('page', fn (AssertablePage $reloaded) => $reloaded->unrequested('yourOrders')->etc()));
    });

    it('are sent to the partial reload that names them, and are the asker\'s own', function () {
        $demo = customer(DemoAccount::EMAIL);
        [$ordered, $not] = productsOf($demo);
        $expected = ordersOf($demo, $ordered);

        actingAs($demo)->get("/products/{$ordered}")->assertPage(ProductPage::class, fn (AssertablePage $page) => $page
            ->unrequested('yourOrders')
            ->reloadOnly('yourOrders', fn (AssertablePage $reloaded) => $reloaded
                ->where('yourOrders', $expected)
                ->etc()));

        expect($expected)->not->toBe([]);

        // A product they never ordered: asked for, and empty.
        actingAs($demo)->get("/products/{$not}")->assertPage(ProductPage::class, fn (AssertablePage $page) => $page
            ->reloadOnly('yourOrders', fn (AssertablePage $reloaded) => $reloaded->where('yourOrders', [])->etc()));
    });
});

describe('asking for them', function () {
    // The field is "lazy, not hidden": the same request from another
    // customer, or from nobody, must not read the first one's orders.
    it('reads only the asker\'s orders: another customer gets theirs, a visitor none', function () {
        $demo = customer(DemoAccount::EMAIL);
        // A product someone else has ordered too, so both have orders the
        // one request could mix up.
        $pair = orm()->connection()->select(
            'SELECT l1.product_id AS product, c.email AS other FROM orders o1 JOIN order_lines l1 ON l1.order_id = o1.id '
            . 'JOIN order_lines l2 ON l2.product_id = l1.product_id JOIN orders o2 ON o2.id = l2.order_id '
            . 'JOIN customers c ON c.id = o2.customer_id WHERE o1.customer_id = ? AND o2.customer_id != ? LIMIT 1',
            [$demo->id, $demo->id],
        )[0];
        $ordered = (string) $pair['product'];
        $other = customer((string) $pair['other']);
        $demoNumbers = array_column(ordersOf($demo, $ordered), 'number');

        $asked = actingAs($other)->page("/products/{$ordered}", ASK)->assertOk();
        $theirs = $asked->recorded()->encoded['page']['yourOrders'];

        expect($asked->header('x-partial'))->toBe('1')
            ->and($theirs)->not->toBe([])
            ->and($theirs)->toBe(ordersOf($other, $ordered))
            ->and(array_intersect(array_column($theirs, 'number'), $demoNumbers))->toBe([]);

        $visitor = testing()->page("/products/{$ordered}", ASK)->assertOk();
        expect($visitor->recorded()->encoded['page']['yourOrders'])->toBe([]);
    });

    // Naming the field never lets a client choose which of the page's
    // checks run: the binder still refuses a product that is not there,
    // before any loader -- let alone the field -- runs.
    it('runs the page\'s chain first: a product that is not there is a 404 whatever is named', function () {
        actingAs(customer(DemoAccount::EMAIL))->page('/products/' . Uuid::v7(), ASK)->assertNotFound();
        actingAs(customer(DemoAccount::EMAIL))->page('/products/not-a-product', ASK)->assertNotFound();
    });

    // The set of fields is the server's: a path naming anything the Data
    // class does not mark #[Optional] resolves nothing a render would not
    // send anyway -- not a column the model has and no Data declares.
    it('can ask for nothing the page does not mark optional', function () {
        $demo = customer(DemoAccount::EMAIL);
        [$ordered] = productsOf($demo);

        $asked = actingAs($demo)->page("/products/{$ordered}", [...ASK, 'x-only' => 'page.product.stock,page.secret']);

        expect(array_keys($asked->recorded()->encoded['page']))->toBe(['product', 'signedIn', 'signInHref', 'yourOrders'])
            ->and($asked->recorded()->encoded['page']['product'])->not->toHaveKey('stock')
            ->and($asked->recorded()->encoded['page']['yourOrders'])->toBe([Encoder::OPTIONAL_KEY => 'page.yourOrders']);
    });

    // Only a partial reload of this very page asks: one that says it holds
    // another page is answered in full, the field left out.
    it('is a partial reload of this page, or nothing', function () {
        $demo = customer(DemoAccount::EMAIL);
        [$ordered] = productsOf($demo);

        $other = actingAs($demo)->page("/products/{$ordered}", [...ASK, 'x-page' => 'shop/catalog']);

        expect($other->header('x-partial'))->toBeNull()
            ->and($other->recorded()->encoded['page']['yourOrders'])->toBe([Encoder::OPTIONAL_KEY => 'page.yourOrders']);
    });
});
