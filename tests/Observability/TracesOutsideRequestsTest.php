<?php

declare(strict_types=1);

/**
 * Traces past the request (ADR 0112): a job, a scheduled task, a mail
 * render and a command each a trace of its own; loaders, statements and
 * calls out spans of whatever made them; a `traceparent` on what leaves;
 * and nothing on a span that a bound value, a literal or a path put there.
 */

namespace Tinvar\Tests\Observability\Background;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Schedule;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Console\ArrayScheduleStore;
use Tinvar\Console\Scheduler;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Jobs\Worker;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Email;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\RenderedMail;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\RequestId;
use Tinvar\Observability\Span;
use Tinvar\Observability\SpanExporter;
use Tinvar\Observability\StatementText;
use Tinvar\Observability\Telemetry;
use Tinvar\Observability\TraceContext;
use Tinvar\Observability\Tracer;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\TracedHttp;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderFailed;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

final class Kept implements SpanExporter
{
    /** @var list<Span> */
    public array $spans = [];

    public function export(array $spans): void
    {
        array_push($this->spans, ...$spans);
    }

    public function named(string $name): Span
    {
        foreach ($this->spans as $span) {
            if ($span->name === $name) {
                return $span;
            }
        }

        throw new \RuntimeException("No span named '{$name}' among: " . implode(', ', array_map(static fn (Span $s): string => $s->name, $this->spans)));
    }
}

/** @return array{Telemetry, Kept, MemorySink} */
function telemetry(): array
{
    $kept = new Kept();
    $sink = new MemorySink();

    return [new Telemetry(new Logger($sink, level: 'debug'), new Metrics(), new Tracer($kept, exportEvery: 0.0)), $kept, $sink];
}

#[Schedule('* * * * *')]
final class TracedTask
{
    public function __invoke(Telemetry $telemetry): void
    {
        $telemetry->logger->info('task ran');
    }
}

final readonly class ReceiptMail implements Mailable
{
    public function email(): Email
    {
        return new Email(new Address('ada@example.com'), 'Your receipt, Ada', 'receipt', new ShelfData());
    }
}

final readonly class ShelfData extends Data
{
    public function __construct(public string $label = 'Lamps') {}
}

#[Page('/shelf', view: 'shelf', name: 'shop.shelf')]
final class ShelfPage
{
    public static ?Orm $orm = null;

    public function __invoke(): ShelfData
    {
        self::$orm?->statements()->select("SELECT 'pellwick-secret' AS label");

        return new ShelfData();
    }
}

describe('work outside a request', function () {
    it('is a trace of its own, whose id every log line written in it carries', function () {
        [$telemetry, $kept, $sink] = telemetry();

        $answer = $telemetry->trace('task Nightly', ['code.namespace' => 'App\Nightly'], function (?Span $span) use ($telemetry): int {
            $telemetry->logger->info('inside');
            $telemetry->span('inner', [], static fn (): null => null);

            return 7;
        });
        $telemetry->logger->info('after');

        $root = $kept->named('task Nightly');
        $inner = $kept->named('inner');
        [$inside, $after] = $sink->records();
        expect($answer)->toBe(7)
            ->and(RequestId::isValid($root->traceId))->toBeTrue()
            ->and($root->parentSpanId)->toBeNull()
            ->and($inner->parentSpanId)->toBe($root->spanId)
            ->and($inside['request_id'] ?? null)->toBe($root->traceId)
            ->and($after)->not->toHaveKey('request_id');
    });

    it('marks its span failed by the class of what was thrown, never its message, and throws it on', function () {
        [$telemetry, $kept] = telemetry();

        expect(fn () => $telemetry->trace('job', [], static function (): never {
            throw new \DomainException('customer ada@example.com has no address');
        }))->toThrow(\DomainException::class);

        $span = $kept->named('job');
        expect($span->status)->toBe(Span::STATUS_ERROR)
            ->and($span->statusMessage)->toBe(\DomainException::class)
            ->and(json_encode($span->attributes))->not->toContain('ada@');
    });

    it('is a span of the request in hand when there is one: a job run sync is part of it', function () {
        [$telemetry, $kept] = telemetry();
        $telemetry->begin(new Request('GET', '/checkout'));

        $telemetry->trace('job SendReceipt', [], static fn (): null => null, Span::KIND_CONSUMER);
        $telemetry->end(Response::make(200));
        $telemetry->tracer?->export(true);

        expect($kept->named('job SendReceipt')->parentSpanId)->toBe($kept->named('GET unmatched')->spanId);
    });

    it('runs a queued job as a consumer span named by its class, its statements inside it', function () {
        [$telemetry, $kept, $sink] = telemetry();
        $h = new Harness();
        $telemetry->observe($h->orm->statements());
        $h->worker = new Worker($h->queue, $h->runner, $h->scope, clock: fn (): int => $h->now, telemetry: $telemetry);
        $h->dispatcher->dispatch(new RecordContext(note: 'ada@example.com'));

        expect($h->work())->toBe(1, implode("\n", $h->log));

        $job = $kept->named('job ' . RecordContext::class);
        expect($job->kind)->toBe(Span::KIND_CONSUMER)
            ->and($job->attributes)->toMatchArray(['messaging.destination.name' => 'default', 'tinvar.job.outcome' => 'done', 'tinvar.job.attempt' => 1])
            // Its arguments are not on it: they are a customer's as often as not.
            ->and(json_encode($kept->spans))->not->toContain('ada@example.com');
    });

    it('runs a scheduled task as a trace, with what it logs under the trace\'s id', function () {
        [$telemetry, $kept, $sink] = telemetry();
        $h = new Harness();
        $h->container->instance(Telemetry::class, $telemetry);
        $scheduler = new Scheduler([TracedTask::class], $h->orm, $h->container->resolver(), $h->scope, new ArrayScheduleStore(), telemetry: $telemetry);

        expect($scheduler->run(TracedTask::class))->toBe('ran');

        $task = $kept->named('task ' . TracedTask::class);
        $line = array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'task ran'))[0];
        expect($line['request_id'])->toBe($task->traceId);
    });
});

