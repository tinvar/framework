<?php

declare(strict_types=1);

/**
 * The testing layer (Tinvar\Testing, ADR 0043) against the shop: pages
 * without the renderer, queries and actions called in PHP, the fakes, and
 * -- where the shop can be built -- pages through the real renderer.
 *
 * Each test has a database of its own, copied from a template built once
 * (TestDatabase), so they run in any order and in parallel.
 */

namespace Tinvar\Tests\Integration\ShopTestingLayer;

use PHPUnit\Framework\AssertionFailedError;
use Shop\Actions\OrderActions;
use Shop\Data\AvailabilityData;
use Shop\Data\ShippingUpdatesData;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Mail\OrderConfirmation;
use Shop\Models\Customer;
use Shop\Models\Order;
use Shop\Models\Product;
use Shop\Pages\Catalog;
use Shop\Pages\Errors\ShopError;
use Shop\Pages\Orders;
use Shop\Pages\SignIn;
use Shop\Queries\Availability;
use Shop\Queries\ShippingUpdates;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Data\Encoder;
use Tinvar\Jobs\Envelope;
use Tinvar\Mail\SendMail;
use Tinvar\Orm\Orm;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Fakes\SentMail;
use Tinvar\Testing\ModelLeaks;
use Tinvar\Testing\RendererPool;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\action;
use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\expectQueries;
use function Tinvar\Testing\get;
use function Tinvar\Testing\query;
use function Tinvar\Testing\render;
use function Tinvar\Testing\testing;

const KEY = 'shop-testing-layer-key-of-32-bytes!';

/** The fakes the current test's shop was built with. */
final class Fakes
{
    public static MailFake $mail;
    public static QueueFake $queue;
    public static HubFake $hub;
}

beforeEach(function () {
    $database = Shop::testDatabase();
    Fakes::$mail = new MailFake();
    Fakes::$hub = new HubFake();
    // Built on the pool's socket directory, so render() can start it; a test
    // that never renders never starts Node.
    $pool = RendererPool::for(Shop::entry(), \dirname(Shop::root(), 2));

    $kernel = null;
    Fakes::$queue = new QueueFake(new Signer(KEY, Envelope::PURPOSE));
    $kernel = Kernel::fromEnvironment(
        $pool->path,
        key: KEY,
        database: $database,
        hub: Fakes::$hub,
        mailTransport: Fakes::$mail,
        mailRenderer: Fakes::$mail,
        queue: Fakes::$queue,
    );
    Testing::use($kernel, $pool);
});

afterEach(function () {
    Testing::forget();
});

function orm(): Orm
{
    return testing()->app->kernel()->orm;
}

