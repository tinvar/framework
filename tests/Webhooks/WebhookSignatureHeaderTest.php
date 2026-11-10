<?php

declare(strict_types=1);

/**
 * The webhook signature header's name, the application's to choose
 * (ADR 0200): `Tinvar-Signature` unless TINVAR_WEBHOOK_SIGNATURE_HEADER
 * names another, its rotation header following it, held to an HTTP token
 * and refused at boot otherwise; the sender writes it, the endpoint reads
 * it in any case, and nothing else.
 */

namespace Tinvar\Tests\Webhooks\WebhookSignatureHeader;

use Tinvar\Attributes\Webhook;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Config\WebhookSignatureHeader;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Webhooks\ArrayDeliveryLog;
use Tinvar\Webhooks\DeliverWebhook;
use Tinvar\Webhooks\DeliveryLog;
use Tinvar\Webhooks\OutboundWebhooks;
use Tinvar\Webhooks\StaticSubscriptions;
use Tinvar\Webhooks\WebhookCall;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Webhooks\WebhookSecrets;
use Tinvar\Webhooks\WebhookSignature;
use Tinvar\Webhooks\WebhookSubscription;
use Tinvar\Webhooks\WebhookSubscriptions;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

const OLD_SECRET = 'the-secret-being-rotated-out-32-bytes-long';

const NEW_SECRET = 'the-secret-rotating-in-now-32-bytes-long!!';

const NOW = 1_800_000_000;

/**
 * Runs $work with the variables set as given, each put back after.
 *
 * @param array<string, string> $env
 */
function withEnv(array $env, \Closure $work): mixed
{
    $before = [];
    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv("{$name}={$value}");
    }
    Configs::forget();

    try {
        return $work();
    } finally {
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        Configs::forget();
    }
}

#[Webhook('/hooks/in')]
final class Receives
{
    public function __invoke(WebhookCall $call): null
    {
        return null;
    }
}

final class Holding implements WebhookSecrets
{
    public function __construct(private readonly string $secret) {}

    public function secretFor(string $webhook, string $tenant): string
    {
        return $this->secret;
    }
}

/**
 * Sends one delivery through the worker, the subscription mid-rotation,
 * and answers the request it made.
 */
function sent(?WebhookSignatureHeader $header): OutboundRequest
{
    $h = new Harness();
    $http = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(204));
    $h->container
        ->instance(OutboundHttp::class, $http)
        ->instance(WebhookSubscriptions::class, new StaticSubscriptions([
            'hooks' => new WebhookSubscription('https://receiver.test/hooks/in', NEW_SECRET, previousSecret: OLD_SECRET),
        ]))
        ->instance(DeliveryLog::class, new ArrayDeliveryLog());
    if ($header !== null) {
        $h->container->instance(WebhookSignatureHeader::class, $header);
    }
    $h->orm->actAs('acme', fn (): string => new OutboundWebhooks($h->dispatcher, clock: fn (): int => $h->now)->send('hooks', 'thing.done', ['n' => 1]));
    $h->worker->run(new WorkerOptions(queues: [DeliverWebhook::QUEUE], stopWhenEmpty: true, signals: false));
    expect($http->sent)->toHaveCount(1);

    return $http->sent[0];
}

/** @param array<string, string> $headers */
function received(?WebhookSignatureHeader $header, array $headers, string $secret = OLD_SECRET, string $body = '{}'): int
{
    $h = new Harness();
    $endpoint = new WebhookEndpoint([Receives::class], $h->orm, $h->container->resolver(), new Holding($secret), new ArrayNonceStore(), clock: static fn (): int => NOW, signature: $header);
    try {
        // Lowercased as the server adapter hands a request on, whatever case it arrived in.
        return $endpoint->handle(new Request('POST', '/hooks/in', array_change_key_case($headers)), $body)->status;
    } catch (HttpException $e) {
        return $e->status;
    }
}

function signedAs(string $secret, string $body = '{}', string $nonce = 'nonce-0123456789abcdef'): string
{
    return WebhookSignature::header($secret, NOW, $nonce, 'POST', '/hooks/in', $body);
}