it('sends mail outside any request as a trace named by its template, never its recipient or subject', function () {
    [$telemetry, $kept] = telemetry();
    $renderer = new class implements MailRenderer {
        public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
        {
            return new RenderedMail('<p>Thanks</p>', 'Thanks');
        }
    };
    $mailer = new Mailer(new ArrayTransport(), $renderer, new Address('shop@example.com'), telemetry: $telemetry);

    $mailer->send(new ReceiptMail());

    expect($kept->named('mail receipt')->attributes)->toBe(['tinvar.mail.template' => 'receipt'])
        ->and(json_encode($kept->spans))->not->toContain('ada@')->not->toContain('Ada');
});

describe('a statement\'s span', function () {
    it('carries its text with every literal out, and none of its bindings', function () {
        [$telemetry, $kept] = telemetry();
        $connection = PdoConnection::sqlite(':memory:');
        $telemetry->observe($connection);
        $connection->execute('CREATE TABLE customers (id INTEGER, email TEXT)');

        $telemetry->trace('job', [], static function () use ($connection): void {
            $connection->execute('INSERT INTO customers (id, email) VALUES (?, ?)', [1, 'bound-secret@example.com']);
            $connection->select("SELECT * FROM customers WHERE email = 'literal-secret@example.com' AND id > 0 -- ada");
        });

        $insert = $kept->named('INSERT customers');
        $select = $kept->named('SELECT customers');
        expect($insert->kind)->toBe(Span::KIND_CLIENT)
            ->and($insert->attributes)->toBe(['db.system.name' => 'sqlite', 'db.query.text' => 'INSERT INTO customers (id, email) VALUES (?, ?)'])
            ->and($select->attributes['db.query.text'])->toBe('SELECT * FROM customers WHERE email = ? AND id > ?')
            ->and(json_encode($kept->spans))->not->toContain('secret@')->not->toContain('ada');
    });

    it('is not made outside a trace, nor kept with no tracer', function () {
        [$telemetry, $kept] = telemetry();
        $connection = PdoConnection::sqlite(':memory:');
        $telemetry->observe($connection);
        $connection->select('SELECT 1');
        $telemetry->tracer?->export(true);

        expect($kept->spans)->toBe([]);
    });

    it('is named by its operation and first table, and sanitizes what a driver quotes as a string', function () {
        expect(StatementText::summary(StatementText::sanitize('update "orders" set paid = 1')))->toBe('UPDATE orders')
            ->and(StatementText::summary('VACUUM'))->toBe('VACUUM')
            ->and(StatementText::summary('frobnicate the table'))->toBe('statement')
            ->and(StatementText::sanitize('SELECT $1, t1.x FROM t1 WHERE a = $tag$ x $tag$ AND b = E\'it\\\'s\''))->toBe('SELECT $1, t1.x FROM t1 WHERE a = ? AND b = ?')
            ->and(StatementText::sanitize('SELECT "ada@example.com"', 'mysql'))->toBe('SELECT ?')
            ->and(StatementText::sanitize('SELECT "orders"."id" FROM "orders"', 'postgresql'))->toBe('SELECT "orders"."id" FROM "orders"');
    });
});

