<?php

declare(strict_types=1);

/**
 * A webhook whose sender signs in its own way names a WebhookVerifier on its
 * #[Webhook], which the endpoint asks in place of Tinvar's own signature
 * (ADR 0150). The endpoint's other steps stay its own: the tenant before,
 * the nonce after -- claimed in the NonceStore, a replay a 409, released
 * when the handler throws -- and the handler is told which verifier passed.
 */

namespace Tinvar\Tests\Webhooks\WebhookVerifier;

use Tinvar\Attributes\Webhook;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\DatabaseNonceStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Webhooks\VerifiedDelivery;
use Tinvar\Webhooks\WebhookCall;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Webhooks\WebhookSecrets;
use Tinvar\Webhooks\WebhookVerifier;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

const NOW = 1_800_000_000;

/** A sender that signs `X-Sum: <timestamp>.<id>.<sha256 of secret, timestamp, id, body>`. */
final class SumVerifier implements WebhookVerifier
{
    public const string SECRET = 'the-sum-senders-secret';

    public static int $asked = 0;

    public function verify(string $webhook, string $tenant, Request $request, string $rawBody, int $now): VerifiedDelivery
    {
        self::$asked++;
        $parts = explode('.', (string) $request->header('x-sum'));
        if (\count($parts) !== 3 || !hash_equals(hash('sha256', self::SECRET . "{$parts[0]}.{$parts[1]}.{$rawBody}"), $parts[2])) {
            throw new HttpException(401, 'The sum does not verify.', 'webhook_signature');
        }

        return new VerifiedDelivery((int) $parts[0], $parts[1], (int) $parts[0] + 300, VerifiedDelivery::signerOf(self::SECRET));
    }

    public static function header(int $timestamp, string $id, string $body): string
    {
        return "{$timestamp}.{$id}." . hash('sha256', self::SECRET . "{$timestamp}.{$id}.{$body}");
    }
}

#[Webhook('/hooks/sum', verifier: SumVerifier::class)]
final class SumHook
{
    public function __construct(private readonly Journal $journal) {}

    public function __invoke(WebhookCall $call): ?Response
    {
        if (str_contains($call->body, 'fail')) {
            throw new \RuntimeException('handler failed');
        }
        $this->journal->entries[] = ['nonce' => $call->nonce, 'verifier' => $call->verifier, 'timestamp' => $call->timestamp];

        return null;
    }
}

#[Webhook('/hooks/not-a-verifier', verifier: \stdClass::class)]
final class MisdeclaredHook
{
    public function __invoke(WebhookCall $call): void {}
}

/** Never asked: a declared verifier stands in for the secrets. */
final class NoSecrets implements WebhookSecrets
{
    public function secretFor(string $webhook, string $tenant): ?string
    {
        throw new \LogicException('The secrets were asked for a webhook that declares its own verifier.');
    }
}

function endpoint(Harness $h): WebhookEndpoint
{
    return new WebhookEndpoint([SumHook::class], $h->orm, $h->container->resolver(), new NoSecrets(), new ArrayNonceStore(), clock: static fn (): int => NOW);
}

function delivery(string $body, ?string $header = null): Request
{
    return new Request('POST', '/hooks/sum', ['content-type' => 'application/json', 'x-sum' => $header ?? SumVerifier::header(NOW, 'evt_0001', $body)]);
}

beforeEach(function () {
    SumVerifier::$asked = 0;
});

it('runs the handler once its declared verifier proves the delivery, and says which verifier did', function () {
    $h = new Harness();

    $response = endpoint($h)->handle(delivery('{"ok":true}'), '{"ok":true}');

    expect($response->status)->toBe(204)
        ->and(SumVerifier::$asked)->toBe(1)
        ->and($h->journal()->entries)->toBe([['nonce' => 'evt_0001', 'verifier' => SumVerifier::class, 'timestamp' => NOW]]);
});

it('refuses what the verifier refuses, and runs nothing', function () {
    $h = new Harness();

    expect(fn () => endpoint($h)->handle(delivery('{"ok":true}', NOW . '.evt_0001.' . str_repeat('0', 64)), '{"ok":true}'))
        ->toThrow(fn (HttpException $e) => expect($e->status)->toBe(401))
        ->and($h->journal()->entries)->toBe([]);
});

it('takes the nonce the verifier proved as any other: a replay is a 409', function () {
    $h = new Harness();
    $endpoint = endpoint($h);
    $endpoint->handle(delivery('{}'), '{}');

    expect(fn () => $endpoint->handle(delivery('{}'), '{}'))
        ->toThrow(fn (HttpException $e) => expect([$e->status, $e->code_])->toBe([409, 'webhook_replayed']))
        ->and($h->journal()->entries)->toHaveCount(1);
});

it('releases the nonce when the handler throws, so the sender may try again', function () {
    $h = new Harness();
    $endpoint = endpoint($h);

    expect(fn () => $endpoint->handle(delivery('{"fail":1}'), '{"fail":1}'))->toThrow(\RuntimeException::class);
    expect(fn () => $endpoint->handle(delivery('{"fail":1}'), '{"fail":1}'))->toThrow(\RuntimeException::class, 'handler failed');
});

it('refuses, when it is built, a verifier that is no WebhookVerifier', function () {
    $h = new Harness();

    expect(fn () => new WebhookEndpoint([MisdeclaredHook::class], $h->orm, $h->container->resolver(), new NoSecrets(), new ArrayNonceStore()))
        ->toThrow(\InvalidArgumentException::class, 'is no Tinvar\Webhooks\WebhookVerifier');
});

it('refuses a verified delivery with no usable nonce or a signer that is not a digest', function (string $nonce, string $signer) {
    expect(fn () => new VerifiedDelivery(NOW, $nonce, NOW + 300, $signer))->toThrow(\InvalidArgumentException::class);
})->with([
    'a short nonce' => ['abc', VerifiedDelivery::signerOf('s')],
    'a nonce with a space' => ['evt 00001', VerifiedDelivery::signerOf('s')],
    // Longer than a nonce store holds: verified, it was then refused by the
    // store as a replay, and the sender's delivery lost with a 409.
    'a nonce longer than a nonce store keeps' => ['evt_' . str_repeat('a', DatabaseNonceStore::MAX_NONCE_BYTES - 3), VerifiedDelivery::signerOf('s')],
    'the secret itself as the signer' => ['evt_00001', 'the-secret'],
]);

it('takes a nonce as long as a nonce store keeps, and the store claims it', function () {
    $nonce = 'evt_' . str_repeat('a', DatabaseNonceStore::MAX_NONCE_BYTES - 4);
    $connection = \Tinvar\Orm\PdoConnection::sqlite(':memory:', 'nonce-length');
    DatabaseNonceStore::define(new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection)));

    expect(new VerifiedDelivery(NOW, $nonce, NOW + 300, VerifiedDelivery::signerOf('s'))->nonce)->toBe($nonce)
        ->and(new DatabaseNonceStore($connection)->claim('webhook', $nonce, NOW + 300, NOW))->toBeTrue();
});
