<?php

declare(strict_types=1);

/**
 * Telemetry through the request path (ADR 0041): the Kernel files every
 * request under one id -- in its log lines, its metrics and spans, the
 * X-Request-Id it answers with and the render frame -- answers the probes
 * ahead of everything else, and leaves what has to leave the process for
 * terminate(), which the worker calls once the response has gone.
 */

namespace Tinvar\Tests\Observability\KernelTelemetry;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\Application;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Terminable;
use Tinvar\Http\Worker;
use Tinvar\Observability\ArrayMetricsStore;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\Probes;
use Tinvar\Observability\RequestId;
use Tinvar\Observability\Span;
use Tinvar\Observability\SpanExporter;
use Tinvar\Observability\Telemetry;
use Tinvar\Observability\Tracer;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

#[Page('/catalog', view: 'catalog', name: 'shop.catalog')]
final class CatalogPage {}

#[Page('/broken', view: 'broken', name: 'shop.broken')]
final class BrokenPage
{
    public function __invoke(): never
    {
        throw new \LogicException('a bug in a loader, for ada@example.com');
    }
}

/** Counts how often the application's own stack ran: a session would start here. */
final class StartSession implements Middleware
{
    public static int $runs = 0;

    public function handle(Request $request, \Closure $next): Response
    {
        self::$runs++;

        return $next($request);
    }
}

final class Exported implements SpanExporter
{
    /** @var list<Span> */
    public array $spans = [];

    public function export(array $spans): void
    {
        array_push($this->spans, ...$spans);
    }
}

/**
 * @return array{Kernel, Telemetry, MemorySink, ArrayMetricsStore, Exported}
 */
function kernel(bool $trustIncoming = false, bool $throwOnRenderError = false, bool $withLog = false): array
{
    $sink = new MemorySink();
    $store = new ArrayMetricsStore();
    $exported = new Exported();
    $telemetry = new Telemetry(
        new Logger($sink, level: 'debug'),
        new Metrics($store),
        new Tracer($exported, exportEvery: 0.0),
        trustIncoming: $trustIncoming,
    );

    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $routes = new RouteRegistry();
    $routes->registerAll([CatalogPage::class, BrokenPage::class]);
    StartSession::$runs = 0;

    $kernel = new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [StartSession::class],
        log: $withLog ? static function (): void {} : null,
        throwOnRenderError: $throwOnRenderError,
        telemetry: $telemetry,
        probes: new Probes(metrics: $telemetry->metrics),
    );

    return [$kernel, $telemetry, $sink, $store, $exported];
}

/** @return \Closure(LoadedRoute, Request): Response */
function respond(?string &$seen = null): \Closure
{
    return static function (LoadedRoute $loaded, Request $request) use (&$seen): Response {
        $seen = RequestId::of($request);

        return Response::make(200, '<html>' . $loaded->chain->page->view . '</html>', ['content-type' => 'text/html']);
    };
}

function get(string $path, array $headers = [], string $address = '127.0.0.1'): Request
{
    return new Request('GET', $path, ['accept' => 'text/html', ...$headers], remoteAddress: $address);
}

it('files a request under one id: the response header, the render, the access log line', function () {
    [$kernel, , $sink] = kernel();
    $seen = null;

    $response = $kernel->handle(get('/catalog'), respond($seen));

    $id = $response->header('x-request-id');
    expect($id)->toBeString()
        ->and(RequestId::isValid((string) $id))->toBeTrue()
        ->and($seen)->toBe($id);

    $access = array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'request'));
    expect($access)->toHaveCount(1)
        ->and($access[0])->toMatchArray([
            'level' => 'info',
            'request_id' => $id,
            'method' => 'GET',
            'route' => 'shop.catalog',
            'path' => '/catalog',
            'status' => 200,
        ])
        ->and($access[0]['duration_ms'])->toBeNumeric();
});

// A fragment's token is in its path, and the path is in the access line
// and the root span: neither may carry it.
it('writes no signed token from a path to the log or the trace', function () {
    [$kernel, , $sink, , $exported] = kernel();
    $token = 'v1.eyJjIjoiU2hpcHBpbmdVcGRhdGVzIiwicCI6e319.' . str_repeat('Mac_', 11);

    $kernel->handle(get("/_fragment/{$token}/{$token}"), respond());
    $kernel->terminate();

    $access = array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'request'))[0];
    expect($access['path'])->toBe('/_fragment/[token]/[token]')
        ->and(json_encode($sink->records()))->not->toContain('eyJjIjoi')
        ->and($exported->spans[0]->attributes['url.path'] ?? null)->toBe('/_fragment/[token]/[token]');
});

