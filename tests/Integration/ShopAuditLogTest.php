<?php

declare(strict_types=1);

/**
 * The shop's audit log (ADR 0150), through its own Kernel: staff fulfilling
 * an order is an audited action, and the workflow's moves are the job's,
 * started by them; a customer cancelling is the action and the change it
 * made, through it; sign-ins, failed or not, are in the account's log with
 * the address they came from; the admin panel reads an order's activity and
 * the staff's, to staff who may, and to no one else; and a change that
 * rolls back leaves nothing behind.
 */

namespace Tinvar\Tests\Integration\ShopAuditLog;

use Shop\Auth\Admin\StaffMemberships;
use Shop\Auth\Admin\StaffPermission;
use Shop\Auth\Sessions\AuthEpochs;
use Shop\Database\Models\Account\Customer;
use Shop\Database\Models\Orders\Order;
use Shop\Database\Models\Orders\OrderStatus;
use Shop\Http\Data\Admin\OrderActivityPageData;
use Shop\Http\Data\Admin\StaffActivityPageData;
use Shop\Http\Pages\Admin\OrderActivity;
use Shop\Http\Pages\Admin\StaffActivity;
use Shop\Kernel;
use Shop\Support\DemoAccount;
use Shop\Support\StaffAccount;
use Tinvar\Audit\ActorKind;
use Tinvar\Audit\AuditEntry;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Support\Signer;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;

const ADMIN_ORIGIN = 'http://admin.localhost:8080';

const KEY = 'shop-audit-log-test-key-of-32-bytes!';

beforeEach(function () {
    $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-audit-none.sock', key: KEY, database: Shop::testDatabase());
    Testing::use($this->kernel);
});

afterEach(function () {
    Testing::forget();
});

