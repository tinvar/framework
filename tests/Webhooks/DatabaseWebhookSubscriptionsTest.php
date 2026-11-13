<?php

declare(strict_types=1);

/**
 * Tenants' own webhook subscriptions (ADR 0197): kept per tenant, their
 * URL held to OutboundHttp's rules when it is set, their secret derived
 * and never stored, shown once, rotated with an overlap in which both sign
 * -- and a receiver still holding either verifies -- paused, and disabled
 * after the deliveries to one keep failing, the reason kept and the notice
 * told once. On SQLite, and on Postgres and MySQL where they are there.
 */

namespace Tinvar\Tests\Webhooks\DatabaseWebhookSubscriptions;

use Carbon\CarbonImmutable;
use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Webhook;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\Request;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\StaticResolver;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Support\Signer;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Support\Fixtures\Databases;
use Tinvar\Tests\Support\Fixtures\Sources;
use Tinvar\Webhooks\ArrayDeliveryLog;
use Tinvar\Webhooks\DatabaseDeliveryLog;
use Tinvar\Webhooks\DatabaseWebhookSubscriptions;
use Tinvar\Webhooks\DeliverWebhook;
use Tinvar\Webhooks\DeliveryAttempt;
use Tinvar\Webhooks\DeliveryLog;
use Tinvar\Webhooks\OutboundWebhooks;
use Tinvar\Webhooks\StoredWebhookSubscription;
use Tinvar\Webhooks\WebhookCall;
use Tinvar\Webhooks\WebhookDisabledNotice;
use Tinvar\Webhooks\WebhookDisabledReason;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Webhooks\WebhookSecrets;
use Tinvar\Webhooks\WebhookSignature;
use Tinvar\Webhooks\WebhookSubscriptionRefused;
use Tinvar\Webhooks\WebhookSubscriptions;
use Tinvar\Webhooks\WebhookSubscriptionStatus;
use Tinvar\Webhooks\WebhookUrlCheck;

use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';
require_once __DIR__ . '/../Support/Fixtures/tenants.php';

const KEY = 'webhook-subscriptions-test-key-32-bytes!';

/** The signature header as a receiver before ADR 0197 reads it: one MAC, nothing after. */
const PRE_0197_HEADER = '/^t=([1-9]\\d{0,11}),n=([A-Za-z0-9_-]{16,128}),v1=([0-9a-f]{64})\\z/';

/** Names as the test says they resolve: the receiver public, one name private, one nowhere. */
function resolver(): StaticResolver
{
    return new StaticResolver([
        'receiver.test' => ['93.184.215.10'],
        'other.test' => ['93.184.215.11'],
        'intranet.test' => ['10.0.0.5'],
        'sneaky.test' => ['93.184.215.12', '127.0.0.1'],
    ]);
}

/** Every subscription the notice was told of, as it was then. */
final class Told implements WebhookDisabledNotice
{
    /** @var list<StoredWebhookSubscription> */
    public array $disabled = [];

    public function disabled(StoredWebhookSubscription $subscription): void
    {
        $this->disabled[] = $subscription;
    }
}

final class Clock
{
    public static CarbonImmutable $now;
}

function store(Orm $orm, ?Told $told = null, int $disableAfter = 3): DatabaseWebhookSubscriptions
{
    return new DatabaseWebhookSubscriptions(
        $orm,
        new Signer(KEY, DatabaseWebhookSubscriptions::PURPOSE),
        new WebhookUrlCheck(new OutboundPolicy(allow: ['10.0.0.0/8']), resolver()),
        $told,
        disableAfter: $disableAfter,
        overlapSeconds: 3600,
        maxPerTenant: 3,
        clock: static fn (): CarbonImmutable => Clock::$now,
    );
}

