<?php

declare(strict_types=1);

/**
 * The shop's order webhook: queued in the transaction that places the
 * order, sent by the queue worker through OutboundHttp, signed as a Tinvar
 * receiver verifies it, and recorded with its status and timing alone --
 * and never sent to the shop's own machine, whatever URL it is given.
 *
 * Skipped when the shop cannot be built (no JavaScript dependencies): the
 * worker renders the order's confirmation email on the way.
 */

namespace Tinvar\Tests\Integration\ShopOrderWebhook;

use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Http\Webhooks\Warehouse\OrderEvent;
use Shop\Http\Webhooks\Warehouse\OrderWebhook;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\PdoConnection;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Support\Signer;
use Tinvar\Webhooks\DeliverWebhook;
use Tinvar\Webhooks\WebhookSignature;

const KEY = 'shop-order-webhook-test-key-32-bytes!';

const SECRET = 'pellwick-fulfilment-secret-of-32-bytes';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * The shop on a fresh database, its order webhook pointed at $url (none
 * when null) and sent by $outbound (the Kernel's own PinnedHttp when null).
 *
 * @param \Closure(Kernel): void $work
 */
function shop(?string $url, ?OutboundHttp $outbound, \Closure $work): void
{
    $directory = \Tinvar\Support\PrivateDirectory::create('tinvar-hookshop');
    $database = "{$directory}/shop.sqlite";
    new \Shop\Database\Seeders\Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 2);
    $renderer = Renderer::start('msgpack', 'order-webhook');
    putenv("SHOP_MAIL_LOG={$directory}/mail.log");
    putenv('SHOP_URL=https://shop.test');
    if ($url !== null) {
        putenv("SHOP_ORDER_WEBHOOK={$url}");
        putenv('SHOP_ORDER_WEBHOOK_SECRET=' . SECRET);
    }
    try {
        $work(Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database, outbound: $outbound));
    } finally {
        putenv('SHOP_MAIL_LOG');
        putenv('SHOP_URL');
        putenv('SHOP_ORDER_WEBHOOK');
        putenv('SHOP_ORDER_WEBHOOK_SECRET');
        $renderer->stop();
        \Tinvar\Support\PrivateDirectory::remove($directory);
    }
}

/** Signs in as the demo customer and places an order for two of something in stock; returns the order's id. */
function placeOrder(Kernel $kernel): string
{
    $cookies = [];
    $keep = static function (Response $response) use (&$cookies): Response {
        foreach ($response->cookies() as $name => $line) {
            $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    };
    $action = static function (string $name, array $body) use ($kernel, &$cookies, $keep): Response {
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $cookies));

        return $keep($kernel->handle(
            new Request('POST', "/_action/{$name}", ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token], [], [], [...$cookies, '__Host-XSRF-TOKEN' => $token]),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    };

    $keep($kernel->handle(new Request('GET', '/sign-in', ['accept' => 'text/html'])));
    $action('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/']);
    $product = (string) $kernel->orm->connection()->select('SELECT id FROM products WHERE stock > 0 LIMIT 1')[0]['id'];
    $placed = $action('orders.place', ['product' => $product, 'quantity' => 2]);
    expect($placed->status)->toBe(303);

    return substr((string) $placed->header('location'), \strlen('/orders/'));
}

function work(Kernel $kernel): string
{
    $lines = [];
    $output = new Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line) use (&$lines): void {
        $lines[] = $line;
    });
    expect($kernel->console->run('queue:work', ['--stop-when-empty'], $output))->toBe(0);

    return implode("\n", $lines);
}