function account(Kernel $kernel, string $email): Customer
{
    $id = (string) $kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = $kernel->orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

function signedIn(Kernel $kernel, Customer $customer): \Tinvar\Testing\Client
{
    return actingAs($customer, new AuthEpochs($kernel->orm)->of($customer->id));
}

/** One of $customer's orders, put in $status behind the log's back, as a seed would. */
function orderOf(Kernel $kernel, Customer $customer, OrderStatus $status): string
{
    $id = (string) $kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id = ? ORDER BY id LIMIT 1', [$customer->id])[0]['id'];
    $kernel->orm->connection()->execute('UPDATE orders SET status = ? WHERE id = ?', [$status->value, $id]);

    return $id;
}

/** @return list<AuditEntry> */
function activityOf(Kernel $kernel, string $order): array
{
    return $kernel->audit->trail()->forSubjectKey(Order::class, $order);
}

/** A visitor signing in through the shop's own Kernel, from $address, as the sign-in form posts. */
function signInFrom(Kernel $kernel, string $address, string $password): Response
{
    $page = $kernel->handle(new Request('GET', '/sign-in', ['host' => 'localhost', 'accept' => 'text/html'], remoteAddress: $address));
    $cookies = [];
    foreach ($page->cookies() as $name => $line) {
        $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
    }
    $token = (string) new CsrfTokens(
        new Signer(KEY, CsrfTokens::PURPOSE),
        static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
    )->for(new Request('GET', '/', ['host' => 'localhost'], [], [], $cookies));
    $body = ['email' => DemoAccount::EMAIL, 'password' => $password, 'next' => '/'];

    return $kernel->handle(
        new Request(
            'POST',
            '/_action/account.signIn',
            ['host' => 'localhost', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
            [],
            [],
            [...$cookies, '__Host-XSRF-TOKEN' => $token],
            null,
            $address,
        ),
        json_encode($body, JSON_THROW_ON_ERROR),
    );
}

function work(Kernel $kernel): void
{
    $output = new Output(static function (string $line): void {}, static function (string $line): void {});
    expect($kernel->console->run('queue:work', ['--queue=default', '--stop-when-empty'], $output))->toBe(0);
}

it('records a customer cancelling an order as the action and the change it made through it', function () {
    $owner = account($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Placed);

    signedIn($this->kernel, $owner)->action('orders.cancel', order: $order)->assertOk();

    [$action, $change] = activityOf($this->kernel, $order);
    expect($action->event)->toBe('action')
        ->and($action->data['name'])->toBe('orders.cancel')
        ->and($action->data['models']['order'])->toBe(['type' => Order::class, 'id' => $order])
        ->and($action->actor->kind)->toBe(ActorKind::User)
        ->and($action->actor->id)->toBe($owner->id)
        ->and($change->event)->toBe('updated')
        ->and($change->action)->toBe('orders.cancel')
        ->and($change->changes)->toBe(['status' => ['placed', 'cancelled']])
        ->and($this->kernel->audit->verify()->intact)->toBeTrue();

    // A cancel refused by the policy runs no body, and leaves nothing.
    signedIn($this->kernel, $owner)->action('orders.cancel', order: $order)->assertForbidden();
    expect(activityOf($this->kernel, $order))->toHaveCount(2);
});

it('names the staff member who fulfilled an order, and the job that moved it as started by them', function () {
    $staff = account($this->kernel, StaffAccount::EMAIL);
    $owner = account($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Placed);

    signedIn($this->kernel, $staff)->action('orders.fulfil', order: $order)->assertOk();
    work($this->kernel);

    $entries = activityOf($this->kernel, $order);
    $moved = array_values(array_filter($entries, static fn (AuditEntry $e): bool => $e->event === 'updated'));
    $ran = array_values(array_filter($entries, static fn (AuditEntry $e): bool => $e->event === 'action'));
    expect($ran[0]->actor->id)->toBe($staff->id)
        ->and($ran[0]->panel)->toBe(StaffMemberships::PANEL)
        ->and($moved)->not->toBe([])
        ->and($moved[0]->changes['status'][1])->toBe('packed')
        ->and($moved[0]->actor->kind)->toBe(ActorKind::Job)
        ->and($moved[0]->actor->startedBy)->toBe($staff->id)
        ->and($moved[0]->actor->job)->not->toBeNull();
});

it('records sign-ins, failed and not, in the account\'s log with where they came from', function () {
    $owner = account($this->kernel, DemoAccount::EMAIL);

    expect(signInFrom($this->kernel, '203.0.113.9', 'not-it')->status)->toBe(422)
        ->and(signInFrom($this->kernel, '203.0.113.9', DemoAccount::PASSWORD)->status)->toBeLessThan(400);

    $entries = $this->kernel->audit->trail()->forSubject($owner);
    $events = array_map(static fn (AuditEntry $e): string => $e->event, $entries);
    expect(\array_slice($events, 0, 2))->toBe(['auth.signed_in', 'auth.sign_in_failed'])
        ->and($entries[0]->actor->id)->toBe($owner->id)
        ->and($entries[1]->actor->kind)->toBe(ActorKind::Guest)
        ->and($entries[1]->data)->toBe(['email' => DemoAccount::EMAIL])
        ->and($entries[1]->request?->ip)->toBe('203.0.113.9')
        ->and($entries[1]->request?->host)->toBe('localhost')
        ->and($entries[0]->request?->ip)->toBe('203.0.113.9');
});

it('shows an order\'s activity to staff who may read every order, and to no one else', function () {
    $staff = account($this->kernel, StaffAccount::EMAIL);
    $owner = account($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Placed);
    signedIn($this->kernel, $owner)->action('orders.cancel', order: $order)->assertOk();

    signedIn($this->kernel, $staff)->get(ADMIN_ORIGIN . "/orders/{$order}/activity")->assertOk()
        ->assertPage(OrderActivity::class, static fn (AssertablePage $page) => $page->data(static function (OrderActivityPageData $data) use ($order, $owner): void {
            expect($data->orderId)->toBe($order)
                ->and(array_map(static fn ($e): string => $e->summary, $data->entries))->toBe(['Ran orders.cancel', 'Order changed'])
                ->and($data->entries[0]->actor)->toBe($owner->name)
                ->and($data->entries[1]->via)->toBe('orders.cancel')
                ->and($data->entries[1]->changes[0]->field)->toBe('status')
                ->and($data->older)->toBeNull();
        }));

    // The customer is no staff; staff who may not read every order are refused.
    signedIn($this->kernel, $owner)->get(ADMIN_ORIGIN . "/orders/{$order}/activity")->assertForbidden();
    new StaffMemberships($this->kernel->orm)->grant($staff->id, [StaffPermission::FulfilOrders]);
    signedIn($this->kernel, $staff)->get(ADMIN_ORIGIN . "/orders/{$order}/activity")->assertForbidden();
});

it('shows what the staff did, one member\'s or everyone\'s, and never an account that is not staff', function () {
    $staff = account($this->kernel, StaffAccount::EMAIL);
    $owner = account($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Placed);
    signedIn($this->kernel, $owner)->action('orders.cancel', order: $order)->assertOk();
    $placed = orderOf($this->kernel, $owner, OrderStatus::Placed);
    signedIn($this->kernel, $staff)->action('orders.fulfil', order: $placed)->assertOk();

    $listed = static fn (?string $member) => static fn (AssertablePage $page) => $page->data(static function (StaffActivityPageData $data) use ($staff, $member): void {
        expect($data->member)->toBe($member)
            ->and(array_map(static fn ($m): string => $m->id, $data->members))->toContain($staff->id)
            ->and(array_map(static fn ($e): string => $e->summary, $data->entries))->toBe(['Ran orders.fulfil']);
    });

    signedIn($this->kernel, $staff)->get(ADMIN_ORIGIN . '/staff/activity')->assertOk()->assertPage(StaffActivity::class, $listed(null));
    signedIn($this->kernel, $staff)->get(ADMIN_ORIGIN . "/staff/activity?member={$staff->id}")->assertOk()->assertPage(StaffActivity::class, $listed($staff->id));
    // The customer is not staff: naming them filters nothing, and shows none of theirs.
    signedIn($this->kernel, $staff)->get(ADMIN_ORIGIN . "/staff/activity?member={$owner->id}")->assertOk()->assertPage(StaffActivity::class, $listed(null));
});

it('leaves no entry for an order change that rolls back', function () {
    $owner = account($this->kernel, DemoAccount::EMAIL);
    $order = orderOf($this->kernel, $owner, OrderStatus::Placed);
    $model = $this->kernel->orm->find(Order::class, $order);
    \assert($model instanceof Order);

    expect(fn () => $this->kernel->orm->transaction(function () use ($model): void {
        $model->status = OrderStatus::Cancelled;
        $this->kernel->orm->save($model);
        throw new \RuntimeException('changed my mind');
    }))->toThrow(\RuntimeException::class);

    expect(activityOf($this->kernel, $order))->toBe([])
        ->and($this->kernel->audit->verify()->intact)->toBeTrue();
});
