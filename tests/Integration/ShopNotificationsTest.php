<?php

declare(strict_types=1);

/**
 * The shop's notifications (ADR 0152) through its own Kernel, the real
 * renderer and the worker: an order shipped by the schedule rings the
 * customer's bell and emails them, with a one-click unsubscribe that turns
 * that kind's email off -- the same preference the account page's switches
 * write -- while an order delivered rings the bell alone, by default.
 *
 * Skipped when the shop cannot be built (no JavaScript dependencies).
 */

namespace Tinvar\Tests\Integration\ShopNotifications;

use Shop\Console\Tasks\Fulfilment\AdvanceShipments;
use Shop\Database\Seeders\Seeder;
use Shop\Http\Channels\Orders\OrderChanges;
use Shop\Http\Inboxes\CustomerInbox;
use Shop\Kernel;
use Shop\Notifications\Orders\OrderDelivered;
use Shop\Notifications\Orders\OrderShipped;
use Shop\Support\DemoAccount;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Mail\LogTransport;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'shop-notifications-test-key-32-bytes';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * The shop on a fresh database, its email to a log, its links on
 * https://shop.test.
 *
 * @param \Closure(Kernel, string): void $work handed the kernel and the mail log's path
 */
function shop(\Closure $work): void
{
    $directory = \Tinvar\Support\PrivateDirectory::create('tinvar-notify');
    $database = "{$directory}/shop.sqlite";
    new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 2);
    $renderer = Renderer::start('msgpack', 'notifications');
    putenv("SHOP_MAIL_LOG={$directory}/mail.log");
    putenv('SHOP_URL=https://shop.test');
    try {
        $work(Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database), "{$directory}/mail.log");
    } finally {
        putenv('SHOP_MAIL_LOG');
        putenv('SHOP_URL');
        $renderer->stop();
        \Tinvar\Support\PrivateDirectory::remove($directory);
    }
}

/** The demo customer, signed in, with the cookies their browser would hold. */
final class Customer
{
    /** @var array<string, string> */
    private array $cookies = [];

    public readonly string $id;

    public function __construct(private readonly Kernel $kernel)
    {
        $this->id = (string) $kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];
        $this->keep($kernel->handle(new Request('GET', '/sign-in', ['host' => self::host(), 'accept' => 'text/html'])));
        $this->post('/_action/account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{Response, array<string, mixed>}
     */
    public function json(string $path, array $body = []): array
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
        )->for(new Request('GET', '/', ['host' => self::host()], [], [], $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request('POST', $path, ['host' => self::host(), 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token], [], [], [...$this->cookies, '__Host-XSRF-TOKEN' => $token]),
            json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR),
        ));
    }

    /** One of their orders, put back to $status $hours ago, so the next run moves it on. */
    public function anOrder(string $status, int $hours): string
    {
        $id = (string) $this->kernel->orm->connection()->select("SELECT id FROM orders WHERE customer_id = ? AND status <> 'cancelled' LIMIT 1", [$this->id])[0]['id'];
        $this->kernel->orm->connection()->execute('UPDATE orders SET status = ?, placed_at = ? WHERE id = ?', [$status, gmdate('Y-m-d\TH:i:s\Z', time() - $hours * 3600), $id]);

        return $id;
    }

    private static function host(): string
    {
        return (string) parse_url(Kernel::baseUrl(), PHP_URL_HOST);
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

/** Moves every order on as the schedule would, then works the queue. */
function advanceAndWork(Kernel $kernel): void
{
    // Only this customer's orders move: every other is set back to placed just now.
    new AdvanceShipments()->advance($kernel->orm, new OrderChanges(notifier: $kernel->kernel()->notifier, baseUrl: 'https://shop.test'), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $lines = [];
    $output = new Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line) use (&$lines): void {
        $lines[] = $line;
    });
    expect($kernel->console->run('queue:work', ['--stop-when-empty'], $output))->toBe(0, implode("\n", $lines))
        ->and($kernel->queue->failed())->toBe([]);
}

/** Every order but $keep put back to placed just now, so the run moves $keep alone. */
function holdOthers(Kernel $kernel, string $keep): void
{
    $kernel->orm->connection()->execute("UPDATE orders SET status = 'placed', placed_at = ? WHERE id <> ?", [gmdate('Y-m-d\TH:i:s\Z'), $keep]);
}