it('gives every request an id of its own', function () {
    [$kernel] = kernel();

    $a = $kernel->handle(get('/catalog'), respond())->header('x-request-id');
    $b = $kernel->handle(get('/catalog'), respond())->header('x-request-id');

    expect($a)->not->toBe($b);
});

it('logs a failure under the request\'s id and the digest the visitor is shown, redacted', function () {
    [$kernel, , $sink] = kernel();

    $response = $kernel->handle(get('/broken'), respond());

    $failure = array_values(array_filter($sink->records(), static fn (array $r): bool => isset($r['exception'])))[0] ?? [];
    expect($response->status)->toBe(500)
        ->and($failure['request_id'] ?? null)->toBe($response->header('x-request-id'))
        ->and($failure['level'] ?? null)->toBe('error')
        ->and($failure['digest'] ?? null)->toBeString()
        ->and($failure['message'] ?? '')->toBe('LogicException: a bug in a loader, for [email]');
});

it('leaves the log to an application that gave one of its own', function () {
    [$kernel, , $sink] = kernel(withLog: true);

    $kernel->handle(get('/broken'), respond());

    expect(array_filter($sink->records(), static fn (array $r): bool => isset($r['exception'])))->toBe([]);
});

it('counts requests by method, route and status, never by path', function () {
    [$kernel, $telemetry] = kernel();

    $kernel->handle(get('/catalog'), respond());
    $kernel->handle(get('/catalog'), respond());
    $kernel->handle(get('/broken'), respond());
    $kernel->handle(get('/no/such/page/12345'), respond());

    $text = $telemetry->metrics?->exposition() ?? '';
    expect($text)->toContain('tinvar_http_requests_total{method="GET",route="shop.catalog",status="200"} 2')
        ->and($text)->toContain('tinvar_http_requests_total{method="GET",route="shop.broken",status="500"} 1')
        ->and($text)->toContain('tinvar_http_requests_total{method="GET",route="unmatched",status="404"} 1')
        ->and($text)->toContain('tinvar_http_request_duration_seconds_count{method="GET",route="shop.catalog"} 2')
        ->and($text)->not->toContain('12345');
});

it('traces a request as a server span named for its method and route, with its id as the trace id', function () {
    [$kernel, , , , $exported] = kernel();

    $response = $kernel->handle(get('/catalog'), respond());
    $kernel->terminate();

    $root = $exported->spans[0] ?? null;
    expect($root)->toBeInstanceOf(Span::class);
    \assert($root instanceof Span);
    expect($root->name)->toBe('GET shop.catalog')
        ->and($root->kind)->toBe(Span::KIND_SERVER)
        ->and($root->traceId)->toBe($response->header('x-request-id'))
        ->and($root->attributes)->toMatchArray(['http.route' => 'shop.catalog', 'http.response.status_code' => 200]);
});

it('ignores a traceparent and an X-Request-Id it was not told to trust', function () {
    [$kernel] = kernel();
    $chosen = '0123456789abcdef0123456789abcdef';

    $response = $kernel->handle(get('/catalog', ['traceparent' => "00-{$chosen}-00f067aa0ba902b7-01", 'x-request-id' => $chosen]), respond());

    expect($response->header('x-request-id'))->not->toBe($chosen);
});

it('continues a trusted proxy\'s trace', function () {
    [$kernel, , , , $exported] = kernel(trustIncoming: true);
    $trace = '0123456789abcdef0123456789abcdef';

    $response = $kernel->handle(get('/catalog', ['traceparent' => "00-{$trace}-00f067aa0ba902b7-01"]), respond());
    $kernel->terminate();
    $fromHeader = $kernel->handle(get('/catalog', ['x-request-id' => 'FEDCBA9876543210FEDCBA9876543210']), respond());
    $malformed = $kernel->handle(get('/catalog', ['x-request-id' => "x\ny"]), respond());

    expect($response->header('x-request-id'))->toBe($trace)
        ->and($exported->spans[0]->parentSpanId ?? null)->toBe('00f067aa0ba902b7')
        ->and($fromHeader->header('x-request-id'))->toBe('fedcba9876543210fedcba9876543210')
        ->and(RequestId::isValid((string) $malformed->header('x-request-id')))->toBeTrue();
});

