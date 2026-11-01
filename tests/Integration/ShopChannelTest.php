<?php

declare(strict_types=1);

/**
 * The shop's order channel (ADR 0069), through its own Kernel: a customer
 * subscribes to their own order and to no one else's, the shipping task
 * tells exactly that subscription when it ships the order, and signing out
 * takes the hub's grant away.
 */

namespace Tinvar\Tests\Integration\ShopChannel;

use Shop\Http\Channels\Orders\OrderUpdates;
use Shop\Database\Seeders\Seeder;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Orders\OrderStatus;
use Shop\Http\Channels\Orders\OrderChanges;
use Shop\Console\Tasks\Fulfilment\AdvanceShipments;
use Tinvar\Channels\ChannelToken;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\TenantClaim;
use Tinvar\Support\Signer;

const KEY = 'shop-channel-test-key-of-32-bytes!!';

function database(): string
{
    $path = sys_get_temp_dir() . '/tinvar-shop-channel-' . bin2hex(random_bytes(4)) . '.sqlite';
    new Seeder(PdoConnection::sqlite($path, 'shop', create: true))->run(customers: 3);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (['', '-wal', '-shm', '-throttle', '-throttle-wal', '-throttle-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    });

    return $path;
}

/** The demo customer, signed in, with the cookies their browser would hold. */
final class Customer
{
    /** @var array<string, string> */
    public array $cookies = [];

    public readonly Kernel $kernel;
    public readonly MemoryHub $hub;
    public readonly string $id;

    public function __construct(public readonly string $database)
    {
        $this->hub = new MemoryHub();
        $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-channel-none.sock', key: KEY, database: $database, hub: $this->hub);
        $this->id = (string) $this->kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];

        $this->keep($this->kernel->handle(new Request('GET', '/sign-in', ['host' => 'localhost', 'accept' => 'text/html'])));
        $this->post('/_action/account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/']);
    }

    /** One of this customer's orders, put back to placed a day and a half ago, so the next run ships it. */
    public function anOrder(): string
    {
        $id = (string) $this->kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id = ? LIMIT 1', [$this->id])[0]['id'];
        $placed = gmdate('Y-m-d\TH:i:s\Z', time() - 36 * 3600);
        $this->kernel->orm->connection()->execute("UPDATE orders SET status = 'placed', placed_at = ? WHERE id = ?", [$placed, $id]);

        return $id;
    }

    public function someoneElsesOrder(): string
    {
        return (string) $this->kernel->orm->connection()->select('SELECT id FROM orders WHERE customer_id <> ? LIMIT 1', [$this->id])[0]['id'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{Response, array<string, mixed>}
     */
    public function json(string $path, array $body): array
    {
        $response = $this->post($path, $body);
        $decoded = json_decode($response->body() === '' ? '{}' : $response->body(), true, flags: JSON_THROW_ON_ERROR);

        return [$response, \is_array($decoded) ? $decoded : []];
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body): Response
    {
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', ['host' => 'localhost'], [], [], $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                $path,
                ['host' => 'localhost', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

it('lets a customer subscribe to their own order, and tells that subscription when the order ships', function () {
    $customer = new Customer(database());
    $order = $customer->anOrder();

    [$subscribed, $body] = $customer->json('/_channel/orders', ['params' => ['order' => $order]]);
    expect($subscribed->status)->toBe(200);

    [$traded, $grant] = $customer->json(SubscribeEndpoint::PATH, ['tokens' => [$body['token']]]);
    $live = LiveUpdates::fromKey(KEY, $customer->hub, static fn (): string => '');
    $topic = (string) $live->channelTopics?->for(TenantClaim::current($customer->kernel->orm), 'orders', ['order' => $order]);
    expect($traded->status)->toBe(200)
        ->and($grant['topics'])->toBe([$topic])
        ->and(ChannelToken::decode((string) $body['token'], $live->signer)->topic)->toBe($topic);

    $changes = new OrderChanges(channels: $live->channels($customer->kernel->channels, $customer->kernel->orm));
    new AdvanceShipments()->advance($customer->kernel->orm, $changes, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $heard = array_values(array_filter($customer->hub->published, static fn (array $u): bool => $u['topic'] === $topic));
    $number = (string) $customer->kernel->orm->connection()->select('SELECT number FROM orders WHERE id = ?', [$order])[0]['number'];
    expect($heard)->toHaveCount(1)
        ->and(json_decode($heard[0]['data'], true))->toBe([
            'topic' => $topic,
            'channel' => 'orders',
            'message' => ['number' => $number, 'status' => OrderStatus::Shipped->value, 'title' => "Order {$number} has shipped"],
        ]);
});

it('refuses another customer\'s order with a 403, and a visitor signed out with a 401', function () {
    $customer = new Customer(database());

    [$theirs, $body] = $customer->json('/_channel/orders', ['params' => ['order' => $customer->someoneElsesOrder()]]);
    expect($theirs->status)->toBe(403)
        ->and($body)->not->toHaveKey('token');

    $customer->post('/_action/account.signOut', []);
    [$signedOut] = $customer->json('/_channel/orders', ['params' => ['order' => $customer->anOrder()]]);
    expect($signedOut->status)->toBe(401);
});

it('takes the hub\'s grant away when the customer signs out, and refuses the old subscription after', function () {
    $customer = new Customer(database());
    [, $body] = $customer->json('/_channel/orders', ['params' => ['order' => $customer->anOrder()]]);
    [, $grant] = $customer->json(SubscribeEndpoint::PATH, ['tokens' => [$body['token']]]);
    expect($grant['topics'])->toHaveCount(1);

    $signOut = $customer->post('/_action/account.signOut', []);
    expect($signOut->header('x-test-hub-revoked'))->toBe('1');

    [$after] = $customer->json(SubscribeEndpoint::PATH, ['tokens' => [$body['token']]]);
    expect($after->status)->toBe(401);
});

it('is a channel the shop registers, and publishes to', function () {
    expect(Kernel::classes()->channels)->toBe([OrderUpdates::class]);
});
