<?php

declare(strict_types=1);

/**
 * The shop's workflow, service call and tool (ADR 0098), through its own
 * Kernel: staff fulfil an order now, a workflow packs it on the worker and
 * hands the warehouse a service context token, the warehouse calls back to
 * say it was collected, and a model helping a customer reads where their
 * order is through a read-only tool. No renderer: none of it draws a page.
 */

namespace Tinvar\Tests\Integration\ShopFulfilment;

use Shop\Actions\FulfilmentActions;
use Shop\Auth\AuthEpochs;
use Shop\Auth\StaffMemberships;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Models\Order;
use Shop\Pages\Admin\Orders as AdminOrders;
use Shop\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Calls\CallResult;
use Tinvar\Console\Output;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Services\ServiceCalls;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Testing;
use Tinvar\Webhooks\DeliverWebhook;

use function Tinvar\Testing\actingAs;

const KEY = 'shop-fulfilment-test-key-of-32-bytes!';
const TOKEN = 'warehouse-token-of-at-least-32-characters';

beforeEach(function () {
    $this->database = Shop::testDatabase();
    $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-fulfilment-none.sock', key: KEY, database: $this->database, warehouseToken: TOKEN);
    Testing::use($this->kernel);
});

afterEach(function () {
    Testing::forget();
    putenv('SHOP_ORDER_WEBHOOK');
    putenv('SHOP_ORDER_WEBHOOK_SECRET');
});

function account(Kernel $kernel, string $email): Customer
{
    $id = (string) $kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = $kernel->orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

function epochOf(Kernel $kernel, Customer $customer): string
{
    return new AuthEpochs($kernel->orm)->of($customer->id);
}

/** A placed order of $customer's, or of anyone's. */
function placedOrder(Kernel $kernel, ?Customer $customer = null): string
{
    $sql = 'SELECT id FROM orders WHERE status = ?' . ($customer === null ? '' : ' AND customer_id = ?') . ' ORDER BY id LIMIT 1';
    $rows = $kernel->orm->connection()->select($sql, $customer === null ? [Order::PLACED] : [Order::PLACED, $customer->id]);
    if ($rows === []) {
        $id = (string) $kernel->orm->connection()->select('SELECT id FROM orders ORDER BY id LIMIT 1')[0]['id'];
        $kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [Order::PLACED, $id]);

        return $id;
    }

    return (string) $rows[0]['id'];
}

function statusOf(Kernel $kernel, string $order): string
{
    return (string) $kernel->orm->connection()->select('SELECT status FROM orders WHERE id = ?', [$order])[0]['status'];
}

/** Works the default queue, as `tinvar queue:work` does; at most $jobs jobs when given. */
function work(Kernel $kernel, ?int $jobs = null): void
{
    $output = new Output(static function (string $line): void {}, static function (string $line): void {});
    $options = ['--queue=default', '--stop-when-empty', ...($jobs === null ? [] : ["--max-jobs={$jobs}"])];
    expect($kernel->console->run('queue:work', $options, $output))->toBe(0);
}

/** @return list<string> */
function failures(Kernel $kernel): array
{
    return array_map(static fn (array $row): string => (string) $row['error'], $kernel->orm->connection()->select('SELECT error FROM failed_jobs'));
}

/** The staff account fulfilling $order, through the action endpoint. */
function fulfil(Kernel $kernel, string $order): \Tinvar\Testing\ActionResponse
{
    $staff = account($kernel, StaffAccount::EMAIL);

    return actingAs($staff, epochOf($kernel, $staff))->action('orders.fulfil', order: $order);
}

/**
 * The warehouse calling an operation back, signed in with its bearer token
 * unless told not to.
 *
 * @param array<string, mixed> $arguments
 */
function callBack(Kernel $kernel, string $operation, string $context, array $arguments, bool $signedIn = true): Response
{
    $body = ['arguments' => $arguments];

    return $kernel->handle(
        new Request('POST', "/api/warehouse/calls/{$operation}", [
            'host' => '127.0.0.1',
            'accept' => 'application/json',
            'content-type' => 'application/json',
            ServiceCalls::HEADER => $context,
            ...($signedIn ? ['authorization' => 'Bearer ' . TOKEN] : []),
        ], [], $body, [], remoteAddress: '127.0.0.1'),
        json_encode($body, JSON_THROW_ON_ERROR),
    );
}

/** A token for the warehouse to say $operation, issued as the staff account in the admin panel. */
function contextFor(Kernel $kernel, string $operation = FulfilmentActions::COLLECTED): string
{
    $staff = account($kernel, StaffAccount::EMAIL);
    $http = $kernel->kernel();
    \assert($http->panels !== null);
    $panels = $http->panels;

    return $http->auth->actingAs($staff, fn (): string => $panels->within(StaffMemberships::PANEL, fn (): string => $kernel->services->context('warehouse', [$operation])), epochOf($kernel, $staff));
}