describe('the name', function () {
    it('is Tinvar-Signature, and its rotation header Tinvar-Signature-Next, unless the application names another', function () {
        $default = new WebhookSignatureHeader('Tinvar-Signature');

        expect(withEnv([WebhookSignatureHeader::ENV => ''], static fn (): WebhookSignatureHeader => new WebhookSignatureHeader()))->toEqual($default)
            ->and($default->name)->toBe(WebhookSignature::HEADER)
            ->and($default->next)->toBe(WebhookSignature::NEXT_HEADER)
            ->and(FrameworkConfig::load()->webhookSignature())->toEqual($default);
    });

    it('is the one TINVAR_WEBHOOK_SIGNATURE_HEADER names, the rotation header following it, in any case', function () {
        $configured = withEnv([WebhookSignatureHeader::ENV => 'Celerium-Signature'], static fn (): array => [
            new WebhookSignatureHeader(),
            FrameworkConfig::load()->webhookSignature(),
        ]);

        expect($configured[0]->name)->toBe('celerium-signature')
            ->and($configured[0]->next)->toBe('celerium-signature-next')
            ->and($configured[1])->toEqual($configured[0])
            ->and(new WebhookSignatureHeader('CELERIUM-signature'))->toEqual($configured[0]);
    });

    it('is refused when it is not an HTTP token, too long, or a header the transport or the delivery writes', function (string $name) {
        expect(static fn () => new WebhookSignatureHeader($name))->toThrow(\InvalidArgumentException::class, 'is not a webhook signature header');
    })->with([
        'empty' => [''],
        'a space' => ['Celerium Signature'],
        'a colon' => ['Celerium-Signature:'],
        'a line break' => ["Celerium-Signature\r\nX-Injected: 1"],
        'a separator' => ['Celerium(Signature)'],
        'non-ASCII' => ['Célerium-Signature'],
        'longer than 64' => [str_repeat('a', 65)],
        'the body\'s type' => ['Content-Type'],
        'the transport\'s' => ['Host'],
        'a proxy\'s' => ['Proxy-Signature'],
    ]);

    it('takes every token character, up to 64 of them', function () {
        expect(new WebhookSignatureHeader("X!#$%&'*+.^_`|~-9")->next)->toBe("x!#$%&'*+.^_`|~-9-next")
            ->and(new WebhookSignatureHeader(str_repeat('a', 64))->name)->toBe(str_repeat('a', 64));
    });

    it('refuses the application at boot, naming the variable and never the value', function () {
        try {
            Configs::load(FrameworkConfig::class, [WebhookSignatureHeader::ENV => 'Secret Value: here']);
            $refused = null;
        } catch (ConfigException $e) {
            $refused = $e;
        }

        expect($refused)->not->toBeNull()
            ->and($refused?->getMessage())->toContain(WebhookSignatureHeader::ENV)
            ->and($refused?->getMessage())->not->toContain('Secret Value');
    });
});

describe('the sender', function () {
    it('signs under Tinvar-Signature, and Tinvar-Signature-Next while a secret rotates, as it always did', function () {
        $request = sent(null);

        expect(array_keys($request->headers))->toBe(['content-type', 'tinvar-signature', 'tinvar-signature-next']);
    });

    it('signs under the configured name, the new secret under its -Next, and never under Tinvar\'s', function () {
        $request = sent(new WebhookSignatureHeader('Celerium-Signature'));
        $main = WebhookSignature::parse($request->headers['celerium-signature'] ?? null);
        $next = WebhookSignature::parse($request->headers['celerium-signature-next'] ?? null);
        \assert($main !== null && $next !== null);
        [$t, $n] = $main;

        expect(array_keys($request->headers))->toBe(['content-type', 'celerium-signature', 'celerium-signature-next'])
            ->and(implode("\n", array_keys($request->headers)) . $request->body)->not->toContain('tinvar')
            // The MACs themselves are as they were: old secret in the main header, new beside it.
            ->and($main[2])->toBe(WebhookSignature::mac(OLD_SECRET, $t, $n, 'POST', '/hooks/in', $request->body))
            ->and($next[2])->toBe(WebhookSignature::mac(NEW_SECRET, $t, $n, 'POST', '/hooks/in', $request->body));
    });

    it('reads the name from the environment when the application registers none', function () {
        $request = withEnv([WebhookSignatureHeader::ENV => 'Celerium-Signature'], static fn (): OutboundRequest => sent(null));

        expect(array_keys($request->headers))->toBe(['content-type', 'celerium-signature', 'celerium-signature-next']);
    });
});

