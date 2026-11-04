<?php

declare(strict_types=1);

/**
 * Outbound webhooks: an event queued as the tenant that sent it, delivered
 * from a worker through OutboundHttp, signed as the inbound side verifies
 * (ADR 0033), tried again with backoff, and recorded try by try with its
 * status and timing alone -- "Tenants see only status codes and timing for
 * their deliveries, never response bodies" (tinvar-spec.md, "Outbound
 * requests").
 */

namespace Tinvar\Tests\Webhooks\OutboundWebhooks;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Webhook;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Http\Request;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\PinnedHttp;
use Tinvar\Outbound\StaticResolver;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Outbound\Fixtures\Server;
use Tinvar\Tests\Support\Fixtures\Sources;
use Tinvar\Webhooks\ArrayDeliveryLog;
use Tinvar\Webhooks\ArraySentWebhooks;
use Tinvar\Webhooks\DatabaseSentWebhooks;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Webhooks\DatabaseDeliveryLog;
use Tinvar\Webhooks\DeliverWebhook;
use Tinvar\Webhooks\DeliveryAttempt;
use Tinvar\Webhooks\DeliveryLog;
use Tinvar\Webhooks\OutboundWebhooks;
use Tinvar\Webhooks\StaticSubscriptions;
use Tinvar\Webhooks\WebhookCall;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Webhooks\WebhookSecrets;
use Tinvar\Webhooks\WebhookSignature;
use Tinvar\Webhooks\WebhookSubscription;
use Tinvar\Webhooks\WebhookSubscriptions;

use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';
require_once __DIR__ . '/../Outbound/Fixtures/server.php';
require_once __DIR__ . '/../Support/Fixtures/tenants.php';

const KEY = 'outbound-webhooks-test-key-32-bytes!';

const SECRET = 'the-receivers-signing-secret-32-bytes-long';

/** What a script ran: the data an event carries. */
final readonly class ScriptRanData extends Data
{
    public function __construct(public string $name, public int $lines) {}
}

/**
 * The tenant a receiver's URL names, in plain sight: the receiver here is
 * the framework's alone, and a URL signed for its tenant (tinvar/tenancy's
 * Signature) is tested with tenancy.
 */
final class FromQuery
{
    public function tenantFor(Request $request): ?string
    {
        $tenant = $request->query['tenant'] ?? null;

        return \is_string($tenant) ? $tenant : null;
    }
}

/** Another Tinvar application's inbound side, receiving the deliveries. */
#[Webhook('/hooks/scripts'), TenantFrom(FromQuery::class)]
final class ReceivesScripts
{
    public function __construct(private readonly Journal $journal) {}

    public function __invoke(WebhookCall $call): null
    {
        $body = $call->json();
        $this->journal->entries[] = ['tenant' => $call->tenant, 'event' => $body['event'], 'id' => $body['id'], 'nonce' => $call->nonce];

        return null;
    }
}

/** The receiver keeps the one secret it was given. */
final class ReceiverSecret implements WebhookSecrets
{
    public function secretFor(string $webhook, string $tenant): ?string
    {
        return $webhook === ReceivesScripts::class && $tenant === 'acme' ? SECRET : null;
    }
}

/**
 * The real client, over the network, to a receiver on this machine alone:
 * a delivery is publicOnly, and a loopback receiver is refused it, so each
 * request is noted as it was built and sent on with only that lifted,
 * through a PinnedHttp that lets in 127.0.0.1 and nothing else. Every
 * other byte -- method, URL, headers, body, limits -- is the delivery's.
 */
final class ThisMachine implements OutboundHttp
{
    /** @var list<bool> each request's publicOnly, as the delivery built it */
    public array $publicOnly = [];

    private PinnedHttp $http;

    public function __construct()
    {
        $this->http = new PinnedHttp(new OutboundPolicy(allow: ['127.0.0.1']));
    }

    public function send(OutboundRequest $request): OutboundResponse
    {
        $this->publicOnly[] = $request->publicOnly;

        return $this->http->send(new OutboundRequest(
            $request->method,
            $request->url,
            $request->headers,
            $request->body,
            $request->timeout,
            $request->maxResponseBytes,
            $request->readBody,
            $request->maxRedirects,
            $request->sink,
            $request->destinations,
            publicOnly: false,
        ));
    }
}

