<?php

declare(strict_types=1);

/**
 * Inbound webhooks: a delivery proves itself -- its signed URL places it in
 * a tenant, its signature verifies, its timestamp is fresh and its nonce is
 * new -- before anything of the handler runs.
 *
 * "#[TenantFrom(Signature::class)]" is the spec's source for "an entry
 * point reached with no session behind it ... a webhook"; replay
 * protection is a timestamp tolerance and a nonce.
 */

namespace Tinvar\Tests\Webhooks\Webhooks;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Webhook;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\HttpException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Signature;
use Tinvar\Tenancy\SignedUrls;
use Tinvar\Tenancy\TenantHosts;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Webhooks\ArrayNonceStore;
use Tinvar\Webhooks\DatabaseNonceStore;
use Tinvar\Webhooks\DerivedSecrets;
use Tinvar\Webhooks\NonceStore;
use Tinvar\Webhooks\WebhookCall;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Webhooks\WebhookSecrets;
use Tinvar\Webhooks\WebhookSignature;

use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

const KEY = 'webhooks-test-key-of-thirty-two-bytes';

const NOW = 1_800_000_000;

/** Records the tenant's scripts it sees, and what it was sent. */
#[Webhook('/webhooks/scripts'), TenantFrom(Signature::class), Panel('admin')]
final class ScriptsHook
{
    public function __construct(private readonly Journal $journal, private readonly \Tinvar\Orm\Orm $orm, private readonly \Tinvar\Auth\PanelContext $panels) {}

    public function __invoke(WebhookCall $call): ?Response
    {
        $data = $call->json();
        if (($data['fail'] ?? false) === true) {
            throw new \RuntimeException('handler failed');
        }
        $this->journal->entries[] = [
            'tenant' => $call->tenant,
            'scripts' => \count($this->orm->all(Script::class)),
            'panel' => $this->panels->currentOrNull(),
            'event' => $data['event'] ?? null,
        ];

        return null;
    }
}

/** Placed by the host it is sent to, and signed with one secret for every tenant: a provider's, say. */
#[Webhook('/webhooks/provider'), TenantFrom(Host::class)]
final class ProviderHook
{
    public function __construct(private readonly Journal $journal) {}

    public function __invoke(WebhookCall $call): ?Response
    {
        if (($call->json()['fail'] ?? false) === true) {
            throw new \RuntimeException('handler failed');
        }
        $this->journal->entries[] = ['tenant' => $call->tenant];

        return null;
    }
}

/** acme.shop.test is acme's, globex.shop.test globex's. */
final class ProviderHosts implements TenantHosts
{
    public function tenantForHost(string $host): ?string
    {
        return ['acme.shop.test' => 'acme', 'globex.shop.test' => 'globex'][$host] ?? null;
    }
}

/** The provider's one secret, whichever tenant the delivery is for. */
final class OneSecret implements WebhookSecrets
{
    public const string SECRET = 'the-provider-signs-every-tenant-with-this';

    public function secretFor(string $webhook, string $tenant): string
    {
        return self::SECRET;
    }
}

/** A middleware that would sign a visitor in: never run for a webhook. */
final class GlobalStack implements Middleware
{
    public static int $ran = 0;

    public function handle(Request $request, \Closure $next): Response
    {
        self::$ran++;

        return $next($request);
    }
}

final class Setup
{
    public Harness $h;
    public WebhookEndpoint $endpoint;
    public SignedUrls $urls;
    public DerivedSecrets $secrets;
    public NonceStore $nonces;
    public int $now = NOW;

    public function __construct(?NonceStore $nonces = null)
    {
        $this->h = new Harness();
        $this->urls = new SignedUrls(new Signer(KEY, SignedUrls::PURPOSE));
        $this->secrets = new DerivedSecrets(new Signer(KEY, DerivedSecrets::PURPOSE));
        $this->nonces = $nonces ?? new ArrayNonceStore();
        $this->h->container->instance(Signature::class, new Signature($this->urls));
        $this->endpoint = new WebhookEndpoint(
            [ScriptsHook::class],
            $this->h->orm,
            $this->h->container->resolver(),
            $this->secrets,
            $this->nonces,
            $this->h->panels,
            $this->h->registry,
            fn (): int => $this->now,
        );
    }

    /** The URL a tenant's sender is given. */
    public function url(string $tenant): string
    {
        return $this->urls->sign('/webhooks/scripts', $tenant, ttl: 86_400 * 365, now: NOW);
    }