it('rings the bell and emails the customer when the schedule ships their order, with a one-click unsubscribe for that kind', function () {
    shop(function (Kernel $kernel, string $log): void {
        $customer = new Customer($kernel);
        $order = $customer->anOrder('packed', 31);
        holdOthers($kernel, $order);
        $number = (string) $kernel->orm->connection()->select('SELECT number FROM orders WHERE id = ?', [$order])[0]['number'];

        advanceAndWork($kernel);

        [$feed, $page] = $customer->json('/_inbox/customer');
        expect($feed->status)->toBe(200)
            ->and($page['unread'])->toBe(1)
            ->and($page['entries'][0]['title'])->toBe("Order {$number} has shipped")
            ->and($page['entries'][0]['type'])->toBe('orders.shipped')
            ->and($page['entries'][0]['url'])->toBe("/orders/{$order}");

        $sent = LogTransport::read($log);
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['subject'])->toBe("Order {$number} has shipped")
            ->and((string) $sent[0]['text'])->toContain("https://shop.test/orders/{$order}")
            ->and((string) $sent[0]['listUnsubscribe'])->toStartWith('<https://')
            ->and($sent[0]['listUnsubscribePost'])->toBe('List-Unsubscribe=One-Click');

        // The mail client's one-click POST, with no session.
        $path = (string) parse_url(trim((string) $sent[0]['listUnsubscribe'], '<>'), PHP_URL_PATH);
        $unsubscribed = $kernel->handle(
            new Request('POST', $path, ['host' => (string) parse_url(Kernel::baseUrl(), PHP_URL_HOST), 'content-type' => 'application/x-www-form-urlencoded'], [], ['List-Unsubscribe' => 'One-Click'], [], null, '203.0.113.9'),
            'List-Unsubscribe=One-Click',
        );
        expect($unsubscribed->status)->toBe(200);

        [, $preferences] = $customer->json('/_inbox/customer/preferences');
        $shipped = array_values(array_filter($preferences['types'], static fn (array $t): bool => $t['type'] === 'orders.shipped'))[0];
        expect($shipped['enabled'])->toBe(['inbox']);

        // The next shipment rings the bell and sends no email.
        $kernel->orm->connection()->execute("UPDATE orders SET status = 'packed', placed_at = ? WHERE id = ?", [gmdate('Y-m-d\TH:i:s\Z', time() - 31 * 3600), $order]);
        advanceAndWork($kernel);
        expect(LogTransport::read($log))->toHaveCount(1)
            ->and($customer->json('/_inbox/customer')[1]['unread'])->toBe(2);
    });
});

it('rings the bell alone for a delivery by default, and emails it once the customer turns that on', function () {
    shop(function (Kernel $kernel, string $log): void {
        $customer = new Customer($kernel);
        $order = $customer->anOrder('shipped', 24 * 6);
        holdOthers($kernel, $order);

        advanceAndWork($kernel);
        expect(LogTransport::read($log))->toBe([])
            ->and($customer->json('/_inbox/customer')[1]['entries'][0]['type'])->toBe('orders.delivered');

        [$chosen] = $customer->json('/_inbox/customer/preferences', ['choose' => [['type' => 'orders.delivered', 'channel' => 'mail', 'enabled' => true]]]);
        expect($chosen->status)->toBe(200);
        $kernel->orm->connection()->execute("UPDATE orders SET status = 'shipped' WHERE id = ?", [$order]);
        advanceAndWork($kernel);

        expect(LogTransport::read($log))->toHaveCount(1)
            ->and(LogTransport::read($log)[0]['subject'])->toEndWith('was delivered');
    });
});

it('marks entries read for the customer, and refuses the inbox to someone signed out', function () {
    shop(function (Kernel $kernel): void {
        $customer = new Customer($kernel);
        $order = $customer->anOrder('packed', 31);
        holdOthers($kernel, $order);
        advanceAndWork($kernel);

        [, $page] = $customer->json('/_inbox/customer');
        [$read, $count] = $customer->json('/_inbox/customer/read', ['ids' => [$page['entries'][0]['id']]]);
        expect($read->status)->toBe(200)->and($count)->toBe(['unread' => 0]);

        $customer->post('/_action/account.signOut', []);
        expect($customer->json('/_inbox/customer')[0]->status)->toBe(401);
    });
});

it('is the notifications and the inbox the shop registers', function () {
    expect(Kernel::classes()->notifications)->toBe([OrderDelivered::class, OrderShipped::class])
        ->and(Kernel::classes()->inboxes)->toBe([CustomerInbox::class]);
});