function storeHolds(PdoConnection $connection): void
{
    Clock::$now = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
    DatabaseWebhookSubscriptions::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('webhook-subscriptions'), Tenancy::flat());
    $told = new Told();
    $store = store($orm, $told);
    $acme = static fn (\Closure $work): mixed => $orm->actAs('acme', $work);

    $made = $acme(fn () => $store->create('https://receiver.test/hooks?for=acme', ['order.placed', 'order.shipped', 'order.placed'], ' Warehouse ', 'm-1'));
    $other = $orm->actAs('globex', fn () => $store->create('https://other.test/in', ['order.placed'], '', 'm-9'));

    // Shown once: the secret is in the answer, derived and never stored.
    expect($made->secret)->toStartWith('whsec_')
        ->and(\strlen($made->secret))->toBeGreaterThan(32)
        ->and($made->subscription->events)->toBe(['order.placed', 'order.shipped'])
        ->and($made->subscription->description)->toBe('Warehouse')
        ->and($made->subscription->status)->toBe(WebhookSubscriptionStatus::Active)
        ->and($made->subscription->createdBy)->toBe('m-1')
        ->and(json_encode($made))->not->toContain($made->secret)
        ->and(print_r($made, true))->not->toContain($made->secret)
        ->and(json_encode($connection->select('SELECT * FROM webhook_subscriptions')))->not->toContain($made->secret)
        ->and($other->secret)->not->toBe($made->secret);

    // Found in its tenant alone, and by nobody with no tenant in force.
    $id = $made->subscription->id;
    expect($acme(fn () => $store->find($id)?->secret))->toBe($made->secret)
        ->and($acme(fn () => $store->find($id)?->previousSecret))->toBeNull()
        ->and($orm->actAs('globex', fn () => $store->find($id)))->toBeNull()
        ->and($store->find($id))->toBeNull()
        ->and($store->all())->toBe([])
        ->and($acme(fn () => array_map(static fn (StoredWebhookSubscription $s): string => $s->id, $store->all())))->toBe([$id])
        ->and($acme(fn () => $store->subscribedTo('order.shipped')))->toBe([$id])
        ->and($acme(fn () => $store->subscribedTo('order.refunded')))->toBe([])
        ->and($orm->actAs('globex', fn () => $store->pause($id)))->toBeFalse()
        ->and($orm->actAs('globex', fn () => $store->delete($id)))->toBeFalse()
        ->and($orm->actAs('globex', fn () => $store->rotate($id)))->toBeNull()
        ->and($orm->actAs('globex', fn () => $store->update($id, 'https://other.test/x', ['order.placed'], '')))->toBeFalse()
        ->and(fn () => $store->create('https://receiver.test/x', ['order.placed'], '', 'm-1'))->toThrow(\LogicException::class);

    // Rotated: a new secret, and the one it replaced signs too until the overlap ends.
    $rotated = $acme(fn () => $store->rotate($id));
    expect($rotated?->secret)->not->toBeNull()->not->toBe($made->secret)
        ->and($acme(fn () => $store->find($id)?->signingSecret()))->toBe($made->secret)
        ->and($acme(fn () => $store->find($id)?->nextSecret()))->toBe($rotated?->secret);
    Clock::$now = Clock::$now->addSeconds(3601);
    expect($acme(fn () => $store->find($id)?->signingSecret()))->toBe($rotated?->secret)
        ->and($acme(fn () => $store->find($id)?->nextSecret()))->toBeNull();

    // Edited, paused and resumed.
    expect($acme(fn () => $store->update($id, 'https://other.test/hooks', ['order.shipped'], 'Moved')))->toBeTrue()
        ->and($acme(fn () => $store->get($id)?->url))->toBe('https://other.test/hooks')
        ->and($acme(fn () => $store->pause($id)))->toBeTrue()
        ->and($acme(fn () => $store->pause($id)))->toBeFalse()
        ->and($acme(fn () => $store->find($id)?->active))->toBeFalse()
        ->and($acme(fn () => $store->subscribedTo('order.shipped')))->toBe([])
        ->and($acme(fn () => $store->resume($id)))->toBeTrue()
        ->and($acme(fn () => $store->find($id)?->active))->toBeTrue();

    // Disabled after three undelivered in a row; a delivery that arrives starts the count again.
    $acme(fn () => $store->deliveryFailed($id, 'timeout'));
    $acme(fn () => $store->deliveryFailed($id, 'timeout'));
    $acme(fn () => $store->deliveryArrived($id));
    $acme(fn () => $store->deliveryFailed($id, 'status_500'));
    $acme(fn () => $store->deliveryFailed($id, 'status_502'));
    $orm->actAs('globex', fn () => $store->deliveryFailed($id, 'status_502'));
    expect($acme(fn () => $store->get($id)?->status))->toBe(WebhookSubscriptionStatus::Active)
        ->and($told->disabled)->toBe([]);
    $acme(fn () => $store->deliveryFailed($id, 'status_503'));
    $acme(fn () => $store->deliveryFailed($id, 'status_503'));
    $disabled = $acme(fn () => $store->get($id));
    expect($disabled?->status)->toBe(WebhookSubscriptionStatus::Disabled)
        ->and($disabled?->disabledReason)->toBe(WebhookDisabledReason::ConsecutiveFailures)
        ->and($disabled?->lastFailure)->toBe('status_503')
        ->and($disabled?->disabledAt?->getTimestamp())->toBe(Clock::$now->getTimestamp())
        ->and($told->disabled)->toHaveCount(1)
        ->and($told->disabled[0]->id)->toBe($id)
        ->and($acme(fn () => $store->find($id)?->active))->toBeFalse();

    // Resumed, the count and the reason forgotten -- but on probation: the
    // first delivery to fail before one arrives disables it again at once,
    // so a tenant cannot loop a dead receiver through resume after resume.
    expect($acme(fn () => $store->resume($id)))->toBeTrue()
        ->and($acme(fn () => $store->get($id)?->disabledReason))->toBeNull()
        ->and($acme(fn () => $store->get($id)?->consecutiveFailures))->toBe(0)
        ->and($acme(fn () => $store->get($id)?->probation))->toBeTrue();
    $acme(fn () => $store->deliveryFailed($id, 'timeout'));
    expect($acme(fn () => $store->get($id)?->disabledReason))->toBe(WebhookDisabledReason::FailedAfterResume)
        ->and($told->disabled)->toHaveCount(2);

    // Resumed again, a delivery that arrives ends the probation.
    $acme(fn () => $store->resume($id));
    $acme(fn () => $store->deliveryArrived($id));
    $acme(fn () => $store->deliveryFailed($id, 'timeout'));
    expect($acme(fn () => $store->get($id)?->status))->toBe(WebhookSubscriptionStatus::Active)
        ->and($acme(fn () => $store->get($id)?->probation))->toBeFalse();

    // Paused and resumed by a member: no probation. A 410 disables it at once.
    $acme(fn () => $store->pause($id));
    $acme(fn () => $store->resume($id));
    expect($acme(fn () => $store->get($id)?->probation))->toBeFalse();
    $acme(fn () => $store->deliveryFailed($id, 'receiver_gone', receiverGone: true));
    expect($acme(fn () => $store->get($id)?->disabledReason))->toBe(WebhookDisabledReason::ReceiverGone)
        ->and($told->disabled)->toHaveCount(3);

    // Deleted: found no more.
    expect($acme(fn () => $store->delete($id)))->toBeTrue()
        ->and($acme(fn () => $store->find($id)))->toBeNull();
}