function customer(string $email = DemoAccount::EMAIL): Customer
{
    $id = (string) orm()->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = orm()->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

function product(bool $inStock = true): Product
{
    $id = (string) orm()->connection()->select('SELECT id FROM products WHERE stock ' . ($inStock ? '> 0' : '= 0') . ' LIMIT 1')[0]['id'];
    $product = orm()->find(Product::class, $id);
    \assert($product instanceof Product);

    return $product;
}

function orderOf(Customer $customer): Order
{
    $id = (string) orm()->connection()->select('SELECT id FROM orders WHERE customer_id = ? ORDER BY placed_at DESC LIMIT 1', [$customer->id])[0]['id'];
    $order = orm()->find(Order::class, $id);
    \assert($order instanceof Order);

    return $order;
}

// ------------------------------------------------- pages, no renderer

it('asserts what a page sent to React, its layout\'s too, through the whole request path', function () {
    get('/')
        ->assertOk()
        ->assertPage(Catalog::class, fn (AssertablePage $page) => $page
            ->has('products', 24, fn (AssertablePage $product) => $product
                ->where('id', fn (string $id): bool => Uuid::isValid($id))
                ->has('name')
                ->etc())
            ->missing('stock')
            ->layout('shop', fn (AssertablePage $layout) => $layout->where('customer', null)));
});

it('fails a path naming a field the page\'s Data class does not have, as that', function () {
    expect(fn () => get('/')->assertPage(Catalog::class, fn (AssertablePage $page) => $page->has('items')))
        ->toThrow(AssertionFailedError::class, "has no field 'items'");
});

it('holds a scope to every field in it, unless the test says etc()', function () {
    expect(fn () => get('/')->assertPage(Catalog::class, fn (AssertablePage $page) => $page
        ->has('products', 24, fn (AssertablePage $product) => $product->has('name'))))
        ->toThrow(AssertionFailedError::class, 'did not assert');
});

it('acts as a signed-in customer through the session the application makes for them', function () {
    $demo = customer();

    actingAs($demo)->get('/orders')
        ->assertOk()
        ->assertPage(Orders::class, fn (AssertablePage $page) => $page
            ->where('status', 'all')
            ->has('orders.items')
            ->deferred('summary')
            ->loadDeferred('summary', fn (AssertablePage $summary) => $summary
                ->has('value', fn (AssertablePage $value) => $value->where('orders', fn (int $n): bool => $n > 0)->etc())
                ->etc())
            ->layout('shop', fn (AssertablePage $layout) => $layout
                ->has('customer', fn (AssertablePage $customer) => $customer
                    ->where('email', DemoAccount::EMAIL)
                    ->where('name', DemoAccount::NAME)
                    ->has('id')
                    ->has('avatarUrl'))));
});

it('re-runs a page as a partial reload and asserts what was sent again', function () {
    actingAs(customer())->get('/orders')
        ->assertPage(Orders::class, fn (AssertablePage $page) => $page
            ->reloadOnly('orders', fn (AssertablePage $reloaded) => $reloaded->has('orders.items')->etc()));
});

it('reads a refusal before any loader ran: the redirect, and the error page', function () {
    get('/orders')->assertRedirect('/sign-in?next=%2Forders');
    testing()->navigate('/orders')->assertRedirect('/sign-in?next=%2Forders');
    get('/products/' . Uuid::v7())->assertErrorPage(404, ShopError::class);
});

it('posts a form as a browser does without script, and follows it back to its errors', function () {
    get('/sign-in')->then()
        ->submit('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => 'not-it', 'next' => '/orders'], '/sign-in?next=%2Forders')
        ->assertRedirect('/sign-in?next=%2Forders')
        ->follow()
        ->assertPage(SignIn::class, fn (AssertablePage $page) => $page->hasFormErrors(['password'])->missingFlash('message'));
});

it('signs in through a form, and carries the session it was given on to the next page', function () {
    get('/sign-in')->then()
        ->submit('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/orders'], '/sign-in')
        ->assertRedirect('/orders')
        ->follow()
        ->assertPage(Orders::class);
});

it('holds a page to a budget of statements', function () {
    get('/')->assertQueryBudget(3);

    expect(fn () => get('/')->assertQueryBudget(0))->toThrow(AssertionFailedError::class, 'over its budget of 0');
});

// ------------------------------------------------------- direct calls

it('calls a query in PHP, as its owner, and hands back its Data object', function () {
    $demo = customer();
    $order = orderOf($demo);

    actingAs($demo)->query(ShippingUpdates::class, order: $order)
        ->assertOk()
        ->assertData(fn (ShippingUpdatesData $updates): bool => $updates->events !== [] && $updates->events[array_key_last($updates->events)]->id === 'placed');
});

it('refuses a query to anyone the policy refuses, and a row out of scope as not found', function () {
    $order = orderOf(customer());

    actingAs(customer('customer1@shop.test'))->query(ShippingUpdates::class, order: $order)->assertForbidden();
    query(ShippingUpdates::class, order: $order)->assertForbidden();
    actingAs(customer())->query('orders.shipments', order: Uuid::v7())->assertNotFound();
});

it('runs a batch query as a render\'s call would, by name or class', function () {
    $product = product();

    query(Availability::class, product: $product)
        ->assertData(fn (AvailabilityData $availability): bool => $availability->product === $product->id);
    query('products.availability', product: $product)
        ->assertValue(new Encoder()->encode(Availability::of($product)));
});

it('calls an action in PHP: its redirect, the tags it invalidated, and what it queued', function () {
    $demo = customer();
    $product = product();

    actingAs($demo)->action([OrderActions::class, 'place'], product: $product, quantity: 2)
        ->assertRedirect()
        ->assertRevalidatedTags(OrderActions::ORDERS_TAG, OrderActions::customerTag($demo->id));

    // Queued in the order's transaction, and opened as a worker opens it.
    Fakes::$queue->assertPushed(
        SendMail::class,
        fn (SendMail $job, Envelope $envelope): bool => $job->mailable instanceof OrderConfirmation && $envelope->tenant === '',
        queue: SendMail::QUEUE,
    );
    // Every other client holding a page that read the tags is told, once
    // the order committed.
    Fakes::$hub->assertInvalidated(OrderActions::ORDERS_TAG, OrderActions::customerTag($demo->id));
    Fakes::$mail->assertNothingSent();
});

it('sends the queued confirmation from the worker, to the customer, from its template', function () {
    $demo = customer();
    actingAs($demo)->action('orders.place', product: product(), quantity: 1)->assertRedirect();

    $lines = [];
    $output = new Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line) use (&$lines): void {
        $lines[] = $line;
    });
    expect(testing()->app->console->run('queue:work', ['--stop-when-empty'], $output))->toBe(0, implode("\n", $lines));

    Fakes::$mail->assertSentCount(1)
        ->assertSentTo(DemoAccount::EMAIL, OrderConfirmation::TEMPLATE)
        ->assertSent(OrderConfirmation::TEMPLATE, fn (SentMail $mail): bool => str_starts_with($mail->message->subject, 'Order ')
            && ($mail->props['customerName'] ?? null) === DemoAccount::NAME);
});

