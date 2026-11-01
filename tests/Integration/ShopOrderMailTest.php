<?php

declare(strict_types=1);

/**
 * The shop's background work through the real renderer, without a browser:
 * the order confirmation drawn from @tinvar/mail's kit and held to MER-36,
 * queued in the transaction that places the order and sent by the worker,
 * and the scheduled task that moves orders along their shipping timeline.
 *
 * Skipped when the shop cannot be built (no JavaScript dependencies).
 */

namespace Tinvar\Tests\Integration\ShopOrderMail;

use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Mail\Links;
use Shop\Mail\OrderConfirmation;
use Shop\Mail\SendOrderConfirmation;
use Shop\Database\Models\Orders\Order;
use Shop\Http\Channels\Orders\OrderChanges;
use Shop\Tasks\AdvanceShipments;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\Invalidator;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\Topics;
use Tinvar\Mail\LogTransport;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'shop-order-mail-test-key-32-bytes!!!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * The shop on a fresh database, its email to a log, its links on
 * https://shop.test; $work is handed the kernel, the log's path and the
 * database's.
 *
 * @param \Closure(Kernel, string, string): void $work
 */
function shop(\Closure $work): void
{
    $directory = \Tinvar\Support\PrivateDirectory::create('tinvar-mailshop');
    $database = "{$directory}/shop.sqlite";
    new \Shop\Database\Seeders\Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 2);
    $renderer = Renderer::start('msgpack', 'order-mail');
    putenv("SHOP_MAIL_LOG={$directory}/mail.log");
    putenv('SHOP_URL=https://shop.test');
    try {
        $work(Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database), "{$directory}/mail.log", $database);
    } finally {
        putenv('SHOP_MAIL_LOG');
        putenv('SHOP_URL');
        $renderer->stop();
        \Tinvar\Support\PrivateDirectory::remove($directory);
    }
}