it('queues the order webhook with the order, and the worker sends it signed, keeping its status and timing alone', function () {
    $fake = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(202, ['x-receiver' => ['abc']], 'thanks', 15));

    shop('https://fulfilment.example.test/hooks/pellwick?store=1', $fake, function (Kernel $kernel) use ($fake): void {
        $id = placeOrder($kernel);
        $number = (string) $kernel->orm->connection()->select('SELECT number FROM orders WHERE id = ?', [$id])[0]['number'];

        // Queued, not sent: the customer's redirect waited on no receiver.
        expect($kernel->queue->size(DeliverWebhook::QUEUE))->toBe(1)
            ->and($fake->sent)->toBe([]);

        $log = work($kernel);

        expect($fake->sent)->toHaveCount(1, $log);
        $request = $fake->sent[0];
        \assert($request instanceof OutboundRequest);
        $signature = WebhookSignature::parse($request->headers[WebhookSignature::HEADER] ?? null);
        \assert($signature !== null);
        $body = json_decode($request->body, true);

        expect($request->url)->toBe('https://fulfilment.example.test/hooks/pellwick?store=1')
            ->and(hash_equals(WebhookSignature::mac(SECRET, $signature[0], $signature[1], 'POST', '/hooks/pellwick?store=1', $request->body), $signature[2]))->toBeTrue()
            ->and($body['event'])->toBe(OrderEvent::Placed->value)
            ->and($body['data']['id'])->toBe($id)
            ->and($body['data']['number'])->toBe($number)
            ->and($body['data']['lines'][0]['quantity'])->toBe(2)
            // Nothing of the customer leaves with it.
            ->and($request->body)->not->toContain(DemoAccount::EMAIL)
            ->and($request->body)->not->toContain(DemoAccount::NAME)
            ->and($kernel->orm->connection()->select('SELECT tenant, attempt, outcome, status, reason, milliseconds FROM webhook_deliveries'))->toBe([
                ['tenant' => '', 'attempt' => 1, 'outcome' => 'delivered', 'status' => 202, 'reason' => null, 'milliseconds' => 15],
            ])
            ->and($kernel->queue->size(DeliverWebhook::QUEUE))->toBe(0)
            ->and($kernel->queue->failed())->toBe([]);
    });
});

it('queues nothing where no order webhook is configured', function () {
    $fake = new FakeOutboundHttp();

    shop(null, $fake, function (Kernel $kernel) use ($fake): void {
        placeOrder($kernel);

        expect($kernel->queue->size(DeliverWebhook::QUEUE))->toBe(0);
        work($kernel);
        expect($fake->sent)->toBe([]);
    });
});

it('refuses to send the order webhook to the shop\'s own machine, and does not try again', function () {
    shop('http://127.0.0.1:9999/hooks/pellwick', null, function (Kernel $kernel): void {
        placeOrder($kernel);
        work($kernel);

        expect($kernel->orm->connection()->select('SELECT outcome, status, reason FROM webhook_deliveries'))->toBe([
            ['outcome' => 'refused', 'status' => null, 'reason' => null],
        ])
            ->and($kernel->queue->size(DeliverWebhook::QUEUE))->toBe(0)
            ->and($kernel->queue->failed())->toHaveCount(1)
            ->and($kernel->queue->failed()[0]->error)->toContain('loopback');
    });
});

it('refuses an order webhook configured without a secret to sign it with', function () {
    putenv('SHOP_ORDER_WEBHOOK=https://fulfilment.example.test/hooks/pellwick');
    try {
        expect(fn () => OrderWebhook::subscriptionFromEnvironment())->toThrow(\Tinvar\Config\ConfigException::class, 'SHOP_ORDER_WEBHOOK needs SHOP_ORDER_WEBHOOK_SECRET');
        putenv('SHOP_ORDER_WEBHOOK_SECRET=short');
        expect(fn () => OrderWebhook::subscriptionFromEnvironment())->toThrow(\Tinvar\Config\ConfigException::class, 'SHOP_ORDER_WEBHOOK_SECRET must be at least 32 bytes');
    } finally {
        putenv('SHOP_ORDER_WEBHOOK');
        putenv('SHOP_ORDER_WEBHOOK_SECRET');
    }
});