describe('the endpoint', function () {
    it('takes a delivery signed under its configured name or that name\'s -Next, in any case', function () {
        $celerium = new WebhookSignatureHeader('Celerium-Signature');

        expect(received($celerium, ['celerium-signature' => signedAs(OLD_SECRET)]))->toBe(204)
            ->and(received(new WebhookSignatureHeader('CELERIUM-signature'), ['celerium-signature' => signedAs(OLD_SECRET)]))->toBe(204)
            ->and(received($celerium, ['Celerium-Signature' => signedAs(OLD_SECRET)]))->toBe(204)
            ->and(received($celerium, ['celerium-signature' => signedAs(NEW_SECRET), 'celerium-signature-next' => signedAs(OLD_SECRET)]))->toBe(204)
            ->and(received($celerium, ['CELERIUM-SIGNATURE-NEXT' => signedAs(OLD_SECRET)]))->toBe(204);
    });

    it('reads no other name: Tinvar\'s, beside it or alone, is no signature at all', function () {
        $celerium = new WebhookSignatureHeader('Celerium-Signature');

        expect(received($celerium, ['tinvar-signature' => signedAs(OLD_SECRET)]))->toBe(401)
            ->and(received($celerium, ['tinvar-signature-next' => signedAs(OLD_SECRET)]))->toBe(401)
            // The configured header's wrong MAC is not rescued by a right one under the default's name.
            ->and(received($celerium, ['celerium-signature' => signedAs(NEW_SECRET), 'tinvar-signature' => signedAs(OLD_SECRET)]))->toBe(401)
            ->and(received(null, ['celerium-signature' => signedAs(OLD_SECRET)]))->toBe(401)
            ->and(received(null, ['tinvar-signature' => signedAs(OLD_SECRET)]))->toBe(204);
    });

    it('holds a delivery under the configured name to the same MAC, window and nonce as ever', function () {
        $h = new Harness();
        $celerium = new WebhookSignatureHeader('Celerium-Signature');
        $now = NOW;
        $endpoint = new WebhookEndpoint([Receives::class], $h->orm, $h->container->resolver(), new Holding(OLD_SECRET), new ArrayNonceStore(), clock: static function () use (&$now): int {
            return $now;
        }, signature: $celerium);
        $status = static function (array $headers, string $body = '{}') use ($endpoint): int {
            try {
                return $endpoint->handle(new Request('POST', '/hooks/in', $headers), $body)->status;
            } catch (HttpException $e) {
                return $e->status;
            }
        };
        $signed = ['celerium-signature' => signedAs(OLD_SECRET)];

        expect($status($signed, '{"edited":true}'))->toBe(401)
            ->and($status($signed))->toBe(204)
            ->and($status($signed))->toBe(409);
        $now = NOW + WebhookEndpoint::TOLERANCE + 1;
        expect($status(['celerium-signature' => signedAs(OLD_SECRET, nonce: 'another-nonce-0123456')]))->toBe(401);
    });

    it('reads the name from the environment when it is given none', function () {
        expect(withEnv([WebhookSignatureHeader::ENV => 'Celerium-Signature'], static fn (): array => [
            received(null, ['celerium-signature' => signedAs(OLD_SECRET)]),
            received(null, ['tinvar-signature' => signedAs(OLD_SECRET)]),
        ]))->toBe([204, 401]);
    });
});
