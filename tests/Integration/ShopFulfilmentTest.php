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

use Shop\Http\Actions\Fulfilment\FulfilmentActions;
use Shop\Auth\Sessions\AuthEpochs;
use Shop\Auth\Admin\StaffMemberships;
use Shop\Auth\Admin\StaffPermission;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Orders\Order;
use Shop\Database\Models\Orders\OrderStatus;
use Shop\Http\Pages\Admin\Orders as AdminOrders;
use Shop\Support\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Calls\CallResult;
use Tinvar\Console\Output;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Services\ServiceCalls;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Testing;
use Tinvar\Webhooks\DeliverWebhook;
use Tinvar\Workflows\WorkflowRecord;

use function Tinvar\Testing\actingAs;

/** The admin panel's host (ADR 0149): admin. in front of the shop's, localhost in a test. */
const ADMIN_ORIGIN = 'http://admin.localhost:8080';

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
    $rows = $kernel->orm->connection()->select($sql, $customer === null ? [OrderStatus::Placed->value] : [OrderStatus::Placed->value, $customer->id]);
    if ($rows === []) {
        $id = (string) $kernel->orm->connection()->select('SELECT id FROM orders ORDER BY id LIMIT 1')[0]['id'];
        $kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [OrderStatus::Placed->value, $id]);

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
function callBack(Kernel $kernel, string $operation, string $context, array $arguments, bool $signedIn = true, string $host = 'admin.localhost:8080'): Response
{
    $body = ['arguments' => $arguments];

    return $kernel->handle(
        new Request('POST', "/api/warehouse/calls/{$operation}", [
            'host' => $host,
            'accept' => 'application/json',
            'content-type' => 'application/json',
            ServiceCalls::HEADER => $context,
            ...($signedIn ? ['authorization' => 'Bearer ' . TOKEN] : []),
        ], [], $body, [], remoteAddress: '127.0.0.1'),
        json_encode($body, JSON_THROW_ON_ERROR),
    );
}

/**
 * A token for the warehouse to say $operation, issued as the staff account
 * in the admin panel, for the admin host it calls back on (ADR 0149).
 */
function contextFor(Kernel $kernel, string $operation = FulfilmentActions::COLLECTED, ?string $host = 'admin.localhost'): string
{
    $staff = account($kernel, StaffAccount::EMAIL);
    $http = $kernel->kernel();
    \assert($http->panels !== null);
    $panels = $http->panels;

    return $http->auth->actingAs($staff, fn (): string => $panels->within(StaffMemberships::PANEL, fn (): string => $kernel->services->context('warehouse', [$operation], host: $host)), epochOf($kernel, $staff));
}

