<?php

declare(strict_types=1);

/**
 * Query::fake() and Action::fake() against the shop: a fake answers a direct
 * call, a form a page posts and a render alike, in place of the body and
 * only of the body. Its checks -- model resolution, #[Authorize], validation,
 * Precognition -- still run first, and what it returns still goes through
 * the encoder and the rest of the action's path. Nothing it installs
 * outlives its test.
 */

namespace Tinvar\Tests\Integration\ShopFakes;

use Shop\Http\Actions\Orders\OrderActions;
use Shop\Http\Data\Catalog\AvailabilityData;
use Shop\Http\Data\Orders\ShippingUpdatesData;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Orders\Order;
use Shop\Database\Models\Catalog\Product;
use Shop\Http\Queries\Catalog\Availability;
use Shop\Http\Queries\Orders\ShippingUpdates;
use Shop\Http\Requests\Orders\PlaceOrderRequest;
use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionResult;
use Tinvar\Bench\Shop;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\Encoder;
use Tinvar\Http\TestableApplication;
use Tinvar\Jobs\Envelope;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;
use Tinvar\Testing\Action;
use Tinvar\Testing\Fakes\BodyFakes;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Query;
use Tinvar\Testing\RendererPool;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\action;
use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\get;
use function Tinvar\Testing\query;
use function Tinvar\Testing\render;
use function Tinvar\Testing\testing;

const KEY = 'shop-fakes-test-key-of-32-bytes!!!!';

/** What the current test's shop was built with. */
final class Built
{
    public static BodyFakes $fakes;
    public static QueueFake $queue;
}

function shop(?BodyFakes $fakes): Kernel
{
    Built::$queue = new QueueFake(new Signer(KEY, Envelope::PURPOSE));

    return Kernel::fromEnvironment(
        RendererPool::for(Shop::entry(), \dirname(Shop::root(), 2))->path,
        key: KEY,
        database: Shop::testDatabase(),
        queue: Built::$queue,
        standIns: $fakes,
    );
}