/** A sending application over the jobs harness: its client a fake unless given, its log in memory. */
final class Sender
{
    public Harness $h;
    public OutboundHttp $http;
    public ArrayDeliveryLog $log;
    public OutboundWebhooks $webhooks;

    /**
     * @param (\Closure(OutboundRequest): OutboundResponse)|null $respond
     * @param array<string, WebhookSubscription>|null $subscriptions 'scripts' at acme's signed receiver URL unless given
     */
    public function __construct(?\Closure $respond = null, ?array $subscriptions = null, ?OutboundHttp $http = null)
    {
        $this->h = new Harness();
        $this->http = $http ?? new FakeOutboundHttp($respond);
        $this->log = new ArrayDeliveryLog();
        $this->h->container
            ->instance(OutboundHttp::class, $this->http)
            ->instance(WebhookSubscriptions::class, new StaticSubscriptions($subscriptions ?? [
                'scripts' => new WebhookSubscription('https://receiver.test' . $this->receiverPath(), SECRET),
            ]))
            ->instance(DeliveryLog::class, $this->log);
        $this->webhooks = new OutboundWebhooks($this->h->dispatcher, clock: fn (): int => $this->h->now);
    }

    /** The path and query acme's receiver handed out. */
    public function receiverPath(): string
    {
        return '/hooks/scripts?tenant=acme';
    }

    public function sendAsAcme(string $event = 'script.ran'): string
    {
        return $this->h->orm->actAs('acme', fn (): string => $this->webhooks->send('scripts', $event, new ScriptRanData('backup', 12)));
    }

    /** Works the webhooks queue until nothing is available now. */
    public function work(): int
    {
        return $this->h->worker->run(new WorkerOptions(queues: [DeliverWebhook::QUEUE], stopWhenEmpty: true, signals: false));
    }

    public function queue(): ArrayQueue
    {
        \assert($this->h->queue instanceof ArrayQueue);

        return $this->h->queue;
    }

    /** @return list<OutboundRequest> */
    public function sent(): array
    {
        \assert($this->http instanceof FakeOutboundHttp);

        return $this->http->sent;
    }
}

/** The signature header's nonce and timestamp. */
function signed(OutboundRequest $request): array
{
    $parsed = WebhookSignature::parse($request->headers[WebhookSignature::HEADER] ?? null);
    \assert($parsed !== null);

    return ['timestamp' => $parsed[0], 'nonce' => $parsed[1]];
}

it('queues a delivery as the tenant in force, carrying the event but never the secret', function () {
    $s = new Sender();
    $id = $s->sendAsAcme();
    $pending = $s->queue()->pushed();

    expect($s->queue()->size(DeliverWebhook::QUEUE))->toBe(1)
        ->and($pending[0]->payload)->toContain('"tenant":"acme"')
        ->and($pending[0]->payload)->toContain($id)
        ->and($pending[0]->payload)->not->toContain(SECRET)
        ->and($s->sent())->toBe([]);
});

it('POSTs every event in one shape, signed as the inbound side verifies it', function () {
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(204, milliseconds: 37));
    $id = $s->sendAsAcme();
    $s->work();

    $request = $s->sent()[0];
    $url = parse_url($request->url);
    \assert(\is_array($url));
    parse_str($url['query'] ?? '', $query);

    expect($request->method)->toBe('POST')
        ->and($request->readBody)->toBeFalse()
        ->and($request->maxRedirects)->toBe(0)
        ->and($request->headers['content-type'])->toBe('application/json')
        ->and(json_decode($request->body, true))->toBe([
            'id' => $id,
            'event' => 'script.ran',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $s->h->now),
            'data' => ['name' => 'backup', 'lines' => 12],
        ])
        ->and(signed($request)['nonce'])->toBe($id);

    // The receiver: a WebhookEndpoint, as another Tinvar application runs it.
    $endpoint = new WebhookEndpoint([ReceivesScripts::class], $s->h->orm, $s->h->container->resolver(), new ReceiverSecret(), new ArrayNonceStore(), tenants: new Sources($s->h->container->resolver()));
    /** @var array<string, string> $query */
    $answer = $endpoint->handle(new Request('POST', $url['path'], $request->headers, $query, [], [], $url['query'] ?? null), $request->body);

    expect($answer->status)->toBe(204)
        ->and($s->h->journal()->entries)->toBe([['tenant' => 'acme', 'event' => 'script.ran', 'id' => $id, 'nonce' => $id]])
        ->and($s->log->recorded)->toEqual([new DeliveryAttempt($id, 'scripts', 'script.ran', 'acme', 1, DeliveryAttempt::DELIVERED, 204, null, 37, $s->log->recorded[0]->attemptedAt)]);
});