it('answers the probes ahead of the application\'s own stack, and only to whom it may', function () {
    [$kernel, , $sink] = kernel();

    $health = $kernel->handle(get('/_health'), respond());
    $metrics = $kernel->handle(get('/_metrics'), respond());
    $outside = $kernel->handle(get('/_health', address: '203.0.113.9'), respond());

    expect($health->status)->toBe(200)
        ->and(json_decode($health->body(), true))->toBe(['status' => 'ok'])
        ->and($metrics->body())->toContain('route="_health"')
        ->and($outside->status)->toBe(404)
        ->and($outside->body())->toBe('Not found.')
        ->and(StartSession::$runs)->toBe(0)
        ->and($kernel->answersProbe(get('/_ready')))->toBeTrue()
        ->and($kernel->answersProbe(get('/catalog')))->toBeFalse();

    // Logged, but at debug: a probe every few seconds is not traffic.
    $probes = array_values(array_filter($sink->records(), static fn (array $r): bool => ($r['route'] ?? '') === '_health'));
    expect($probes[0]['level'] ?? null)->toBe('debug');
});

it('counts a failure that escaped the Kernel as a 500, and keeps no request open after it', function () {
    [$kernel, $telemetry] = kernel(throwOnRenderError: true);
    $failing = static function (): Response {
        throw RendererException::fromErrorFrame(['code' => 'render_failed']);
    };

    expect(static fn () => $kernel->handle(get('/catalog'), $failing))->toThrow(RendererException::class);

    expect($telemetry->requestId())->toBeNull()
        ->and($telemetry->metrics?->exposition())->toContain('tinvar_http_requests_total{method="GET",route="shop.catalog",status="500"} 1');
});

it('joins the reset contract', function () {
    [$kernel] = kernel();

    expect($kernel->scope->names())->toContain('telemetry');
});

it('sends metrics to their store when terminated, not before', function () {
    $sink = new MemorySink();
    $store = new ArrayMetricsStore();
    $telemetry = new Telemetry(new Logger($sink), new Metrics($store, flushEvery: 0.0));
    $telemetry->begin(get('/'));
    $telemetry->end(Response::make(200));

    expect($store->all())->toBe([]);
    $telemetry->terminate();
    expect($store->all())->not->toBe([]);
});