describe('the fulfilment workflow', function () {
    it('is offered on the admin orders page to staff who may fulfil, and to no one else', function () {
        $staff = account($this->kernel, StaffAccount::EMAIL);
        $offered = static fn (bool $may) => static fn (AssertablePage $page) => $page->where('canFulfil', $may)->etc();

        actingAs($staff, epochOf($this->kernel, $staff))->get(ADMIN_ORIGIN . '/orders')->assertOk()->assertPage(AdminOrders::class, $offered(true));

        new StaffMemberships($this->kernel->orm)->grant($staff->id, [StaffPermission::ViewAllOrders]);
        actingAs($staff, epochOf($this->kernel, $staff))->get(ADMIN_ORIGIN . '/orders')->assertOk()->assertPage(AdminOrders::class, $offered(false));
    });

    it('packs a placed order on the worker, as the staff member who started it', function () {
        $order = placedOrder($this->kernel);

        $started = fulfil($this->kernel, $order);
        $started->assertOk();

        // Started, not run: the request returned before anything moved.
        expect(statusOf($this->kernel, $order))->toBe(OrderStatus::Placed->value);

        work($this->kernel);

        expect(statusOf($this->kernel, $order))->toBe(OrderStatus::Packed->value)
            ->and(failures($this->kernel))->toBe([])
            ->and($this->kernel->queue->size('default'))->toBe(0);
    });

    it('is refused for an order already packed, or by staff who may not fulfil', function () {
        $order = placedOrder($this->kernel);
        $this->kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [OrderStatus::Packed->value, $order]);

        fulfil($this->kernel, $order)->assertForbidden();

        $other = placedOrder($this->kernel);
        $staff = account($this->kernel, StaffAccount::EMAIL);
        new StaffMemberships($this->kernel->orm)->grant($staff->id, [StaffPermission::ViewAllOrders]);
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
        expect(statusOf($kernel, $order))->toBe(OrderStatus::Packed->value);

        // Narrowed to reading orders: the grant moves the epoch on.
        $staff = account($kernel, StaffAccount::EMAIL);
        new StaffMemberships($kernel->orm)->grant($staff->id, [StaffPermission::ViewAllOrders]);
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
            ->and($sent['data']['collectedUrl'])->toBe(ADMIN_ORIGIN . '/api/warehouse/calls/orders.collected');
        $context = $sent['data']['serviceContext'];

        // Short-lived, since it waits in the jobs table: an hour. And bound
        // to the admin host, where the warehouse calls back (ADR 0149).
        $claims = json_decode((string) \Tinvar\Support\Signer::decode(explode('.', $context)[1]), true);
        expect($claims['h'])->toBe('admin.localhost');
        expect($claims['e'] - $claims['i'])->toBe(\Shop\Workflows\Fulfilment\FulfilOrder::COLLECT_WITHIN)
            ->and(\Shop\Workflows\Fulfilment\FulfilOrder::COLLECT_WITHIN)->toBeLessThanOrEqual(3600);

        // Never kept to be sent again (ADR 0096): the token is not left at
        // rest in webhook_events, and no resend replays it past its hour.
        expect($kernel->orm->connection()->select("SELECT COUNT(*) AS n FROM webhook_events WHERE event = 'order.packed'")[0]['n'])->toBe(0)
            ->and(json_encode($kernel->orm->connection()->select('SELECT data FROM webhook_events')))->not->toContain($context);

        // Without its own credential the token is nothing.
        $fulfilment = $sent['data']['fulfilment'];
        expect(callBack($kernel, 'orders.collected', $context, ['order' => $order, 'fulfilment' => $fulfilment], signedIn: false)->status)->toBe(401)
            ->and(statusOf($kernel, $order))->toBe(OrderStatus::Packed->value);

        // The workflow waits on the warehouse's word, holding no worker
        // (ADR 0119), and its progress says so.
        $staff = account($kernel, StaffAccount::EMAIL);
        $progress = fn () => $kernel->kernel()->auth->actingAs($staff, fn () => $kernel->kernel()->panels?->within(StaffMemberships::PANEL, fn () => $kernel->workflows->progress($fulfilment)), epochOf($kernel, $staff));
        expect($progress()?->status)->toBe(WorkflowRecord::WAITING)
            ->and($progress()?->step)->toBe("collected:{$order}");

        // Good for this order alone: the token is bound to it and to this
        // fulfilment, so it says nothing of another packed order -- and a
        // call refused for that leaves it unspent.
        $other = placedOrder($kernel);
        $kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [OrderStatus::Packed->value, $other]);
        $elsewhere = callBack($kernel, 'orders.collected', $context, ['order' => $other, 'fulfilment' => $fulfilment]);
        expect($elsewhere->status)->toBe(403)
            ->and(json_decode($elsewhere->body(), true)['reason'])->toBe('context')
            ->and(callBack($kernel, 'orders.collected', $context, ['order' => $order, 'fulfilment' => '01900000-0000-7000-8000-000000000000'])->status)->toBe(403)
            ->and(statusOf($kernel, $other))->toBe(OrderStatus::Packed->value);

        // Its word is a signal to the workflow, which wakes and ships it.
        $collected = callBack($kernel, 'orders.collected', $context, ['order' => $order, 'fulfilment' => $fulfilment]);
        expect($collected->status)->toBe(200)
            ->and(json_decode($collected->body(), true))->toBe(['status' => 'ok', 'value' => ['order' => $sent['data']['number'], 'status' => 'collected']])
            ->and(statusOf($kernel, $order))->toBe(OrderStatus::Packed->value);
        work($kernel);
        expect(statusOf($kernel, $order))->toBe(OrderStatus::Shipped->value)
            ->and($progress()?->status)->toBe(WorkflowRecord::FINISHED)
            ->and(failures($kernel))->toBe([]);

        // Said twice, the token is spent.
        $again = callBack($kernel, 'orders.collected', $context, ['order' => $order, 'fulfilment' => $fulfilment]);
        expect($again->status)->toBe(403)
            ->and(json_decode($again->body(), true)['reason'])->toBe('spent');
    });

    it('is pruned nightly once ended, with the queue\'s finished batches and lapsed locks', function () {
        expect(Kernel::classes()->tasks)->toContain(\Tinvar\Jobs\PruneQueue::class, \Tinvar\Workflows\PruneWorkflows::class);
    });

    it('takes no signal for a fulfilment that is not waiting on this order', function () {
        $order = placedOrder($this->kernel);
        $this->kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [OrderStatus::Packed->value, $order]);

        $refused = callBack($this->kernel, 'orders.collected', contextFor($this->kernel), ['order' => $order, 'fulfilment' => '01900000-0000-7000-8000-000000000000']);
        expect($refused->status)->toBe(422)
            ->and(statusOf($this->kernel, $order))->toBe(OrderStatus::Packed->value);
    });
});