it('tries again with backoff, signed afresh under the same nonce, and records each try\'s status and timing alone', function () {
    $tries = 0;
    $s = new Sender(static function () use (&$tries): OutboundResponse {
        $tries++;
        if ($tries === 2) {
            throw new OutboundFailed(OutboundFailed::TIMEOUT, 'POST https://receiver.test:443 failed: Timeout was reached.', null, 10_000);
        }

        return new OutboundResponse($tries === 1 ? 503 : 200, ['x-internal' => ['s3cret']], 'a body the tenant never sees', 12);
    });
    $id = $s->sendAsAcme();

    $s->work();
    $s->h->now += 30;
    $s->work();
    $s->h->now += 120;
    $s->work();

    expect($tries)->toBe(3)
        ->and(array_map(static fn (DeliveryAttempt $a): array => [$a->attempt, $a->outcome, $a->status, $a->reason, $a->milliseconds], $s->log->attempts($id)))->toBe([
            [1, DeliveryAttempt::FAILED, 503, 'status', 12],
            [2, DeliveryAttempt::FAILED, null, OutboundFailed::TIMEOUT, 10_000],
            [3, DeliveryAttempt::DELIVERED, 200, null, 12],
        ])
        ->and(array_unique(array_map(static fn (OutboundRequest $r): string => signed($r)['nonce'], $s->sent())))->toBe([$id])
        ->and($s->queue()->size(DeliverWebhook::QUEUE))->toBe(0)
        ->and($s->queue()->failed())->toBe([]);
});

// Every try goes under one nonce so that a receiver which handled a try
// whose answer was lost refuses the next as a replay: a 409. Taken for a
// failure, the tries went on until the receiver's window had passed, and
// the next one was handled again.
it('ends a delivery the receiver refuses as a replay: it has it already', function () {
    $tries = 0;
    $s = new Sender(static function () use (&$tries): OutboundResponse {
        $tries++;
        if ($tries === 1) {
            throw new OutboundFailed(OutboundFailed::TIMEOUT, 'POST https://receiver.test:443 failed: Timeout was reached.', null, 10_000);
        }

        return new OutboundResponse(409, [], '', 8);
    });
    $id = $s->sendAsAcme();

    foreach ([0, 30, 120, 600, 1800] as $wait) {
        $s->h->now += $wait;
        $s->work();
    }

    expect($tries)->toBe(2)
        ->and(array_map(static fn (DeliveryAttempt $a): array => [$a->outcome, $a->status, $a->reason], $s->log->attempts($id)))->toBe([
            [DeliveryAttempt::FAILED, null, OutboundFailed::TIMEOUT],
            [DeliveryAttempt::DELIVERED, 409, 'already_received'],
        ])
        ->and($s->queue()->size(DeliverWebhook::QUEUE))->toBe(0)
        ->and($s->queue()->failed())->toBe([]);
});

it('ends a delivery the receiver answers 410, without trying again', function () {
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(410));
    $id = $s->sendAsAcme();
    $s->work();
    $s->h->now += 3600;
    $s->work();

    expect($s->sent())->toHaveCount(1)
        ->and($s->queue()->failed())->toHaveCount(1)
        ->and($s->queue()->failed()[0]->error)->toContain('410')
        ->and($s->log->attempts($id)[0]->outcome)->toBe(DeliveryAttempt::GONE);
});