    /**
     * A delivery to $url, signed as its tenant's sender signs it unless told otherwise.
     *
     * @param array<string, string> $override
     */
    public function delivery(string $url, string $body, array $override = []): Request
    {
        $target = $override['target'] ?? $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $tenant = $override['signedAs'] ?? (string) $query['tenant'];
        $timestamp = (int) ($override['timestamp'] ?? $this->now);
        $nonce = $override['nonce'] ?? bin2hex(random_bytes(12));
        $header = $override['header'] ?? WebhookSignature::header(
            $this->secrets->secretFor(ScriptsHook::class, $tenant),
            $timestamp,
            $nonce,
            'POST',
            $target,
            $override['signedBody'] ?? $body,
        );

        /** @var array<string, string> $query */
        return new Request(
            $override['method'] ?? 'POST',
            (string) parse_url($url, PHP_URL_PATH),
            ['content-type' => 'application/json', WebhookSignature::HEADER => $header],
            $query,
            [],
            [],
            (string) parse_url($url, PHP_URL_QUERY),
        );
    }

    public function send(Request $request, string $body): Response
    {
        return $this->endpoint->handle($request, $body);
    }
}

/** The status and code a refused delivery is answered with. */
function refusal(\Closure $send): array
{
    try {
        $send();
    } catch (HttpException $e) {
        return [$e->status, $e->code_];
    }

    return [0, 'accepted'];
}

it('runs a verified delivery as the tenant its signed URL names, in its panel', function () {
    $s = new Setup();
    $body = '{"event":"script.ran"}';

    expect($s->send($s->delivery($s->url('acme'), $body), $body)->status)->toBe(204)
        ->and($s->h->journal()->entries)->toBe([['tenant' => 'acme', 'scripts' => 2, 'panel' => 'admin', 'event' => 'script.ran']])
        ->and($s->h->orm->tenant())->toBe('')
        ->and($s->h->panels->currentOrNull())->toBeNull();
});

it('refuses a delivery whose signature does not verify, before the handler runs', function () {
    $s = new Setup();
    $url = $s->url('acme');
    $body = '{"event":"x"}';

    expect(refusal(fn () => $s->send($s->delivery($url, $body, ['header' => '']), $body)))->toBe([401, 'webhook_signature'])
        ->and(refusal(fn () => $s->send($s->delivery($url, $body, ['signedBody' => '{"event":"y"}']), $body)))->toBe([401, 'webhook_signature'])
        // Signed with another tenant's secret.
        ->and(refusal(fn () => $s->send($s->delivery($url, $body, ['signedAs' => 'globex']), $body)))->toBe([401, 'webhook_signature'])
        ->and($s->h->journal()->entries)->toBe([]);
});

it('refuses a delivery to a URL whose tenant was edited, as placing it nowhere', function () {
    $s = new Setup();
    $forged = str_replace('tenant=acme', 'tenant=globex', $s->url('acme'));
    $body = '{}';

    expect(refusal(fn () => $s->send($s->delivery($forged, $body), $body)))->toBe([404, 'tenant_unknown'])
        ->and($s->h->journal()->entries)->toBe([]);
});

it('refuses a delivery whose signature was made for another URL', function () {
    $s = new Setup();
    $body = '{}';
    // Signed for globex's URL, sent to acme's: the MAC covers the target.
    $request = $s->delivery($s->url('acme'), $body, ['target' => $s->url('globex')]);

    expect(refusal(fn () => $s->send($request, $body)))->toBe([401, 'webhook_signature']);
});

it('refuses a delivery whose timestamp is outside the tolerance, either way', function () {
    $s = new Setup();
    $url = $s->url('acme');
    $body = '{}';

    expect(refusal(fn () => $s->send($s->delivery($url, $body, ['timestamp' => (string) (NOW - 301)]), $body)))->toBe([401, 'webhook_signature'])
        ->and(refusal(fn () => $s->send($s->delivery($url, $body, ['timestamp' => (string) (NOW + 301)]), $body)))->toBe([401, 'webhook_signature'])
        ->and(refusal(fn () => $s->send($s->delivery($url, $body, ['timestamp' => (string) (NOW - 300)]), $body)))->toBe([0, 'accepted']);
});