describe('the warehouse\'s service calls', function () {
    it('are refused for an operation the token does not name, or the warehouse may not call', function () {
        $order = placedOrder($this->kernel);
        $context = contextFor($this->kernel);

        expect(callBack($this->kernel, 'orders.fulfil', $context, ['order' => $order])->status)->toBe(403)
            ->and(json_decode(callBack($this->kernel, 'orders.fulfil', $context, ['order' => $order])->body(), true)['reason'])->toBe('unknown_operation')
            ->and(callBack($this->kernel, 'orders.collected', 'v1.forged.token', ['order' => $order])->status)->toBe(403)
            ->and(statusOf($this->kernel, $order))->toBe(OrderStatus::Placed->value);
    });

    it('are refused once the staff member the token was issued for is no longer staff', function () {
        $order = placedOrder($this->kernel);
        $this->kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [OrderStatus::Packed->value, $order]);
        $context = contextFor($this->kernel);

        new StaffMemberships($this->kernel->orm)->revoke(account($this->kernel, StaffAccount::EMAIL)->id);
        $refused = callBack($this->kernel, 'orders.collected', $context, ['order' => $order]);

        expect($refused->status)->toBe(403)
            ->and(json_decode($refused->body(), true))->toBe(['status' => CallResult::REFUSED, 'value' => null, 'reason' => 'context'])
            ->and(statusOf($this->kernel, $order))->toBe(OrderStatus::Packed->value);
    });

    it('are answered on the admin panel\'s host alone, with a token bound to it', function () {
        $order = placedOrder($this->kernel);
        $this->kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [OrderStatus::Packed->value, $order]);
        $arguments = ['order' => $order, 'fulfilment' => '01900000-0000-7000-8000-000000000000'];

        // Not on the storefront's host, where the admin panel is not.
        expect(callBack($this->kernel, 'orders.collected', contextFor($this->kernel), $arguments, host: '127.0.0.1')->status)->toBe(404)
            ->and(callBack($this->kernel, 'orders.collected', contextFor($this->kernel), $arguments, host: 'localhost:8080')->status)->toBe(404);

        // A token issued with no request in hand and no host named is good
        // only outside HTTP: over HTTP it is refused, unspent.
        $nowhere = callBack($this->kernel, 'orders.collected', contextFor($this->kernel, host: null), $arguments);
        expect($nowhere->status)->toBe(403)
            ->and(json_decode($nowhere->body(), true)['reason'])->toBe('context')
            ->and(statusOf($this->kernel, $order))->toBe(OrderStatus::Packed->value);
    });

    it('validate the arguments as the operation\'s own', function () {
        $context = contextFor($this->kernel);

        expect(callBack($this->kernel, 'orders.collected', $context, ['order' => '01900000-0000-7000-8000-000000000000', 'fulfilment' => 'f'])->status)->toBe(404)
            ->and(callBack($this->kernel, 'orders.collected', contextFor($this->kernel), ['order' => 'x', 'status' => 'delivered'])->status)->toBe(422);
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