it('refuses a subscription pointing where outbound requests may not go, and never tries it again', function () {
    $s = new Sender(subscriptions: ['scripts' => new WebhookSubscription('http://169.254.169.254/latest/meta-data/', SECRET)]);
    $id = $s->sendAsAcme();
    $s->work();

    expect($s->sent())->toBe([])
        ->and($s->queue()->failed())->toHaveCount(1)
        ->and($s->queue()->failed()[0]->error)->toContain('refused')
        ->and(array_map(static fn (DeliveryAttempt $a): array => [$a->outcome, $a->reason], $s->log->attempts($id)))->toBe([[DeliveryAttempt::REFUSED, null]])
        // The operator's record says why.
        ->and($s->queue()->failed()[0]->error)->toContain('(metadata)');
});

it('stops when the subscription is gone by the time the delivery runs', function () {
    $s = new Sender(subscriptions: []);
    $id = $s->sendAsAcme();
    $s->work();

    expect($s->sent())->toBe([])
        ->and($s->queue()->failed())->toBe([])
        ->and($s->queue()->size(DeliverWebhook::QUEUE))->toBe(0)
        ->and(array_map(static fn (DeliveryAttempt $a): array => [$a->outcome, $a->reason], $s->log->attempts($id)))->toBe([[DeliveryAttempt::GONE, 'subscription_gone']]);
});

it('waits for the commit, and announces nothing a rollback takes back', function () {
    $s = new Sender();
    try {
        $s->h->orm->transaction(function () use ($s): void {
            $s->sendAsAcme();

            throw new \RuntimeException('the run was not recorded after all');
        });
    } catch (\RuntimeException) {
    }
    $s->h->orm->transaction(function () use ($s): void {
        $s->sendAsAcme();
        expect($s->queue()->size(DeliverWebhook::QUEUE))->toBe(0);
    });

    expect($s->queue()->size(DeliverWebhook::QUEUE))->toBe(1);
});

it('refuses a model in the data, a malformed event or subscription, and sending while read-only', function () {
    $s = new Sender();
    $script = $s->h->orm->actAs('acme', fn () => $s->h->orm->find(Script::class, $s->h->fixture->scripts['acme'][0]));

    expect(fn () => $s->h->orm->actAs('acme', fn () => $s->webhooks->send('scripts', 'script.ran', ['script' => $script])))
        ->toThrow(DataBoundaryException::class)
        ->and(fn () => $s->webhooks->send('scripts', 'Script Ran', []))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $s->webhooks->send("scripts\n", 'script.ran', []))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $s->webhooks->send('scripts', 'script.ran', ['blob' => str_repeat('x', WebhookEndpoint::MAX_BODY_BYTES)]))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $s->h->orm->readOnly(fn () => $s->webhooks->send('scripts', 'script.ran', [])))->toThrow(ReadOnlyViolation::class)
        ->and($s->queue()->pushed())->toBe([]);
});

it('holds a subscription to a URL a delivery can go to and a secret worth signing with, and keeps both out of a dump', function () {
    $subscription = new WebhookSubscription('https://hooks.example.test/services/T0/s3cret-path', SECRET);

    expect(fn () => new WebhookSubscription('ftp://hooks.example.test/', SECRET))->toThrow(\InvalidArgumentException::class, 'scheme')
        ->and(fn () => new WebhookSubscription('https://user:pw@hooks.example.test/', SECRET))->toThrow(\InvalidArgumentException::class, 'credentials')
        ->and(fn () => new WebhookSubscription('https://hooks.example.test/', 'short'))->toThrow(\InvalidArgumentException::class, '32 bytes')
        ->and(print_r($subscription, true))->not->toContain(SECRET)
        ->and(print_r($subscription, true))->not->toContain('s3cret-path')
        ->and(json_encode($subscription, JSON_THROW_ON_ERROR))->not->toContain(SECRET);
});