/** A signed-in customer's requests: the sign-in action's cookies kept, and CSRF answered. */
final class Customer
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly Kernel $kernel)
    {
        $this->keep($kernel->handle(new Request('GET', '/sign-in', ['accept' => 'text/html'])));
        $this->action('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/']);
    }

    /** @param array<string, mixed> $body */
    public function action(string $name, array $body): Response
    {
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
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

it('draws the order confirmation from @tinvar/mail\'s kit as MER-36 asks: inline styles, tables, both parts, every link in the text', function () {
    shop(function (Kernel $kernel): void {
        $id = (string) $kernel->orm->connection()->select('SELECT id FROM orders ORDER BY placed_at DESC LIMIT 1')[0]['id'];
        $order = $kernel->orm->find(Order::class, $id);
        \assert($order instanceof Order);

        $preferences = 'https://shop.test' . Links::PREFERENCES_LINK . '?token=v1.preferences.token';
        $message = $kernel->mailer->send(new OrderConfirmation($order, 'https://shop.test', $preferences));

        // The subject and the first line say what happened (MER-36.4).
        expect($message->subject)->toMatch('/^Order PW-\d{6}-\d{4,} placed: \$[\d,]+\.\d{2}$/')
            ->and($message->text)->toContain("Order {$order->number} is placed");

        // Inline styles and table layout (MER-36.1): every colour inline, so
        // a client that strips the head keeps them. The one stylesheet is
        // the kit's dark palette over them (ADR 0116), and the only classes
        // are the ones it names.
        preg_match_all('/\sclass="([^"]*)"/', $message->html, $classes);
        expect($message->html)->toContain('style="')
            ->and($message->html)->toContain('<table')
            ->and(array_unique(explode(' ', implode(' ', $classes[1]))))->each->toStartWith('tm-')
            ->and(substr_count($message->html, '<style'))->toBe(1)
            ->and($message->html)->toContain('@media (prefers-color-scheme: dark)')
            ->and($message->html)->toContain('<meta name="color-scheme" content="light dark">');

        // The logo as a hosted PNG, why the reader is getting it, how to
        // stop, and the postal address (MER-36.3) -- in both parts.
        expect($message->html)->toContain('src="https://shop.test/brand/mark-64.png"')
            ->and($message->html)->toContain('Why am I getting this?')
            ->and($message->text)->toContain("Why am I getting this? You placed order {$order->number} at Pellwick Supply as " . DemoAccount::EMAIL)
            // A confirmation is on no list: the footer links to the reader's
            // marketing preferences instead, and says so (ADR 0116).
            ->and($message->html)->toContain('>Manage your marketing email</a>')
            ->and($message->text)->toContain("Manage your marketing email: {$preferences}")
            ->and($message->html)->not->toContain('mailto:')
            ->and($message->text)->toContain('Pellwick Supply · 1 Example Way · Springfield, ST 00000');

        // A plain-text part with every link as text (MER-36.2), and on the
        // shop's canonical address, never a request's Host header.
        expect(array_filter(\Tinvar\Mail\RenderedMail::links($message->html), static fn (string $l): bool => str_starts_with($l, 'http')))
            ->each->toStartWith('https://shop.test/')
            ->and($message->text)->toContain("https://shop.test/orders/{$order->id}")
            ->and($message->text)->not->toContain('<');
        foreach (\Tinvar\Mail\RenderedMail::links($message->html) as $link) {
            expect($message->text)->toContain($link);
        }
        foreach ($order->lines as $line) {
            expect($message->text)->toContain("{$line->quantity} × {$line->productName}");
        }
    });
});

it('queues the confirmation with the order, and the worker sends it to the customer', function () {
    shop(function (Kernel $kernel, string $log): void {
        $customer = new Customer($kernel);
        $product = (string) $kernel->orm->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id'];

        $placed = $customer->action('orders.place', ['product' => $product, 'quantity' => 2]);
        $id = substr((string) $placed->header('location'), \strlen('/orders/'));
        $number = (string) $kernel->orm->connection()->select('SELECT number FROM orders WHERE id = ?', [$id])[0]['number'];

        // Queued, not sent: the customer's redirect did not wait on the
        // renderer or a mail server.
        expect($placed->status)->toBe(303)
            ->and($kernel->queue->size('mail'))->toBe(1)
            ->and(LogTransport::read($log))->toBe([]);

        $lines = [];
        $output = new Output(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        expect($kernel->console->run('queue:work', ['--stop-when-empty'], $output))->toBe(0);

        $sent = LogTransport::read($log);
        expect($sent)->toHaveCount(1, implode("\n", $lines))
            ->and($sent[0]['subject'])->toStartWith("Order {$number} placed: \$")
            ->and($sent[0]['to'])->toBe(['"' . DemoAccount::NAME . '" <' . DemoAccount::EMAIL . '>'])
            ->and($sent[0]['from'])->toBe('"Pellwick Supply" <orders@pellwick.test>')
            ->and((string) $sent[0]['text'])->toContain("https://shop.test/orders/{$id}")
            // Its footer opens the customer's preferences, by a token made on
            // the worker and bound to this address (ADR 0116).
            ->and((string) $sent[0]['text'])->toContain('Manage your marketing email: https://shop.test' . Links::PREFERENCES_LINK . '?token=v1.')
            ->and($kernel->queue->size('mail'))->toBe(0)
            ->and($kernel->queue->failed())->toBe([]);
    });
});

it('sends no confirmation for an order whose transaction rolled back', function () {
    shop(function (Kernel $kernel): void {
        $id = (string) $kernel->orm->connection()->select('SELECT id FROM orders LIMIT 1')[0]['id'];
        $order = $kernel->orm->find(Order::class, $id);
        \assert($order instanceof Order);

        try {
            $kernel->orm->transaction(function () use ($kernel, $order): void {
                $kernel->jobs->dispatch(new SendOrderConfirmation($order));

                throw new \RuntimeException('the order was not placed after all');
            });
        } catch (\RuntimeException) {
        }

        expect($kernel->queue->size('mail'))->toBe(0);
    });
});

it('moves orders along the shipping timeline as they age, and tells the pages showing them once it commits', function () {
    $directory = \Tinvar\Support\PrivateDirectory::create('tinvar-ship');
    try {
        $connection = PdoConnection::sqlite("{$directory}/shop.sqlite", 'shop', create: true);
        new \Shop\Database\Seeders\Seeder($connection)->run(customers: 1);
        $orm = new Orm($connection, new IdentityMap('shop'));
        $hub = new MemoryHub();
        $changes = new OrderChanges(new Invalidator($hub, new Topics(new Signer(KEY, Topics::PURPOSE)), $orm));
        $now = new \DateTimeImmutable('2026-10-24T12:00:00Z');
        $placedAgo = static fn (int $hours): string => $now->modify("-{$hours} hours")->format('Y-m-d\TH:i:s\Z');
        $customer = (string) $connection->select('SELECT id FROM customers LIMIT 1')[0]['id'];
        $connection->execute('DELETE FROM order_lines');
        $connection->execute('DELETE FROM orders');
        foreach ([['a', 'placed', 2], ['b', 'placed', 7], ['c', 'packed', 31], ['d', 'placed', 200], ['e', 'cancelled', 200], ['f', 'shipped', 100]] as [$number, $status, $hours]) {
            $connection->execute(
                'INSERT INTO orders (id, number, customer_id, status, placed_at, subtotal_cents, shipping_cents, total_cents) VALUES (?, ?, ?, ?, ?, 100, 0, 100)',
                [\Tinvar\Support\Uuid::v7(), $number, $customer, $status, $placedAgo($hours)],
            );
        }

        $orm->transaction(function () use ($orm, $changes, $now, $hub): void {
            expect(new AdvanceShipments()->advance($orm, $changes, $now))->toBe(['packed' => 2, 'shipped' => 2, 'delivered' => 1])
                ->and($hub->published)->toBe([], 'not before the commit');
        });

        $statuses = array_column($connection->select('SELECT number, status FROM orders ORDER BY number'), 'status', 'number');
        expect($statuses)->toBe(['a' => 'placed', 'b' => 'packed', 'c' => 'shipped', 'd' => 'delivered', 'e' => 'cancelled', 'f' => 'shipped'])
            // Each moved order's page and timeline, its customer's orders,
            // and staff's list of everyone's: one topic each.
            ->and($hub->published)->toHaveCount(3 + 1 + 1);
    } finally {
        \Tinvar\Support\PrivateDirectory::remove($directory);
    }
});
