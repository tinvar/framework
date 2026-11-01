<?php

declare(strict_types=1);

/**
 * The shop's webhook deliveries page and resend (ADR 0096), through the
 * request path and the queue worker, without the renderer: staff holding
 * the permission see each delivery's latest try and send one again as a
 * new delivery, recorded with who asked -- and a customer, staff without
 * the permission, and an id that is not a kept delivery are each refused.
 */

namespace Tinvar\Tests\Integration\ShopDeliveries;

use Shop\Auth\Admin\StaffMemberships;
use Shop\Auth\Admin\StaffPermission;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Http\Pages\Admin\Deliveries as DeliveriesPage;
use Shop\Support\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Support\Signer;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Testing;
use Tinvar\Webhooks\DeliverWebhook;

use function Tinvar\Testing\actingAs;

const KEY = 'shop-deliveries-test-key-of-32-bytes';

const SECRET = 'pellwick-fulfilment-secret-of-32-bytes';

/** A browser signed in as one account: its session cookie, and the session's CSRF token. */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies;

    public function __construct(public readonly Kernel $kernel, Customer $customer)
    {
        $this->cookies = $kernel->signedIn($customer, new Request('GET', '/', ['host' => '127.0.0.1']));
    }

    /** @param array<string, mixed> $body */
    public function action(string $name, array $body): Response
    {
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));

        return $this->kernel->handle(
            new Request('POST', "/_action/{$name}", ['host' => '127.0.0.1', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token], [], [], [...$this->cookies, '__Host-XSRF-TOKEN' => $token]),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}

beforeEach(function () {
    putenv('SHOP_ORDER_WEBHOOK=https://fulfilment.example.test/hooks/pellwick');
    putenv('SHOP_ORDER_WEBHOOK_SECRET=' . SECRET);
    $this->http = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(503, [], 'down', 12));
    $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-deliveries-none.sock', key: KEY, database: Shop::testDatabase(), outbound: $this->http);
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

/** Places an order as the demo customer, and works the webhook queue once; returns the delivery's id. */
function delivered(Kernel $kernel): string
{
    $product = (string) $kernel->orm->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id'];
    expect(new Browser($kernel, account($kernel, DemoAccount::EMAIL))->action('orders.place', ['product' => $product, 'quantity' => 1])->status)->toBe(303);
    work($kernel);

    return (string) $kernel->orm->connection()->select('SELECT delivery FROM webhook_events ORDER BY sent_at, delivery LIMIT 1')[0]['delivery'];
}

function work(Kernel $kernel): void
{
    $output = new Output(static function (string $line): void {}, static function (string $line): void {});
    expect($kernel->console->run('queue:work', ['--queue=' . DeliverWebhook::QUEUE, '--stop-when-empty'], $output))->toBe(0);
}

it('lists each delivery\'s latest try for staff, and none of what the receiver said', function () {
    $id = delivered($this->kernel);

    actingAs(account($this->kernel, StaffAccount::EMAIL))->get('/admin/deliveries')
        ->assertOk()
        ->assertPage(DeliveriesPage::class, fn (AssertablePage $page) => $page
            ->where('configured', true)
            ->has('deliveries', 1, fn (AssertablePage $d) => $d
                ->where('id', $id)
                ->where('event', 'order.placed')
                ->where('outcome', 'failed')
                ->where('status', 503)
                ->where('attempts', 1)
                ->where('resendable', true)
                ->where('resendOf', null)
                ->etc())
            ->etc());
});

it('sends a delivery again as a new one, recording which it repeats and who asked', function () {
    $id = delivered($this->kernel);
    $staff = account($this->kernel, StaffAccount::EMAIL);

    $response = new Browser($this->kernel, $staff)->action('deliveries.resend', ['delivery' => $id]);
    expect($response->status)->toBe(200);
    $again = (string) json_decode($response->body(), true)['value']['delivery'];

    $row = $this->kernel->orm->connection()->select('SELECT * FROM webhook_events WHERE delivery = ?', [$again])[0];
    $first = $this->kernel->orm->connection()->select('SELECT data FROM webhook_events WHERE delivery = ?', [$id])[0];
    expect($again)->not->toBe($id)
        ->and($row['resend_of'])->toBe($id)
        ->and($row['resent_by'])->toBe($staff->id)
        ->and($row['data'])->toBe($first['data'])
        ->and($this->kernel->queue->size(DeliverWebhook::QUEUE))->toBeGreaterThanOrEqual(1);

    work($this->kernel);
    $bodies = array_map(static fn ($r): array => json_decode($r->body, true), $this->http->sent);
    expect(array_column($bodies, 'id'))->toContain($again)
        ->and(array_unique(array_column($bodies, 'event')))->toBe(['order.placed']);

    actingAs($staff)->get('/admin/deliveries')
        ->assertOk()
        ->assertPage(DeliveriesPage::class, fn (AssertablePage $page) => $page
            ->has('deliveries', 2)
            ->where('deliveries.0.id', $again)
            ->where('deliveries.0.resendOf', $id)
            ->where('deliveries.0.resentBy', StaffAccount::NAME)
            ->etc());
});

it('refuses a customer and staff without the permission, and sends nothing again', function () {
    $id = delivered($this->kernel);

    expect(new Browser($this->kernel, account($this->kernel, DemoAccount::EMAIL))->action('deliveries.resend', ['delivery' => $id])->status)->toBe(403);
    actingAs(account($this->kernel, DemoAccount::EMAIL))->get('/admin/deliveries')->assertStatus(403);

    $staff = account($this->kernel, StaffAccount::EMAIL);
    new StaffMemberships($this->kernel->orm)->grant($staff->id, [StaffPermission::ViewAllOrders]);
    $staff = account($this->kernel, StaffAccount::EMAIL);
    expect(new Browser($this->kernel, $staff)->action('deliveries.resend', ['delivery' => $id])->status)->toBe(403);
    actingAs($staff)->get('/admin/deliveries')->assertStatus(403);

    expect($this->kernel->orm->connection()->select('SELECT COUNT(*) AS n FROM webhook_events')[0]['n'])->toBe(1);
});

it('refuses an id that is no kept delivery', function () {
    delivered($this->kernel);
    $response = new Browser($this->kernel, account($this->kernel, StaffAccount::EMAIL))->action('deliveries.resend', ['delivery' => 'not-a-delivery']);

    expect($response->status)->toBe(422)
        ->and($this->kernel->orm->connection()->select('SELECT COUNT(*) AS n FROM webhook_events')[0]['n'])->toBe(1);
});