it('keeps tries in the database, one delivery\'s in order and a tenant\'s own newest first', function () {
    $s = new Sender();
    $connection = $s->h->fixture->connection;
    DatabaseDeliveryLog::define(schemaOf($connection));
    $log = new DatabaseDeliveryLog($connection);
    $log->record(new DeliveryAttempt('d1', 'scripts', 'script.ran', 'acme', 1, DeliveryAttempt::FAILED, 503, 'status', 40, 100));
    $log->record(new DeliveryAttempt('d2', 'scripts', 'script.ran', 'globex', 1, DeliveryAttempt::DELIVERED, 200, null, 20, 150));
    $log->record(new DeliveryAttempt('d1', 'scripts', 'script.ran', 'acme', 2, DeliveryAttempt::FAILED, null, 'timeout', 10_000, 200));
    $log->record(new DeliveryAttempt('d1', 'scripts', 'script.ran', 'acme', 3, DeliveryAttempt::DELIVERED, 204, null, 30, 300));

    expect(array_column(array_map(get_object_vars(...), $log->attempts('d1')), 'attempt'))->toBe([1, 2, 3])
        ->and($log->attempts('d1')[1])->toEqual(new DeliveryAttempt('d1', 'scripts', 'script.ran', 'acme', 2, DeliveryAttempt::FAILED, null, 'timeout', 10_000, 200))
        ->and(array_map(static fn (DeliveryAttempt $a): ?int => $a->status, $log->recent('acme')))->toBe([204, null, 503])
        ->and($log->recent('acme', 1))->toHaveCount(1)
        ->and($log->recent('globex')[0]->delivery)->toBe('d2')
        ->and($log->recent('initech'))->toBe([])
        ->and(fn () => new DatabaseDeliveryLog($connection, 'deliveries; DROP TABLE x'))->toThrow(\InvalidArgumentException::class);
});

it('never sends a tenant\'s delivery to a private address the application allows for its own services', function () {
    if (!\function_exists('pcntl_fork') || !\function_exists('curl_init')) {
        test()->markTestSkipped('pcntl or curl is not available.');
    }

    // The operator opened loopback for a service of the application's own
    // (a search engine, an object store): the tenant's URL naming it is
    // still refused, and nothing reaches the service.
    $server = Server::start([static fn (array $r): string => Server::reply(200, 'an internal service')]);

    try {
        $s = new Sender(
            subscriptions: ['scripts' => new WebhookSubscription("http://127.0.0.1:{$server->port}/indexes/acme/documents", SECRET)],
            http: new PinnedHttp(new OutboundPolicy(allow: ['127.0.0.1'])),
        );
        $id = $s->sendAsAcme();
        $s->work();
    } finally {
        $requests = $server->stop();
    }

    expect($requests)->toBe([])
        ->and(array_map(static fn (DeliveryAttempt $a): array => [$a->outcome, $a->status], $s->log->attempts($id)))->toBe([[DeliveryAttempt::REFUSED, null]])
        ->and($s->queue()->failed()[0]->error)->toContain('(loopback)');
});

// The fakes see the request as built; this sees it as a receiver does, over
// a real socket: the signature a receiver checks covers the method, the
// target and the body as they arrive, after curl has written them.
it('delivers over the network to a receiver on this machine, which verifies the signature', function () {
    if (!\function_exists('pcntl_fork') || !\function_exists('curl_init')) {
        test()->markTestSkipped('pcntl or curl is not available.');
    }

    $server = Server::start([static function (array $r): string {
        $parsed = WebhookSignature::parse($r['headers'][WebhookSignature::HEADER] ?? null);
        $valid = $parsed !== null && hash_equals(WebhookSignature::mac(SECRET, $parsed[0], $parsed[1], $r['method'], $r['target'], $r['body']), $parsed[2]);

        return Server::reply($valid ? 202 : 401, 'ignored by the sender');
    }]);

    $http = new ThisMachine();
    try {
        $s = new Sender(
            subscriptions: ['scripts' => new WebhookSubscription("http://127.0.0.1:{$server->port}/hooks/in?account=7", SECRET)],
            http: $http,
        );
        $id = $s->sendAsAcme();
        $s->work();
    } finally {
        $requests = $server->stop();
    }

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['target'])->toBe('/hooks/in?account=7')
        ->and($http->publicOnly)->toBe([true])
        ->and(array_map(static fn (DeliveryAttempt $a): array => [$a->outcome, $a->status], $s->log->attempts($id)))->toBe([[DeliveryAttempt::DELIVERED, 202]]);
});

