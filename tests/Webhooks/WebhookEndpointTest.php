<?php

declare(strict_types=1);

/**
 * The inbound webhook endpoint with nothing of tinvar/tenancy installed.
 *
 * A webhook declaring no tenant runs as none; one declaring a tenant
 * source needs a tenancy to read it, and is refused when the endpoint is
 * built without one (ADR 0056). With a tenancy -- here the framework
 * suite's stand-in -- it runs as the tenant read. The signed URLs and hosts
 * that place a delivery in a tenant are tenancy's, and tested with it
 * (tinvar/tenancy's WebhooksTest).
 */

namespace Tinvar\Tests\Webhooks\WebhookEndpoint;

use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Webhook;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Support\Fixtures\Sources;
use Tinvar\Webhooks\ArrayNonceStore;
use Tinvar\Webhooks\WebhookCall;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Webhooks\WebhookSecrets;
use Tinvar\Webhooks\WebhookSignature;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';
require_once __DIR__ . '/../Support/Fixtures/tenants.php';

const NOW = 1_800_000_000;

const SECRET = 'the-senders-signing-secret-of-32-bytes';

/** The tenant a delivery's query names, plainly: a stand-in source. */
final class FromQuery
{
    public function tenantFor(Request $request): ?string
    {
        $tenant = $request->query['tenant'] ?? null;

        return \is_string($tenant) ? $tenant : null;
    }
}

/** Records the tenant each delivery ran as. */
abstract class Records
{
    public function __construct(private readonly Journal $journal, private readonly \Tinvar\Orm\Orm $orm) {}

    public function __invoke(WebhookCall $call): ?Response
    {
        $this->journal->entries[] = ['call' => $call->tenant, 'orm' => $this->orm->tenant()];

        return null;
    }
}

#[Webhook('/hooks/plain')]
final class PlainHook extends Records {}

#[Webhook('/hooks/placed'), TenantFrom(FromQuery::class)]
final class PlacedHook extends Records {}

final class OneSecret implements WebhookSecrets
{
    public function secretFor(string $webhook, string $tenant): string
    {
        return SECRET;
    }
}

/** @param list<class-string> $hooks */
function endpoint(Harness $h, array $hooks, bool $tenancy = false): WebhookEndpoint
{
    return new WebhookEndpoint(
        $hooks,
        $h->orm,
        $h->container->resolver(),
        new OneSecret(),
        new ArrayNonceStore(),
        clock: static fn (): int => NOW,
        tenants: $tenancy ? new Sources($h->container->resolver()) : null,
    );
}

function delivery(string $path, string $query, string $body, ?string $nonce = null): Request
{
    $target = $query === '' ? $path : "{$path}?{$query}";
    parse_str($query, $parsed);
    /** @var array<string, string> $parsed */

    return new Request(
        'POST',
        $path,
        ['content-type' => 'application/json', WebhookSignature::HEADER => WebhookSignature::header(SECRET, NOW, $nonce ?? bin2hex(random_bytes(12)), 'POST', $target, $body)],
        $parsed,
        [],
        [],
        $query === '' ? null : $query,
    );
}

it('runs a verified delivery to a webhook declaring no tenant as no tenant', function () {
    $h = new Harness();
    $body = '{"event":"ping"}';

    expect(endpoint($h, [PlainHook::class])->handle(delivery('/hooks/plain', '', $body), $body)->status)->toBe(204)
        ->and($h->journal()->entries)->toBe([['call' => '', 'orm' => '']]);
});

it('refuses a delivery whose signature does not verify, or that is replayed', function () {
    $h = new Harness();
    $endpoint = endpoint($h, [PlainHook::class]);
    $body = '{"event":"ping"}';
    $request = delivery('/hooks/plain', '', $body, 'nonce-0123456789abcdef');

    expect(fn () => $endpoint->handle($request, '{"event":"edited"}'))->toThrow(HttpException::class, 'does not verify')
        ->and($endpoint->handle($request, $body)->status)->toBe(204)
        ->and(fn () => $endpoint->handle($request, $body))->toThrow(HttpException::class);
});

it('refuses, when it is built, a webhook declaring a tenant source with no tenancy to read it', function () {
    expect(fn () => endpoint(new Harness(), [PlacedHook::class]))->toThrow(\InvalidArgumentException::class, 'no tenancy to read it');
});

it('runs one as the tenant the registered tenancy reads, and refuses a delivery it places nowhere', function () {
    $h = new Harness();
    $endpoint = endpoint($h, [PlacedHook::class], tenancy: true);
    $body = '{"event":"ping"}';

    expect($endpoint->handle(delivery('/hooks/placed', 'tenant=acme', $body), $body)->status)->toBe(204)
        ->and($h->journal()->entries)->toBe([['call' => 'acme', 'orm' => 'acme']])
        ->and(fn () => $endpoint->handle(delivery('/hooks/placed', '', $body), $body))->toThrow(HttpException::class, 'no tenant');
});