describe('a render', function () {
    /**
     * A renderer that asks for one batch of queries, waits for the answer,
     * then ends as $ending says.
     *
     * @param list<array<string, mixed>> $ending
     */
    function renderer(string $path, array $ending): int
    {
        $server = stream_socket_server("unix://{$path}", $errno, $error);
        if ($server === false) {
            throw new \RuntimeException("Cannot listen on {$path}: {$error}");
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            $codec = new MessagePackCodec();
            $client = stream_socket_accept($server, 5);
            if ($client !== false) {
                stream_set_timeout($client, 5);
                fread($client, 65536);
                fwrite($client, $codec->encodeFrame(['t' => Frame::QUERY, 'q' => [
                    ['id' => 0, 'name' => 'availability', 'args' => []],
                    ['id' => 1, 'name' => 'users.passwords', 'args' => []],
                ]]));
                fread($client, 65536);
                foreach ($ending as $frame) {
                    fwrite($client, $codec->encodeFrame($frame));
                }
                fclose($client);
            }
            // Gone without running destructors: an inherited database
            // connection would hang up on the parent's socket.
            posix_kill(getmypid(), SIGKILL);
            exit(0);
        }
        fclose($server);

        return $pid;
    }

    /**
     * @param list<array<string, mixed>> $ending
     * @return array{Telemetry, Exported, ?\Throwable}
     */
    function rendered(array $ending, ?\Closure $boundaryFailed = null): array
    {
        if (!\function_exists('pcntl_fork')) {
            test()->markTestSkipped('pcntl is not available.');
        }
        $exported = new Exported();
        $telemetry = new Telemetry(new Logger(new MemorySink()), new Metrics(), new Tracer($exported, exportEvery: 0.0));
        $path = sys_get_temp_dir() . '/tinvar-tel-' . bin2hex(random_bytes(5)) . '.sock';
        $channel = new RenderChannel(
            $path,
            new QueryRunner(new QueryRegistry(), new Orm(new Fixture(racks: 0, unitsPerRack: 0, sites: 0)->connection, new IdentityMap('test')), new AllowAllGate()),
            deadlineSeconds: 5.0,
            report: static fn (\Throwable $e, ?string $digest): string => (string) $digest,
            telemetry: $telemetry,
            boundaryFailed: $boundaryFailed,
        );

        $request = $telemetry->begin(get('/catalog'));
        $pid = renderer($path, $ending);
        $failure = null;
        try {
            $channel->render(
                Frame::render('catalog', [], [], RequestId::of($request), 'nonce', ['availability']),
                ['availability'],
                static function (string $bytes): void {},
            );
        } catch (\Throwable $e) {
            $failure = $e;
        } finally {
            pcntl_waitpid($pid, $status);
            @unlink($path);
        }
        $telemetry->end(Response::make($failure === null ? 200 : 500));
        $telemetry->tracer?->export(true);

        return [$telemetry, $exported, $failure];
    }

    it('is counted and timed, its query batch with it, under the request\'s trace', function () {
        [$telemetry, $exported, $failure] = rendered([Frame::chunk('<html></html>'), ['t' => Frame::DONE]]);

        expect($failure)->toBeNull();
        $text = $telemetry->metrics?->exposition() ?? '';
        expect($text)->toContain('tinvar_renders_total{kind="document",outcome="ok"} 1')
            ->and($text)->toContain('tinvar_render_duration_seconds_count{kind="document"} 1')
            ->and($text)->toContain('tinvar_query_batch_duration_seconds_count{kind="document"} 1')
            ->and($text)->toContain('tinvar_query_calls_total{kind="document"} 2');

        $byName = [];
        foreach ($exported->spans as $span) {
            $byName[$span->name] = $span;
        }
        $batch = $byName['query batch'];
        $render = $byName['render document'];
        $root = $byName['GET unmatched'];
        expect($batch->parentSpanId)->toBe($render->spanId)
            ->and($render->parentSpanId)->toBe($root->spanId)
            ->and($render->attributes)->toMatchArray(['tinvar.view' => 'catalog', 'tinvar.render.bytes' => 13, 'tinvar.render.query_calls' => 2])
            // A name the route may not call is not repeated from the renderer.
            ->and($batch->attributes['tinvar.query.names'])->toBe(['availability', '_refused']);
    });

    it('counts a boundary rendered as an error row, hands it on, and leaves the render ok (ADR 0112)', function () {
        $heard = [];
        [$telemetry, $exported, $failure] = rendered([
            ['t' => Frame::REPORT, 'code' => 'query_failed', 'digest' => 'b0undary1', 'message' => 'the stock query threw'],
            Frame::chunk('<html></html>'),
            ['t' => Frame::DONE],
        ], static function (RendererException $e) use (&$heard): void {
            $heard[] = $e;
        });

        expect($failure)->toBeNull()
            ->and($heard)->toHaveCount(1)
            ->and($heard[0]->digest)->toBe('b0undary1')
            ->and($heard[0]->failure()->value)->toBe('query_failed')
            ->and($telemetry->metrics?->exposition())->toContain('tinvar_render_boundary_failures_total{kind="document",failure="query_failed"} 1')
            ->and($telemetry->metrics?->exposition())->toContain('tinvar_renders_total{kind="document",outcome="ok"} 1');

        $render = array_values(array_filter($exported->spans, static fn (Span $s): bool => $s->name === 'render document'))[0];
        expect($render->status)->toBe(Span::STATUS_UNSET)
            ->and($render->attributes['tinvar.render.boundary_failures'])->toBe(1);
    });

    it('is counted by how it failed', function () {
        [$telemetry, $exported, $failure] = rendered([['t' => Frame::ERROR, 'code' => 'browser_api_on_server', 'digest' => 'abc']]);

        expect($failure)->toBeInstanceOf(RendererException::class)
            ->and($telemetry->metrics?->exposition())->toContain('tinvar_renders_total{kind="document",outcome="browser_api_on_server"} 1');

        $render = array_values(array_filter($exported->spans, static fn (Span $s): bool => $s->name === 'render document'))[0];
        expect($render->status)->toBe(Span::STATUS_ERROR)->and($render->statusMessage)->toBe('browser_api_on_server');
    });
});

it('runs a terminable application\'s terminate after the response has gone, and before the reset', function () {
    $order = [];
    $application = new class ($order) implements Application, Terminable {
        /** @param list<string> $order */
        public function __construct(private array &$order) {}

        public function handle(Request $request, string $rawBody = ''): Response
        {
            $this->order[] = 'handle';

            return Response::make(200, 'ok');
        }

        public function reset(): void
        {
            $this->order[] = 'reset';
        }

        public function terminate(): void
        {
            $this->order[] = 'terminate';
        }
    };

    $served = new Worker(
        boot: static fn (): Application => $application,
        maxRequests: 1,
        handleRequest: static function (callable $handler): bool {
            $handler();

            return true;
        },
        globals: static fn (): array => ['server' => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], 'query' => [], 'body' => [], 'cookies' => [], 'input' => ''],
        send: static function (Response $response) use (&$order): void {
            $order[] = 'send';
        },
        finish: static function () use (&$order): void {
            $order[] = 'finish';
        },
    )->run();

    expect($served)->toBe(1)->and($order)->toBe(['handle', 'send', 'finish', 'terminate', 'reset']);
});