describe('the fulfilment workflow', function () {
    it('is offered on the admin orders page to staff who may fulfil, and to no one else', function () {
        $staff = account($this->kernel, StaffAccount::EMAIL);
        $offered = static fn (bool $may) => static fn (AssertablePage $page) => $page->where('canFulfil', $may)->etc();

        actingAs($staff, epochOf($this->kernel, $staff))->get('/admin/orders')->assertOk()->assertPage(AdminOrders::class, $offered(true));

        new StaffMemberships($this->kernel->orm)->grant($staff->id, [StaffMemberships::VIEW_ALL_ORDERS]);
        actingAs($staff, epochOf($this->kernel, $staff))->get('/admin/orders')->assertOk()->assertPage(AdminOrders::class, $offered(false));
    });

    it('packs a placed order on the worker, as the staff member who started it', function () {
        $order = placedOrder($this->kernel);

        $started = fulfil($this->kernel, $order);
        $started->assertOk();

        // Started, not run: the request returned before anything moved.
        expect(statusOf($this->kernel, $order))->toBe(Order::PLACED);

        work($this->kernel);

        expect(statusOf($this->kernel, $order))->toBe(Order::PACKED)
            ->and(failures($this->kernel))->toBe([])
            ->and($this->kernel->queue->size('default'))->toBe(0);
    });

    it('is refused for an order already packed, or by staff who may not fulfil', function () {
        $order = placedOrder($this->kernel);
        $this->kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [Order::PACKED, $order]);

        fulfil($this->kernel, $order)->assertForbidden();

        $other = placedOrder($this->kernel);
        $staff = account($this->kernel, StaffAccount::EMAIL);
        new StaffMemberships($this->kernel->orm)->grant($staff->id, [StaffMemberships::VIEW_ALL_ORDERS]);
        fulfil($this->kernel, $other)->assertForbidden();

        expect($this->kernel->queue->size('default'))->toBe(0);
    });

    it('ends before handing over once the staff member\'s role is taken away between its steps', function () {
        $order = placedOrder($this->kernel);
        putenv('SHOP_ORDER_WEBHOOK=https://fulfilment.example/hook');
        putenv('SHOP_ORDER_WEBHOOK_SECRET=pellwick-fulfilment-secret-of-32-bytes');
        $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-fulfilment-none.sock', key: KEY, database: $this->database, warehouseToken: TOKEN);
        Testing::use($kernel);
        fulfil($kernel, $order)->assertOk();

        // The first step, `pack`, and no more.
        work($kernel, 1);
        expect(statusOf($kernel, $order))->toBe(Order::PACKED);

        // Narrowed to reading orders: the grant moves the epoch on.
        $staff = account($kernel, StaffAccount::EMAIL);
        new StaffMemberships($kernel->orm)->grant($staff->id, [StaffMemberships::VIEW_ALL_ORDERS]);
        work($kernel);

        expect(failures($kernel))->toHaveCount(1)
            ->and(failures($kernel)[0])->toContain('another auth epoch')
            // The warehouse was never handed a token.
            ->and($kernel->orm->connection()->select('SELECT id FROM jobs WHERE queue = ?', [DeliverWebhook::QUEUE]))->toBe([]);
    });

    it('hands the warehouse a token it can say the order was collected with, and nothing more', function () {
        $order = placedOrder($this->kernel);
        putenv('SHOP_ORDER_WEBHOOK=https://fulfilment.example/hook');
        putenv('SHOP_ORDER_WEBHOOK_SECRET=pellwick-fulfilment-secret-of-32-bytes');
        $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-fulfilment-none.sock', key: KEY, database: $this->database, warehouseToken: TOKEN);
        Testing::use($kernel);

        fulfil($kernel, $order)->assertOk();
        work($kernel);

        // The `order.packed` delivery, queued for the webhook's worker.
        $payload = (string) $kernel->orm->connection()->select('SELECT payload FROM jobs WHERE queue = ?', [DeliverWebhook::QUEUE])[0]['payload'];
        $sent = json_decode((string) json_decode(substr($payload, (int) strpos($payload, '.') + 1), true)['args']['body'], true);
        expect($sent['event'])->toBe('order.packed')
            ->and($sent['data']['id'])->toBe($order)
            ->and($sent['data']['collectedPath'])->toBe('/api/warehouse/calls/orders.collected');
        $context = $sent['data']['serviceContext'];

        // Short-lived, since it waits in the jobs table: an hour.
        $claims = json_decode((string) \Tinvar\Support\Signer::decode(explode('.', $context)[1]), true);
        expect($claims['e'] - $claims['i'])->toBe(\Shop\Workflows\FulfilOrder::COLLECT_WITHIN)
            ->and(\Shop\Workflows\FulfilOrder::COLLECT_WITHIN)->toBeLessThanOrEqual(3600);

        // Without its own credential the token is nothing.
        expect(callBack($kernel, 'orders.collected', $context, ['order' => $order], signedIn: false)->status)->toBe(401)
            ->and(statusOf($kernel, $order))->toBe(Order::PACKED);

        $collected = callBack($kernel, 'orders.collected', $context, ['order' => $order]);
        expect($collected->status)->toBe(200)
            ->and(json_decode($collected->body(), true))->toBe(['status' => 'ok', 'value' => ['order' => $sent['data']['number'], 'status' => Order::SHIPPED]])
            ->and(statusOf($kernel, $order))->toBe(Order::SHIPPED);

        // Said twice, the second finds it shipped already.
        expect(callBack($kernel, 'orders.collected', $context, ['order' => $order])->status)->toBe(422);
    });
});