it('keeps subscriptions on SQLite', function () {
    storeHolds(PdoConnection::sqlite(':memory:'));
});

it('keeps subscriptions on Postgres, the table under the tenant policy', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
        $connection = new PdoConnection($pdo, 'pgsql');
        storeHolds($connection);
        $policy = $pdo->query("SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forced, (SELECT COUNT(*) FROM pg_policies p WHERE p.tablename = 'webhook_subscriptions' AND p.policyname = '" . RowLevelSecurity::POLICY . "') AS policies FROM pg_class c WHERE c.relname = 'webhook_subscriptions' AND c.relnamespace = current_schema()::regnamespace")->fetch(\PDO::FETCH_ASSOC);
        expect($policy)->toEqual(['rls' => true, 'forced' => true, 'policies' => 1]);
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('works under the tenant policy as the application\'s role, which shows no tenant another\'s', function () {
    $role = 'tinvar_rls_webhooks_' . bin2hex(random_bytes(4));
    $pdo = null;
    try {
        Databases::inScratchSchema('pgsql', static function (\PDO $connection, string $schema) use ($role, &$pdo): void {
            $pdo = $connection;
            Clock::$now = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
            $pg = new PdoConnection($pdo, 'pgsql');
            DatabaseWebhookSubscriptions::define(new Schema($pg, Dialect::for($pg)));
            $pdo->exec("CREATE ROLE {$role} NOLOGIN");
            $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
            $pdo->exec("GRANT ALL ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
            $pdo->exec("SET ROLE {$role}");
            try {
                $orm = new Orm($pg, new IdentityMap('webhook-subscriptions-rls'), Tenancy::flat());
                $store = store($orm);
                $made = $orm->actAs('acme', fn () => $store->create('https://receiver.test/hooks', ['order.placed'], '', 'm-1'));
                $orm->actAs('globex', fn () => $store->create('https://other.test/hooks', ['order.placed'], '', 'm-9'));

                expect($orm->actAs('acme', fn () => $store->find($made->subscription->id)?->secret))->toBe($made->secret)
                    ->and($orm->actAs('acme', fn () => $orm->statements()->select('SELECT COUNT(*) AS n FROM webhook_subscriptions')[0]['n'] ?? null))->toEqual(1)
                    ->and($orm->statements()->select('SELECT COUNT(*) AS n FROM webhook_subscriptions')[0]['n'] ?? null)->toEqual(0);
            } finally {
                $pdo->exec('RESET ROLE');
            }
        });
    } finally {
        $pdo?->exec("DROP ROLE IF EXISTS {$role}");
    }
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('keeps subscriptions on MySQL', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        storeHolds(new PdoConnection($pdo, 'mysql'));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('holds a URL to OutboundHttp\'s rules, the host public, when it is set', function () {
    Clock::$now = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
    $connection = PdoConnection::sqlite(':memory:');
    DatabaseWebhookSubscriptions::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('webhook-urls'), Tenancy::flat());
    $store = store($orm);
    $refusal = static function (string $url, array $events = ['order.placed'], string $description = '') use ($orm, $store): ?string {
        try {
            $orm->actAs('acme', fn () => $store->create($url, $events, $description, 'm-1'));

            return null;
        } catch (WebhookSubscriptionRefused $e) {
            return "{$e->field}: {$e->getMessage()}";
        }
    };

    expect($refusal('http://receiver.test/hooks'))->toBe('url: Give an https URL.')
        ->and($refusal('ftp://receiver.test/hooks'))->toBe('url: Give an https URL.')
        ->and($refusal('https://user:pw@receiver.test/hooks'))->toBe('url: Leave the user name and password out of the URL.')
        // Private, though the application opened 10/8 for its own services.
        // One answer for every host that will not do -- resolving inside, or
        // not at all -- so a tenant cannot map the network by which it gets.
        ->and($refusal('https://intranet.test/hooks'))->toBe('url: ' . WebhookUrlCheck::HOST_REFUSED)
        ->and($refusal('https://10.0.0.5/hooks'))->toBe('url: ' . WebhookUrlCheck::HOST_REFUSED)
        ->and($refusal('https://sneaky.test/hooks'))->toBe('url: ' . WebhookUrlCheck::HOST_REFUSED)
        ->and($refusal('https://169.254.169.254/latest'))->toBe('url: ' . WebhookUrlCheck::HOST_REFUSED)
        ->and($refusal('https://nowhere.test/hooks'))->toBe('url: ' . WebhookUrlCheck::HOST_REFUSED)
        ->and($refusal('https://receiver.test:22/hooks'))->toBe('url: Webhooks are not sent to port 22.')
        ->and($refusal('https://receiver.test/' . str_repeat('a', 2048)))->toStartWith('url: Give a URL of at most')
        ->and($refusal('https://receiver.test/hooks', []))->toBe('events: Choose at least one event to send.')
        ->and($refusal('https://receiver.test/hooks', ['Order Placed']))->toBe('events: Choose the events to send from those listed.')
        ->and($refusal('https://receiver.test/hooks', ['order.placed'], "two\nlines"))->toStartWith('description:')
        ->and($connection->select('SELECT * FROM webhook_subscriptions'))->toBe([])
        ->and($refusal('https://receiver.test/a'))->toBeNull()
        ->and($refusal('https://receiver.test/b'))->toBeNull()
        ->and($refusal('https://receiver.test/c'))->toBeNull()
        ->and($refusal('https://receiver.test/d'))->toStartWith('limit:');
});

/** A receiver that is another Tinvar application, given the old secret or the new. */
#[Webhook('/hooks/orders'), TenantFrom(FromQuery::class)]
final class ReceivesOrders
{
    public function __invoke(WebhookCall $call): null
    {
        return null;
    }
}

final class FromQuery
{
    public function tenantFor(Request $request): ?string
    {
        $tenant = $request->query['tenant'] ?? null;

        return \is_string($tenant) ? $tenant : null;
    }
}

final class Holding implements WebhookSecrets
{
    public function __construct(private string $secret) {}

    public function secretFor(string $webhook, string $tenant): ?string
    {
        return $this->secret;
    }
}

/** A sending application on the jobs harness, its subscriptions in its own table. */
final class Sender
{
    public Harness $h;
    public FakeOutboundHttp $http;
    public ArrayDeliveryLog $log;
    public DatabaseWebhookSubscriptions $subscriptions;
    public OutboundWebhooks $webhooks;
    public Told $told;

    /** @param \Closure(OutboundRequest): OutboundResponse $respond */
    public function __construct(\Closure $respond)
    {
        Clock::$now = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
        $this->h = new Harness();
        $this->http = new FakeOutboundHttp($respond);
        $this->log = new ArrayDeliveryLog();
        $this->told = new Told();
        DatabaseWebhookSubscriptions::define(schemaOf($this->h->fixture->connection));
        $this->subscriptions = store($this->h->orm, $this->told, disableAfter: 2);
        $this->h->container
            ->instance(OutboundHttp::class, $this->http)
            ->instance(WebhookSubscriptions::class, $this->subscriptions)
            ->instance(DeliveryLog::class, $this->log);
        $this->webhooks = new OutboundWebhooks($this->h->dispatcher, clock: fn (): int => $this->h->now);
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function acme(\Closure $work): mixed
    {
        return $this->h->orm->actAs('acme', $work);
    }

    /** Works the webhooks queue through every try a delivery has. */
    public function workThrough(): void
    {
        foreach ([0, 30, 120, 600, 1800, 3600] as $wait) {
            $this->h->now += $wait;
            $this->h->worker->run(new WorkerOptions(queues: [DeliverWebhook::QUEUE], stopWhenEmpty: true, signals: false));
        }
    }

    public function queue(): ArrayQueue
    {
        \assert($this->h->queue instanceof ArrayQueue);

        return $this->h->queue;
    }
}

it('publishes an event to each active subscription that chose it, signed so a receiver on any version holding either secret takes it while one rotates out', function () {
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(204));
    $made = $s->acme(fn () => $s->subscriptions->create('https://receiver.test/hooks/orders?tenant=acme', ['order.placed'], '', 'm-1'));
    $paused = $s->acme(fn () => $s->subscriptions->create('https://other.test/hooks', ['order.placed'], '', 'm-1'));
    $s->acme(fn () => $s->subscriptions->create('https://other.test/shipping', ['order.shipped'], '', 'm-1'));
    $s->acme(fn () => $s->subscriptions->pause($paused->subscription->id));
    $rotated = $s->acme(fn () => $s->subscriptions->rotate($made->subscription->id));
    \assert($rotated !== null);

    $deliveries = $s->acme(fn () => $s->webhooks->publish('order.placed', ['number' => 'PW-1'], $s->subscriptions));
    expect($s->h->orm->actAs('globex', fn () => $s->webhooks->publish('order.placed', ['number' => 'PW-2'], $s->subscriptions)))->toBe([])
        ->and($deliveries)->toHaveCount(1)
        ->and($s->queue()->pushed()[0]->payload)->not->toContain($made->secret)->not->toContain($rotated->secret);
    $s->workThrough();

    $request = $s->http->sent[0];
    $main = $request->headers[WebhookSignature::HEADER] ?? '';
    $next = $request->headers[WebhookSignature::NEXT_HEADER] ?? '';
    [$t, $n] = WebhookSignature::parse($main) ?? [0, ''];
    $path = (string) parse_url($request->url, PHP_URL_PATH) . '?' . (string) parse_url($request->url, PHP_URL_QUERY);
    expect($s->http->sent)->toHaveCount(1)
        // The header every receiver reads, as it was before ADR 0197: one MAC, by the old secret.
        ->and(preg_match(PRE_0197_HEADER, $main))->toBe(1)
        ->and($main)->toBe(WebhookSignature::header($made->secret, $t, $n, 'POST', $path, $request->body))
        ->and($next)->toBe(WebhookSignature::header($rotated->secret, $t, $n, 'POST', $path, $request->body));

    // A receiver holding the new secret, and one still holding the old, both take it.
    $url = parse_url($request->url);
    parse_str($url['query'] ?? '', $query);
    foreach ([$rotated->secret, $made->secret, 'whsec_a-secret-it-never-held-32-bytes-long'] as $i => $secret) {
        $endpoint = new WebhookEndpoint([ReceivesOrders::class], $s->h->orm, $s->h->container->resolver(), new Holding($secret), new ArrayNonceStore(), tenants: new Sources($s->h->container->resolver()));
        /** @var array<string, string> $query */
        try {
            $status = $endpoint->handle(new Request('POST', $url['path'], $request->headers, $query, [], [], $url['query'] ?? null), $request->body)->status;
        } catch (\Tinvar\Http\HttpException $e) {
            $status = $e->status;
        }
        expect($status)->toBe($i < 2 ? 204 : 401);
    }

    // Past the overlap, the new secret alone signs.
    Clock::$now = Clock::$now->addHours(2);
    $s->acme(fn () => $s->webhooks->publish('order.placed', ['number' => 'PW-3'], $s->subscriptions));
    $s->workThrough();
    $later = $s->http->sent[1];
    [$t, $n] = WebhookSignature::parse($later->headers[WebhookSignature::HEADER] ?? null) ?? [0, ''];
    expect($later->headers)->not->toHaveKey(WebhookSignature::NEXT_HEADER)
        ->and($later->headers[WebhookSignature::HEADER] ?? '')->toBe(WebhookSignature::header($rotated->secret, $t, $n, 'POST', $path, $later->body));
});

it('ends unsent a delivery whose subscription was paused after it was queued', function () {
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(204));
    $made = $s->acme(fn () => $s->subscriptions->create('https://receiver.test/hooks', ['order.placed'], '', 'm-1'));
    [$delivery] = $s->acme(fn () => $s->webhooks->publish('order.placed', [], $s->subscriptions));
    $s->acme(fn () => $s->subscriptions->pause($made->subscription->id));
    $s->workThrough();

    expect($s->http->sent)->toBe([])
        ->and(array_map(static fn (DeliveryAttempt $a): array => [$a->outcome, $a->reason], $s->log->attempts('acme', $delivery)))->toBe([[DeliveryAttempt::GONE, 'subscription_paused']]);
});

it('disables a subscription once its deliveries keep failing to the last try, and tells the notice once', function () {
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(500));
    $made = $s->acme(fn () => $s->subscriptions->create('https://receiver.test/hooks', ['order.placed'], '', 'm-1'));
    $id = $made->subscription->id;

    $s->acme(fn () => $s->webhooks->publish('order.placed', [], $s->subscriptions));
    $s->workThrough();
    expect($s->http->sent)->toHaveCount(DeliverWebhook::TRIES)
        ->and($s->acme(fn () => $s->subscriptions->get($id)?->consecutiveFailures))->toBe(1)
        ->and($s->acme(fn () => $s->subscriptions->get($id)?->status))->toBe(WebhookSubscriptionStatus::Active);

    $s->acme(fn () => $s->webhooks->publish('order.placed', [], $s->subscriptions));
    $s->workThrough();
    $stored = $s->acme(fn () => $s->subscriptions->get($id));
    expect($stored?->status)->toBe(WebhookSubscriptionStatus::Disabled)
        ->and($stored?->disabledReason)->toBe(WebhookDisabledReason::ConsecutiveFailures)
        ->and($stored?->lastFailure)->toBe('status_500')
        ->and($s->told->disabled)->toHaveCount(1)
        ->and($s->acme(fn () => $s->webhooks->publish('order.placed', [], $s->subscriptions)))->toBe([]);
});

it('disables a subscription whose receiver answers 410 at once', function () {
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(410));
    $made = $s->acme(fn () => $s->subscriptions->create('https://receiver.test/hooks', ['order.placed'], '', 'm-1'));
    $s->acme(fn () => $s->webhooks->publish('order.placed', [], $s->subscriptions));
    $s->workThrough();

    expect($s->http->sent)->toHaveCount(1)
        ->and($s->acme(fn () => $s->subscriptions->get($made->subscription->id)?->disabledReason))->toBe(WebhookDisabledReason::ReceiverGone)
        ->and($s->told->disabled)->toHaveCount(1);
});

it('reads one subscription\'s tries in its own tenant alone, newest first', function () {
    $connection = PdoConnection::sqlite(':memory:');
    DatabaseDeliveryLog::define(schemaOf($connection));
    foreach ([new DatabaseDeliveryLog($connection), new ArrayDeliveryLog()] as $log) {
        $log->record(new DeliveryAttempt('d1', 'sub-a', 'order.placed', 'acme', 1, DeliveryAttempt::FAILED, 503, 'status', 40, 100));
        $log->record(new DeliveryAttempt('d2', 'sub-b', 'order.placed', 'acme', 1, DeliveryAttempt::DELIVERED, 200, null, 20, 150));
        $log->record(new DeliveryAttempt('d3', 'sub-a', 'order.placed', 'globex', 1, DeliveryAttempt::DELIVERED, 200, null, 20, 160));
        $log->record(new DeliveryAttempt('d1', 'sub-a', 'order.placed', 'acme', 2, DeliveryAttempt::DELIVERED, 204, null, 30, 300));

        expect(array_map(static fn (DeliveryAttempt $a): array => [$a->delivery, $a->attempt], $log->forSubscription('acme', 'sub-a')))->toBe([['d1', 2], ['d1', 1]])
            ->and($log->forSubscription('acme', 'sub-a', 1))->toHaveCount(1)
            ->and($log->forSubscription('globex', 'sub-b'))->toBe([]);
    }
});

it('adds the subscription index to a log table made before it', function () {
    $connection = PdoConnection::sqlite(':memory:');
    $connection->execute('CREATE TABLE webhook_deliveries (id TEXT PRIMARY KEY, delivery TEXT, subscription TEXT, event TEXT, tenant TEXT, attempt INTEGER, outcome TEXT, status INTEGER, reason TEXT, milliseconds INTEGER, attempted_at INTEGER)');
    DatabaseDeliveryLog::addSubscriptionIndex(schemaOf($connection));

    expect(array_column($connection->select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'webhook_deliveries'"), 'name'))->toContain('webhook_deliveries_subscription_recent');
});

it('refuses a table name it would not write, and settings that make no sense', function () {
    $orm = new Orm(PdoConnection::sqlite(':memory:'), new IdentityMap('x'), Tenancy::flat());
    $signer = new Signer(KEY, DatabaseWebhookSubscriptions::PURPOSE);

    expect(fn () => new DatabaseWebhookSubscriptions($orm, $signer, table: 'subs; drop'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new DatabaseWebhookSubscriptions($orm, $signer, disableAfter: 0))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new DatabaseWebhookSubscriptions($orm, $signer, overlapSeconds: 31 * 86_400))->toThrow(\InvalidArgumentException::class);
});

it('counts a delivery whose last try failed where the queue gives up on it, though no try was logged', function () {
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(204));
    $made = $s->acme(fn () => $s->subscriptions->create('https://receiver.test/hooks', ['order.placed'], '', 'm-1'));
    $job = new DeliverWebhook($made->subscription->id, '0199f1c2-7a3e-7c41-9a55-2f0e8d7b1c11', 'order.placed', '{}');

    $s->acme(fn () => $job->failed($s->subscriptions, $s->log, $s->h->orm, new \RuntimeException('lost')));
    $s->log->record(new DeliveryAttempt($job->delivery, $made->subscription->id, 'order.placed', 'acme', 6, DeliveryAttempt::FAILED, 503, 'status', 40, 1));
    $s->acme(fn () => $job->failed($s->subscriptions, $s->log, $s->h->orm, new \RuntimeException('answered 503')));

    expect($s->acme(fn () => $s->subscriptions->get($made->subscription->id)?->consecutiveFailures))->toBe(2)
        ->and($s->acme(fn () => $s->subscriptions->get($made->subscription->id)?->lastFailure))->toBe('status_503');
});

it('holds a tenant\'s webhook deliveries to a rate of their own, so one cannot starve the queue', function () {
    $rate = DeliverWebhook::tenantRate();

    expect($rate->perTenant)->toBeTrue()
        ->and($rate->runs)->toBe(DeliverWebhook::TENANT_RUNS)
        ->and($rate->per)->toBe(60);
});

it('makes subscriptions one at a time per tenant, holding the tenant\'s row', function () {
    Clock::$now = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
    $connection = PdoConnection::sqlite(':memory:');
    DatabaseWebhookSubscriptions::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('webhook-lock'), Tenancy::flat());
    $store = store($orm);

    $inside = $orm->actAs('initech', fn () => $store->exclusively(static fn (): bool => $connection->inTransaction()));
    expect($inside)->toBeTrue()
        ->and($connection->select("SELECT tenant_id FROM webhook_subscriptions_tenants WHERE tenant_id = 'initech'"))->toHaveCount(1);
});

/**
 * A create on a second connection, while the first holds the tenant: it
 * waits, and -- given a short lock timeout -- gives up rather than count
 * past the first.
 */
function createWaits(string $driver): void
{
    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver): void {
        Clock::$now = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');
        $first = new PdoConnection($pdo, $driver);
        DatabaseWebhookSubscriptions::define(new Schema($first, Dialect::for($first)));
        $other = Databases::another($driver);
        $other->exec($driver === 'pgsql' ? "SET search_path TO {$schema}" : "USE {$schema}");
        $other->exec($driver === 'pgsql' ? "SET lock_timeout = '300ms'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        $second = new PdoConnection($other, $driver);
        $a = new Orm($first, new IdentityMap('webhook-lock-a'), Tenancy::flat());
        $b = new Orm($second, new IdentityMap('webhook-lock-b'), Tenancy::flat());
        $storeA = store($a);
        $storeB = store($b);

        $waited = $a->actAs('acme', fn () => $storeA->exclusively(function () use ($b, $storeB): bool {
            try {
                $b->actAs('acme', fn () => $storeB->create('https://receiver.test/b', ['order.placed'], '', 'm-1'));

                return false;
            } catch (\PDOException) {
                return true;
            }
        }));
        // Another tenant is not held by acme's lock.
        $globex = $b->actAs('globex', fn () => $storeB->create('https://receiver.test/g', ['order.placed'], '', 'm-9'));

        expect($waited)->toBeTrue()
            ->and($globex->subscription->tenant)->toBe('globex');
    });
}

it('holds a second create for the tenant on Postgres until the first commits', function () {
    createWaits('pgsql');
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('holds a second create for the tenant on MySQL until the first commits', function () {
    createWaits('mysql');
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('signs a header in the format every receiver reads, one MAC alone', function () {
    $header = WebhookSignature::header(str_repeat('a', 32), 1_800_000_000, 'nonce-of-sixteen-chars', 'POST', '/x', '{}');

    expect(preg_match(PRE_0197_HEADER, $header))->toBe(1)
        ->and(WebhookSignature::parse($header . ',v1=' . str_repeat('0', 64)))->toBeNull();
});