describe('a loader', function () {
    it('is a span of the request, named by its class, its statements its children', function () {
        [$telemetry, $kept] = telemetry();
        $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        ShelfPage::$orm = $orm;
        $routes = new RouteRegistry();
        $routes->register(ShelfPage::class);
        $kernel = new Kernel(
            orm: $orm,
            routes: $routes,
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: new AuthContext(),
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            telemetry: $telemetry,
        );

        $kernel->handle(new Request('GET', '/shelf', ['accept' => 'text/html']), static fn (LoadedRoute $loaded): Response => Response::make(200, 'ok'));
        $telemetry->tracer?->export(true);

        $loader = $kept->named('loader ShelfPage');
        $statement = $kept->named('SELECT');
        expect($loader->parentSpanId)->toBe($kept->named('GET shop.shelf')->spanId)
            ->and($loader->attributes)->toMatchArray(['code.namespace' => ShelfPage::class, 'tinvar.segment' => 'page'])
            ->and($statement->parentSpanId)->toBe($loader->spanId)
            ->and($statement->attributes['db.query.text'])->toBe('SELECT ? AS label');
    });
});

describe('a failed boundary', function () {
    it('is a RenderFailed marked boundary while a request is handled, and nothing outside one', function () {
        $heard = [];
        $routes = new RouteRegistry();
        $routes->register(ShelfPage::class);
        $kernel = new Kernel(
            orm: new Orm(new Fixture(racks: 0, unitsPerRack: 0, sites: 0)->connection, new IdentityMap('test')),
            routes: $routes,
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: new AuthContext(),
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            renderFailed: [static function (RenderFailed $event) use (&$heard): void {
                $heard[] = $event;
            }],
            throwOnRenderError: true,
        );
        $boundary = new RendererException('the stock query threw', 'query_failed', 'b0undary1', reported: true);

        $kernel->boundaryFailed($boundary);
        $response = $kernel->handle(new Request('GET', '/shelf', ['accept' => 'text/html']), static function () use ($kernel, $boundary): Response {
            $kernel->boundaryFailed($boundary);

            return Response::make(200, 'the page, its boundary in its error state');
        });

        // The page went out: throw_on_error is for a render that failed whole.
        expect($response->status)->toBe(200)
            ->and($heard)->toHaveCount(1)
            ->and($heard[0]->boundary)->toBeTrue()
            ->and($heard[0]->digest)->toBe('b0undary1')
            ->and($heard[0]->request->path)->toBe('/shelf');
    });
});

describe('a call out', function () {
    it('is a client span carrying its traceparent, and says nothing of its path or query', function () {
        [$telemetry, $kept] = telemetry();
        $fake = new FakeOutboundHttp();
        $http = new TracedHttp($fake, $telemetry);

        $telemetry->trace('job DeliverWebhook', [], static function () use ($http): void {
            $http->send(OutboundRequest::post('https://hooks.example.com/in/ada@example.com?token=abc', '{}'));
        });

        $call = $kept->named('POST');
        $header = $fake->sent[0]->headers['traceparent'] ?? null;
        $context = TraceContext::parse($header);
        expect($call->kind)->toBe(Span::KIND_CLIENT)
            ->and($context?->traceId)->toBe($call->traceId)
            ->and($context?->parentSpanId)->toBe($call->spanId)
            ->and($context?->sampled)->toBeTrue()
            ->and($call->attributes)->toBe(['http.request.method' => 'POST', 'url.scheme' => 'https', 'server.address' => 'hooks.example.com', 'server.port' => 443, 'http.response.status_code' => 200])
            ->and(json_encode($kept->spans))->not->toContain('ada@')->not->toContain('token');
    });

    it('carries nothing outside a trace, leaves a caller\'s own traceparent, and propagates nothing when told not to', function () {
        [$telemetry] = telemetry();
        $fake = new FakeOutboundHttp();

        new TracedHttp($fake, $telemetry)->send(OutboundRequest::get('https://example.com/'));
        $telemetry->trace('job', [], static function () use ($fake, $telemetry): void {
            new TracedHttp($fake, $telemetry)->send(OutboundRequest::get('https://example.com/', ['traceparent' => 'mine']));
            new TracedHttp($fake, $telemetry, propagate: false)->send(OutboundRequest::get('https://example.com/'));
        });

        expect($fake->sent[0]->headers)->not->toHaveKey('traceparent')
            ->and($fake->sent[1]->headers['traceparent'])->toBe('mine')
            ->and($fake->sent[2]->headers)->not->toHaveKey('traceparent');
    });
});

describe('the active span', function () {
    it('is kept per fiber, so a fiber\'s statements are its own span\'s', function () {
        [$telemetry, $kept] = telemetry();
        $tracer = $telemetry->tracer;
        \assert($tracer !== null);

        $telemetry->trace('render', [], static function () use ($telemetry, $tracer): void {
            $fiber = new \Fiber(static function () use ($telemetry, $tracer): void {
                $telemetry->span('query a', [], static function () use ($tracer): void {
                    \Fiber::suspend();
                    $tracer->end($tracer->start('statement a'));
                });
            });
            $fiber->start();
            // Outside the fiber, its span is not the active one.
            $tracer->end($tracer->start('statement main'));
            $fiber->resume();
        });

        expect($kept->named('statement a')->parentSpanId)->toBe($kept->named('query a')->spanId)
            ->and($kept->named('statement main')->parentSpanId)->toBe($kept->named('render')->spanId);
    });
});