describe('the warehouse\'s service calls', function () {
    it('are refused for an operation the token does not name, or the warehouse may not call', function () {
        $order = placedOrder($this->kernel);
        $context = contextFor($this->kernel);

        expect(callBack($this->kernel, 'orders.fulfil', $context, ['order' => $order])->status)->toBe(403)
            ->and(json_decode(callBack($this->kernel, 'orders.fulfil', $context, ['order' => $order])->body(), true)['reason'])->toBe('unknown_operation')
            ->and(callBack($this->kernel, 'orders.collected', 'v1.forged.token', ['order' => $order])->status)->toBe(403)
            ->and(statusOf($this->kernel, $order))->toBe(Order::PLACED);
    });

    it('are refused once the staff member the token was issued for is no longer staff', function () {
        $order = placedOrder($this->kernel);
        $this->kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [Order::PACKED, $order]);
        $context = contextFor($this->kernel);

        new StaffMemberships($this->kernel->orm)->revoke(account($this->kernel, StaffAccount::EMAIL)->id);
        $refused = callBack($this->kernel, 'orders.collected', $context, ['order' => $order]);

        expect($refused->status)->toBe(403)
            ->and(json_decode($refused->body(), true))->toBe(['status' => CallResult::REFUSED, 'value' => null, 'reason' => 'context'])
            ->and(statusOf($this->kernel, $order))->toBe(Order::PACKED);
    });

    it('validate the arguments as the operation\'s own', function () {
        $context = contextFor($this->kernel);

        expect(callBack($this->kernel, 'orders.collected', $context, ['order' => '01900000-0000-7000-8000-000000000000'])->status)->toBe(404)
            ->and(callBack($this->kernel, 'orders.collected', $context, ['order' => 'x', 'status' => 'delivered'])->status)->toBe(422);
    });
});

describe('the order shipments tool', function () {
    it('tells a model what it takes, and answers for the signed-in customer\'s own order', function () {
        $customer = account($this->kernel, DemoAccount::EMAIL);
        $own = (string) $this->kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id = ? LIMIT 1', [$customer->id])[0]['id'];
        $other = (string) $this->kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id <> ? LIMIT 1', [$customer->id])[0]['id'];
        $auth = $this->kernel->kernel()->auth;

        [$mine, $theirs] = $auth->actingAs($customer, function () use ($own, $other): array {
            $grant = $this->kernel->tools->grant(['order_shipments']);

            return [
                $this->kernel->tools->call($grant, 'order_shipments', ['order' => $own]),
                $this->kernel->tools->call($grant, 'order_shipments', ['order' => $other]),
            ];
        }, epochOf($this->kernel, $customer));

        expect($this->kernel->tools->definitions())->toHaveCount(1)
            ->and($this->kernel->tools->definitions()[0]['input_schema']['required'])->toBe(['order'])
            ->and($mine->status)->toBe(CallResult::OK)
            ->and($mine->value['events'][0]['id'] ?? null)->not->toBeNull()
            // Another customer's order, whatever the model was told to ask for.
            ->and($theirs->status)->toBe(CallResult::FORBIDDEN);
    });

    it('is refused a grant once the customer signs out everywhere, which moves their epoch', function () {
        $customer = account($this->kernel, DemoAccount::EMAIL);
        $own = (string) $this->kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id = ? LIMIT 1', [$customer->id])[0]['id'];
        $auth = $this->kernel->kernel()->auth;
        $epoch = epochOf($this->kernel, $customer);

        $result = $auth->actingAs($customer, function () use ($own, $customer): CallResult {
            $grant = $this->kernel->tools->grant(['order_shipments']);
            new AuthEpochs($this->kernel->orm)->bump($customer->id);

            return $this->kernel->tools->call($grant, 'order_shipments', ['order' => $own]);
        }, $epoch);

        expect($result->reason)->toBe('grant');
    });
});