it('refuses a tenant\'s delivery to a name resolving inside an allowed private range, through any client', function () {
    $http = new FakeOutboundHttp(policy: new OutboundPolicy(allow: ['10.0.0.0/8']), resolver: new StaticResolver(['search.internal' => ['10.0.4.7']]));
    $s = new Sender(subscriptions: ['scripts' => new WebhookSubscription('https://search.internal:7700/indexes', SECRET)], http: $http);
    $id = $s->sendAsAcme();
    $s->work();

    expect($http->sent)->toBe([])
        ->and($s->log->attempts($id)[0]->outcome)->toBe(DeliveryAttempt::REFUSED);
});

/**
 * A sender keeping what it sends, in a table.
 *
 * @return array{Sender, DatabaseSentWebhooks}
 */
function keeping(): array
{
    $s = new Sender(static fn (): OutboundResponse => new OutboundResponse(204));
    DatabaseSentWebhooks::define(schemaOf($s->h->fixture->connection));
    $sent = new DatabaseSentWebhooks($s->h->fixture->connection);
    $s->webhooks = new OutboundWebhooks($s->h->dispatcher, clock: fn (): int => $s->h->now, sent: $sent, orm: $s->h->orm);

    return [$s, $sent];
}

describe('a resend', function () {
    it('sends the same event and data again as a new delivery, recording who asked and which it repeats', function () {
        [$s, $sent] = keeping();
        $first = $s->sendAsAcme();
        $s->h->now += 60;
        $again = $s->h->orm->actAs('acme', fn (): ?string => $s->webhooks->resend($first, new User('ops-1')));
        $s->work();

        [$one, $two] = $s->sent();
        $body = json_decode($two->body, true);

        expect($again)->not->toBeNull()->not->toBe($first)
            ->and($body['id'])->toBe($again)
            ->and($body['event'])->toBe('script.ran')
            ->and($body['data'])->toBe(json_decode($one->body, true)['data'])
            ->and($body['created_at'])->toBe(gmdate('Y-m-d\TH:i:s\Z', $s->h->now))
            // A new delivery, so a new nonce: a receiver that handled the first takes this one.
            ->and(signed($two)['nonce'])->toBe($again)
            ->and($sent->find('acme', (string) $again)?->resendOf)->toBe($first)
            ->and($sent->find('acme', (string) $again)?->resentBy)->toBe('ops-1')
            ->and(array_map(static fn ($r) => $r->delivery, $sent->resendsOf('acme', [$first])[$first]))->toBe([$again])
            ->and(array_keys($sent->many('acme', [$first, (string) $again, 'nope'])))->toEqualCanonicalizing([$first, $again])
            ->and($sent->many('globex', [$first]))->toBe([])
            ->and($sent->find('acme', $first)?->data)->not->toContain(SECRET);
    });

    it('keeps nothing sent as not resendable, so it is never sent again', function () {
        [$s, $sent] = keeping();
        $id = $s->h->orm->actAs('acme', fn (): string => $s->webhooks->send('scripts', 'script.ran', ['token' => 'short-lived'], resendable: false));

        expect($sent->find('acme', $id))->toBeNull()
            ->and($s->h->orm->actAs('acme', fn (): ?string => $s->webhooks->resend($id, new User('ops-1'))))->toBeNull()
            ->and($s->queue()->size(DeliverWebhook::QUEUE))->toBe(1);
    });

    it('finds nothing of another tenant\'s, nor a delivery never kept', function () {
        [$s] = keeping();
        $first = $s->sendAsAcme();

        expect($s->h->orm->actAs('globex', fn (): ?string => $s->webhooks->resend($first, new User('ops-1'))))->toBeNull()
            ->and($s->h->orm->actAs('acme', fn (): ?string => $s->webhooks->resend('no-such-delivery', new User('ops-1'))))->toBeNull()
            ->and($s->queue()->size(DeliverWebhook::QUEUE))->toBe(1);
    });

    it('keeps nothing a rollback takes back, and is refused without a store to read from', function () {
        [$s, $sent] = keeping();
        try {
            $s->h->orm->transaction(function () use ($s): void {
                $s->sendAsAcme();

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        expect($sent->prune(PHP_INT_MAX, 100))->toBe(0)
            ->and(fn () => new Sender()->webhooks->resend('d', new User('ops-1')))->toThrow(\LogicException::class)
            ->and(fn () => new OutboundWebhooks(new Sender()->h->dispatcher, sent: new ArraySentWebhooks()))->toThrow(\InvalidArgumentException::class);
    });
});