it('reads an action\'s refusals as its client would: unauthenticated, invalid, validate-only', function () {
    $product = product();

    action([OrderActions::class, 'place'], product: $product, quantity: 1)->assertUnauthenticated();
    actingAs(customer())->action([OrderActions::class, 'place'], product: $product, quantity: 0)->assertInvalid(['quantity']);
    actingAs(customer())->action([OrderActions::class, 'place'], product: product(inStock: false), quantity: 1)
        ->assertInvalid(['quantity' => 'sold out']);

    $before = (int) orm()->connection()->select('SELECT COUNT(*) AS n FROM orders')[0]['n'];
    actingAs(customer())->precognitive()->action([OrderActions::class, 'place'], product: $product, quantity: 2)->assertOk();
    actingAs(customer())->precognitive('quantity')->action([OrderActions::class, 'place'], product: $product, quantity: 99)->assertInvalid(['quantity']);

    expect((int) orm()->connection()->select('SELECT COUNT(*) AS n FROM orders')[0]['n'])->toBe($before, 'validate-only ran no body');
    Fakes::$queue->assertNothingPushed();
});

it('counts the statements a piece of work issues', function () {
    $orm = orm();
    $ids = array_column($orm->connection()->select('SELECT id FROM orders LIMIT 5'), 'id');
    $orm->flush();

    expectQueries(2, function () use ($orm, $ids): void {
        $orders = $orm->whereIn(Order::class, 'id', $ids);
        foreach ($orders as $order) {
            \count($order->lines);
        }
    });
});

// A client signs anyone in without a password, and so does the shop's seam
// for it: neither runs where the environment says production.
it('refuses to run where the environment says production', function () {
    $app = testing()->app;
    $demo = customer();
    putenv('TINVAR_ENV=production');

    try {
        expect(fn () => Testing::use($app))->toThrow(\LogicException::class, 'TINVAR_ENV=production')
            ->and(fn () => $app->signedIn($demo))->toThrow(\LogicException::class, 'TINVAR_ENV=production');
    } finally {
        putenv('TINVAR_ENV');
    }
});

// ------------------------------------------------- model-leak check

it('finds a column no Data object declares in what was sent', function () {
    $demo = customer();
    $orm = orm();
    $snapshot = ModelLeaks::snapshot($orm);
    $leaked = array_values(array_filter($snapshot, static fn (array $leak): bool => $leak['column'] === 'password_hash'));

    expect($leaked)->toHaveCount(1)
        ->and(ModelLeaks::find($snapshot, '<p>' . DemoAccount::NAME . '</p>'))->toBe([])
        ->and(ModelLeaks::find($snapshot, '"hash":' . json_encode($demo->passwordHash)))->toBe($leaked)
        // As a payload row spells it, and as HTML does.
        ->and(ModelLeaks::find($snapshot, htmlspecialchars($demo->passwordHash)))->toBe($leaked);
});

// --------------------------------------------- pages, real renderer

describe('through the real renderer', function () {
    beforeEach(function () {
        $unbuilt = Shop::ensureBuilt();
        if ($unbuilt !== null) {
            $this->markTestSkipped($unbuilt);
        }
    });

    it('renders the catalog: its text, its markup, its client components, one query tick, no model leak', function () {
        render('/')
            ->assertOk()
            ->assertSee('Pellwick Supply')
            ->assertDontSee('password')
            ->assertSelector('[data-availability]', count: 24)
            ->assertClientComponent('ShopLink')
            ->assertQueryTicks(1)
            // The session's row, and the products: the 24 availability calls
            // resolve theirs from the identity map the loader filled.
            ->assertQueryBudget(2);
    });

    it('renders a customer\'s own order, as them', function () {
        $demo = customer();
        $order = orderOf($demo);

        actingAs($demo)->render("/orders/{$order->id}")
            ->assertOk()
            ->assertSee($order->number)
            ->assertClientComponent('DeferredBoundary');
    });
});