beforeEach(function () {
    Built::$fakes = new BodyFakes();
    Testing::use(shop(Built::$fakes), RendererPool::for(Shop::entry(), \dirname(Shop::root(), 2)));
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

function product(): Product
{
    $id = (string) orm()->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id'];
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

function orders(): int
{
    return (int) orm()->connection()->select('SELECT COUNT(*) AS n FROM orders')[0]['n'];
}

// ------------------------------------------------------------- queries

it('answers a query with its fake, handed the order the runner resolved', function () {
    $demo = customer();
    $order = orderOf($demo);
    Query::fake(ShippingUpdates::class, fn (Order $order): ShippingUpdatesData => new ShippingUpdatesData([], "FAKE-{$order->number}"));

    actingAs($demo)->query(ShippingUpdates::class, order: $order)
        ->assertData(fn (ShippingUpdatesData $updates): bool => $updates->events === [] && $updates->tracking === "FAKE-{$order->number}");
});

it('still denies with a fake installed: #[Authorize] and model resolution run before it', function () {
    $order = orderOf(customer());
    $asked = 0;
    Query::fake('orders.shipments', function () use (&$asked): ShippingUpdatesData {
        $asked++;

        return new ShippingUpdatesData([], null);
    });

    actingAs(customer('customer1@shop.test'))->query(ShippingUpdates::class, order: $order)->assertForbidden();
    query(ShippingUpdates::class, order: $order)->assertForbidden();
    actingAs(customer())->query(ShippingUpdates::class, order: Uuid::v7())->assertNotFound();

    expect($asked)->toBe(0);
});

it('still refuses a model a fake returns, at the encoder', function () {
    $demo = customer();
    Query::fake(ShippingUpdates::class, fn (Order $order): Order => $order);

    actingAs($demo)->query(ShippingUpdates::class, order: orderOf($demo))->assertError(QueryError::BAD_RESULT);
});

it('answers a batch query\'s calls with its fake, one by one', function () {
    $product = product();
    Query::fake(Availability::class, fn (Product $product): AvailabilityData => new AvailabilityData($product->id, 'low', 7, 2));

    query('products.availability', product: $product)
        ->assertValue(['product' => $product->id, 'state' => 'low', 'remaining' => 7, 'shipsInDays' => 2]);
});

it('refuses a query the application does not register, and an application built without fakes', function () {
    expect(fn () => Query::fake('orders.nothing', fn (): null => null))->toThrow(\InvalidArgumentException::class, 'orders.nothing');

    Testing::use(shop(null));
    expect(fn () => Query::fake(ShippingUpdates::class, fn (): null => null))->toThrow(\LogicException::class, 'built without fakes');
});

// ------------------------------------------------------------- actions

it('runs an action\'s fake in place of its body, and answers with what it returned', function () {
    $demo = customer();
    $product = product();
    $before = orders();
    Action::fake([OrderActions::class, 'place'], fn (Product $product, PlaceOrderRequest $order): ActionResult => ActionResult::ok(['ordered' => $order->quantity])
        ->withTags(OrderActions::customerTag($product->id))
        ->flash(['message' => "Pretended to order {$product->name}."]));

    actingAs($demo)->action([OrderActions::class, 'place'], product: $product, quantity: 2)
        ->assertOk()
        ->assertRevalidatedTags(OrderActions::customerTag($product->id))
        ->assertFlash('message', "Pretended to order {$product->name}.");

    expect(orders())->toBe($before, 'the body never ran');
    Built::$queue->assertNothingPushed();
});

it('answers an action fake\'s redirect as the action\'s own', function () {
    Action::fake('orders.place', fn (): ActionResult => ActionResult::redirect('/orders')->withTags(OrderActions::ORDERS_TAG));

    actingAs(customer())->action('orders.place', product: product(), quantity: 1)
        ->assertRedirect('/orders')
        ->assertRevalidatedTags(OrderActions::ORDERS_TAG);
});

it('still authenticates, authorizes and validates with a fake installed, and Precognition still stops before it', function () {
    $asked = 0;
    Action::fake([OrderActions::class, 'place'], function () use (&$asked): ActionResult {
        $asked++;

        return ActionResult::ok();
    });
    $product = product();

    action([OrderActions::class, 'place'], product: $product, quantity: 1)->assertUnauthenticated();
    actingAs(customer())->action([OrderActions::class, 'place'], product: Uuid::v7(), quantity: 1)->assertNotFound();
    actingAs(customer())->action([OrderActions::class, 'place'], product: $product, quantity: 0)->assertInvalid(['quantity']);
    actingAs(customer())->precognitive()->action([OrderActions::class, 'place'], product: $product, quantity: 2)->assertOk();

    expect($asked)->toBe(0);
});

it('still refuses another customer\'s account with a fake installed', function () {
    $asked = 0;
    Action::fake('account.setMarketing', function () use (&$asked): ActionResult {
        $asked++;

        return ActionResult::ok();
    });

    actingAs(customer('customer1@shop.test'))->action('account.setMarketing', customer: customer(), list: 'newsletter', subscribed: true)
        ->assertForbidden();
    expect($asked)->toBe(0);
});

it('still refuses a model, and anything but an ActionResult, from an action fake', function () {
    Action::fake('orders.place', fn (Product $product): ActionResult => ActionResult::ok($product));
    $leaked = actingAs(customer())->action('orders.place', product: product(), quantity: 1)->assertStatus(500);

    Action::fake('orders.place', fn (): string => 'done');
    $bare = actingAs(customer())->action('orders.place', product: product(), quantity: 1)->assertStatus(500);

    expect($leaked->failure)->toBeInstanceOf(DataBoundaryException::class)
        ->and($bare->failure)->toBeInstanceOf(ActionException::class);
});

it('answers a form a page posts with the fake, after the form is validated', function () {
    Action::fake('account.signIn', fn (): ActionResult => ActionResult::redirect('/'));

    get('/sign-in')->then()
        ->submit('account.signIn', ['email' => 'nobody@shop.test', 'password' => 'not-it', 'next' => '/orders'], '/sign-in')
        ->assertRedirect('/');
    get('/sign-in')->then()
        ->submit('account.signIn', ['email' => 'not an address', 'password' => 'x', 'next' => '/orders'], '/sign-in')
        ->assertRedirect('/sign-in');
});

// ------------------------------------------------------------- isolation

it('refuses fakes where the environment says production', function () {
    $fakes = new BodyFakes();
    putenv('TINVAR_ENV=production');
    // A production build has its discovery manifest (ADR 0146).
    putenv('TINVAR_BUILD_DIR=' . Shop::productionBuild());

    try {
        expect(fn () => new BodyFakes())->toThrow(\LogicException::class, 'TINVAR_ENV=production')
            ->and(fn () => shop($fakes))->toThrow(\InvalidArgumentException::class, 'TINVAR_ENV=production');
    } finally {
        putenv('TINVAR_ENV');
        putenv('TINVAR_BUILD_DIR');
    }
});

it('installs a fake that the next test must not meet', function () {
    Query::fake(Availability::class, fn (Product $product): AvailabilityData => new AvailabilityData($product->id, 'sold_out', null, null));
    expect(Built::$fakes->query('products.availability'))->not->toBeNull();

    return [testing()->app, Built::$fakes];
});

it('meets the real body in the next test, on the same application', function (array $earlier) {
    [$app, $fakes] = $earlier;
    \assert($app instanceof TestableApplication && $fakes instanceof BodyFakes);
    expect($fakes->query('products.availability'))->toBeNull('Testing::forget() emptied it after the last test');

    Testing::use($app);
    $product = product();
    query(Availability::class, product: $product)->assertValue(new Encoder()->encode(Availability::of($product)));
})->depends('it installs a fake that the next test must not meet');

// --------------------------------------------------- through the renderer

describe('through the real renderer', function () {
    beforeEach(function () {
        $unbuilt = Shop::ensureBuilt();
        if ($unbuilt !== null) {
            $this->markTestSkipped($unbuilt);
        }
    });

    it('renders the catalog with every availability call answered by the fake, in one tick', function () {
        Query::fake(Availability::class, fn (Product $product): AvailabilityData => new AvailabilityData($product->id, 'low', 7, 2));

        render('/')
            ->assertOk()
            ->assertSelector('[data-availability="low"]', count: 24)
            ->assertSee('7 left')
            ->assertQueryTicks(1);
    });
});