it('refuses a replayed delivery, and accepts the sender\'s retry of one whose handler failed', function (\Closure $store) {
    $s = new Setup($store(new Harness()));
    $url = $s->url('acme');
    $body = '{"event":"once"}';
    $request = $s->delivery($url, $body, ['nonce' => 'nonce-0123456789abcdef']);

    expect($s->send($request, $body)->status)->toBe(204)
        ->and(refusal(fn () => $s->send($request, $body)))->toBe([409, 'webhook_replayed'])
        // The same nonce on another tenant's URL is that tenant's own.
        ->and($s->send($s->delivery($s->url('globex'), $body, ['nonce' => 'nonce-0123456789abcdef']), $body)->status)->toBe(204);

    $failing = '{"fail":true}';
    $retry = $s->delivery($url, $failing, ['nonce' => 'nonce-fedcba9876543210']);
    // Failed, the nonce was released: the retry runs the handler again
    // rather than being refused as a replay.
    expect(fn () => $s->send($retry, $failing))->toThrow(\RuntimeException::class, 'handler failed')
        ->and(fn () => $s->send($retry, $failing))->toThrow(\RuntimeException::class, 'handler failed');
})->with([
    'in memory' => [static fn (Harness $h): NonceStore => new ArrayNonceStore()],
    'in the database' => [static function (Harness $h): NonceStore {
        DatabaseNonceStore::define(schemaOf($h->fixture->connection));

        return new DatabaseNonceStore($h->fixture->connection);
    }],
]);

// Where the tenant is not in what the sender signs -- a host, here -- and
// its tenants share a secret, one tenant's delivery verifies at another's
// host. The nonce was kept per tenant, so it was new there, and a captured
// delivery for acme ran again as globex.
it('refuses a delivery replayed to another tenant that shares its secret', function (\Closure $store) {
    $h = new Harness();
    $nonces = $store($h);
    $h->container->instance(Host::class, new Host(new ProviderHosts()));
    $endpoint = new WebhookEndpoint([ProviderHook::class], $h->orm, $h->container->resolver(), new OneSecret(), $nonces, clock: static fn (): int => NOW);
    $deliver = static function (string $host, string $body, string $nonce) use ($endpoint): Response {
        $request = new Request('POST', '/webhooks/provider', [
            'host' => $host,
            'content-type' => 'application/json',
            WebhookSignature::HEADER => WebhookSignature::header(OneSecret::SECRET, NOW, $nonce, 'POST', '/webhooks/provider', $body),
        ]);

        return $endpoint->handle($request, $body);
    };

    $body = '{"event":"paid"}';
    expect($deliver('acme.shop.test', $body, 'nonce-0123456789abcdef')->status)->toBe(204)
        ->and(refusal(fn () => $deliver('globex.shop.test', $body, 'nonce-0123456789abcdef')))->toBe([409, 'webhook_replayed'])
        ->and(refusal(fn () => $deliver('acme.shop.test', $body, 'nonce-0123456789abcdef')))->toBe([409, 'webhook_replayed']);

    // One whose handler failed is open to the sender's retry, to the tenant
    // it was sent to -- and still to no other.
    $failing = '{"fail":true}';
    expect(fn () => $deliver('acme.shop.test', $failing, 'nonce-fedcba9876543210'))->toThrow(\RuntimeException::class, 'handler failed')
        ->and(refusal(fn () => $deliver('globex.shop.test', $failing, 'nonce-fedcba9876543210')))->toBe([409, 'webhook_replayed'])
        ->and(fn () => $deliver('acme.shop.test', $failing, 'nonce-fedcba9876543210'))->toThrow(\RuntimeException::class, 'handler failed')
        ->and($h->journal()->entries)->toBe([['tenant' => 'acme']]);
})->with([
    'in memory' => [static fn (Harness $h): NonceStore => new ArrayNonceStore()],
    'in the database' => [static function (Harness $h): NonceStore {
        DatabaseNonceStore::define(schemaOf($h->fixture->connection));

        return new DatabaseNonceStore($h->fixture->connection);
    }],
]);

it('answers a webhook ahead of the global stack, and resets the request after it', function () {
    GlobalStack::$ran = 0;
    $s = new Setup();
    $kernel = new Kernel(
        orm: $s->h->orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $s->h->auth,
        policies: new PolicyRegistry(),
        resolver: $s->h->container->resolver(),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [GlobalStack::class],
        panels: $s->h->panels,
        panelRegistry: $s->h->registry,
        webhooks: $s->endpoint,
    );
    $body = '{"event":"via kernel"}';

    expect($kernel->handle($s->delivery($s->url('acme'), $body), static fn () => Response::make(500), $body)->status)->toBe(204)
        ->and(GlobalStack::$ran)->toBe(0)
        ->and($kernel->handle($s->delivery($s->url('acme'), $body, ['header' => 't=1']), static fn () => Response::make(500), $body)->status)->toBe(401)
        ->and($kernel->handle($s->delivery($s->url('acme'), $body, ['method' => 'GET']), static fn () => Response::make(500), $body)->status)->toBe(405)
        ->and($s->h->journal()->entries)->toHaveCount(1);
});
