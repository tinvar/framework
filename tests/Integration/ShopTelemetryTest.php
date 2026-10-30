<?php

declare(strict_types=1);

/**
 * The shop's telemetry (ADR 0041), against the real renderer pool: JSON log
 * lines in a file, filed under the id the response carries; /_health,
 * /_ready and /_metrics answered to the probe token alone, with no session issued; the
 * pool's renderers and the queues in the metrics; and a page's own vitals
 * report, taken at /_vitals.
 *
 * Skipped when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\ShopTelemetry;

use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\RequestId;
use Tinvar\Console\Output;
use Tinvar\Observability\Span;
use Tinvar\Observability\SpanExporter;
use Tinvar\Observability\Telemetry;
use Tinvar\Observability\Tracer;

const KEY = 'shop-telemetry-test-key-32-bytes!!!!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * The shop on a pool of two renderers, logging to a file of its own.
 *
 * @param \Closure(Kernel, string, Renderer): void $use given the kernel, its log file and the renderers
 */
function shop(\Closure $use): void
{
    $renderer = Renderer::start('msgpack', 'telemetry', processes: 2);
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-shop-log-');
    putenv("SHOP_LOG={$log}");
    try {
        // A database of its own size, so no other test in this process has
        // counted into the metrics file beside it.
        $use(Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(customers: 17)), $log, $renderer);
    } finally {
        putenv('SHOP_LOG');
        $renderer->stop();
        @unlink($log);
    }
}

/** @return list<array<string, mixed>> */
function records(string $log): array
{
    return array_values(array_filter(array_map(
        static fn (string $line): mixed => json_decode($line, true),
        file($log, FILE_IGNORE_NEW_LINES) ?: [],
    ), \is_array(...)));
}

function from(string $path, string $address = '127.0.0.1'): Request
{
    return new Request('GET', $path, ['accept' => 'text/html'], remoteAddress: $address);
}

/** A probe as a scraper sends it: over loopback, with the probe token. */
function probe(string $path): Request
{
    return new Request('GET', $path, ['authorization' => 'Bearer ' . Kernel::probeToken()], remoteAddress: '127.0.0.1');
}

it('logs a page to its file under the id the response carries, and counts its render', function () {
    shop(function (Kernel $kernel, string $log): void {
        $response = $kernel->handle(from('/'));
        $kernel->terminate();
        $id = (string) $response->header('x-request-id');

        $access = array_values(array_filter(records($log), static fn (array $r): bool => $r['message'] === 'request'));
        expect($response->status)->toBe(200)
            ->and(RequestId::isValid($id))->toBeTrue()
            ->and($access)->toHaveCount(1)
            ->and($access[0])->toMatchArray(['request_id' => $id, 'route' => 'shop.catalog', 'status' => 200, 'source' => 'php']);

        $metrics = $kernel->handle(probe('/_metrics'))->body();
        expect($metrics)->toContain('tinvar_http_requests_total{method="GET",route="shop.catalog",status="200"} 1')
            ->and($metrics)->toContain('tinvar_renders_total{kind="document",outcome="ok"} 1')
            ->and($metrics)->toMatch('/tinvar_query_calls_total\{kind="document"\} [1-9]/');
    });
});

it('answers /_health and /_ready to the probe token, with no session, and asks every renderer in the pool', function () {
    shop(function (Kernel $kernel): void {
        $health = $kernel->handle(probe('/_health'));
        $ready = $kernel->handle(probe('/_ready'));

        expect($health->status)->toBe(200)
            ->and($health->cookies())->toBe([])
            ->and($ready->status)->toBe(200)
            ->and(json_decode($ready->body(), true))->toBe(['status' => 'ok', 'checks' => ['database' => 'ok', 'renderer' => 'ok']])
            ->and($ready->cookies())->toBe([]);

        $metrics = $kernel->handle(probe('/_metrics'))->body();
        expect($metrics)->toContain("tinvar_renderers 2\n")
            ->and($metrics)->toContain("tinvar_renderers_answering 2\n")
            ->and($metrics)->toMatch('/tinvar_renderer_up\{slot="0"\} 1/')
            ->and($metrics)->toMatch('/tinvar_renderer_up\{slot="1"\} 1/')
            ->and($metrics)->toContain('tinvar_queue_depth{queue="mail"}');
    });
});

// Loopback included: a proxy or TLS terminator on the same machine connects
// from it, and may add no header to say so.
it('answers the probes to no one without the token, on this machine or off it', function () {
    shop(function (Kernel $kernel): void {
        foreach (['/_health', '/_ready', '/_metrics'] as $path) {
            foreach (['127.0.0.1', '::1', '203.0.113.9'] as $address) {
                $response = $kernel->handle(from($path, $address));

                expect($response->status)->toBe(404)->and($response->body())->toBe('Not found.');
            }
        }
    });
});

// Nothing set says nothing about loopback, and nothing is fail closed: the
// default ServerConfig reads is 0, so loopback without the token is a 404.
it('lets loopback without the token into no probe when TINVAR_PROBE_TRUST_LOOPBACK is unset or empty', function () {
    $saved = getenv('TINVAR_PROBE_TRUST_LOOPBACK');
    try {
        foreach ([null, ''] as $trust) {
            putenv($trust === null ? 'TINVAR_PROBE_TRUST_LOOPBACK' : "TINVAR_PROBE_TRUST_LOOPBACK={$trust}");
            expect(Kernel::server()->probeTrustLoopback)->toBeFalse();
            shop(function (Kernel $kernel): void {
                foreach (['/_health', '/_ready', '/_metrics'] as $path) {
                    expect($kernel->handle(from($path, '127.0.0.1'))->status)->toBe(404)
                        ->and($kernel->handle(from($path, '::1'))->status)->toBe(404);
                }
                expect($kernel->handle(probe('/_health'))->status)->toBe(200);
            });
        }
    } finally {
        putenv($saved === false ? 'TINVAR_PROBE_TRUST_LOOPBACK' : "TINVAR_PROBE_TRUST_LOOPBACK={$saved}");
    }
});

it('fails readiness when the renderers are gone, and says which check in the log', function () {
    shop(function (Kernel $kernel, string $log, Renderer $renderer): void {
        $renderer->stop();

        $ready = $kernel->handle(probe('/_ready'));

        expect($ready->status)->toBe(503)
            ->and(json_decode($ready->body(), true)['checks'])->toBe(['database' => 'ok', 'renderer' => 'failing'])
            ->and(array_column(records($log), 'check'))->toContain('renderer');
    });
});

// A test's telemetry in place of the environment's, read back from memory:
// what the testing layer's fakes are for mail and the queue.
it('takes a test\'s telemetry, and logs to it under the id the response carries', function () {
    $renderer = Renderer::start('msgpack', 'telemetry-fake');
    $sink = new MemorySink();
    try {
        $kernel = Kernel::fromEnvironment(
            $renderer->socketPath,
            key: KEY,
            database: Shop::database(customers: 17),
            telemetry: new Telemetry(new Logger($sink), new Metrics()),
        );
        $response = $kernel->handle(from('/'));
    } finally {
        $renderer->stop();
    }

    $access = array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'request'));
    expect($access)->toHaveCount(1)
        ->and($access[0])->toMatchArray(['route' => 'shop.catalog', 'status' => 200, 'request_id' => $response->header('x-request-id')]);
});

// ADR 0112: a page's loaders and statements are spans of its request, and
// a command run outside any request a trace of its own, with nothing a
// bound value or a literal put on any of them.
it('traces a page\'s loaders and statements, and a command as a trace of its own', function () {
    $renderer = Renderer::start('msgpack', 'telemetry-traces');
    $kept = new class implements SpanExporter {
        /** @var list<Span> */
        public array $spans = [];

        public function export(array $spans): void
        {
            array_push($this->spans, ...$spans);
        }
    };
    try {
        $telemetry = new Telemetry(new Logger(new MemorySink()), new Metrics(), new Tracer($kept, exportEvery: 0.0));
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(customers: 17), telemetry: $telemetry);
        $response = $kernel->handle(from('/'));
        $kernel->terminate();
        $telemetry->tracer?->export(true);
        $kernel->console->run('orders:advance', [], new Output(static function (): void {}, static function (): void {}));
        $telemetry->tracer?->export(true);
    } finally {
        $renderer->stop();
    }

    $request = array_values(array_filter($kept->spans, static fn (Span $s): bool => $s->traceId === $response->header('x-request-id')));
    $names = array_map(static fn (Span $s): string => $s->name, $request);
    $root = array_values(array_filter($request, static fn (Span $s): bool => $s->name === 'GET shop.catalog'))[0];
    $loaders = array_values(array_filter($request, static fn (Span $s): bool => str_starts_with($s->name, 'loader ')));
    $statements = array_values(array_filter($request, static fn (Span $s): bool => $s->kind === Span::KIND_CLIENT));
    $command = array_values(array_filter($kept->spans, static fn (Span $s): bool => $s->name === 'command orders:advance'))[0] ?? null;

    expect($names)->toContain('render document')
        ->and($loaders)->not->toBeEmpty()
        ->and($loaders[0]->parentSpanId)->toBe($root->spanId)
        ->and($statements)->not->toBeEmpty()
        ->and($statements[0]->attributes['db.system.name'])->toBe('sqlite')
        ->and($command)->not->toBeNull()
        ->and($command?->traceId)->not->toBe($root->traceId)
        // No customer's address, from a binding or a literal, on any span.
        ->and(json_encode($kept->spans))->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/');
});

// What the client entry's reporter does as a page is left: the route name
// from the page's route token, the CSRF token from its cookie, and the
// vitals as a histogram filed under that route.
it('takes a vitals report from a page it served, under the route its token names', function () {
    $renderer = Renderer::start('msgpack', 'telemetry-vitals');
    $metrics = new Metrics();
    try {
        $kernel = Kernel::fromEnvironment(
            $renderer->socketPath,
            key: KEY,
            database: Shop::database(customers: 17),
            telemetry: new Telemetry(new Logger(new MemorySink()), $metrics),
        );

        $page = $kernel->handle(from('/'));
        $cookies = [];
        foreach ($page->cookies() as $name => $line) {
            $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }
        $claims = json_decode((string) base64_decode(strtr(explode('.', (string) $page->header('x-route-token'))[1] ?? '', '-_', '+/')), true);
        $csrf = $cookies['__Host-XSRF-TOKEN'] ?? '';
        $body = json_encode(['route' => $claims['r'] ?? null, 'metrics' => ['LCP' => 1840.5, 'CLS' => 0.02, 'TTFB' => 95]], JSON_THROW_ON_ERROR);

        $report = $kernel->handle(new Request(
            'POST',
            '/_vitals',
            ['content-type' => 'application/json', 'x-xsrf-token' => $csrf],
            cookies: $cookies,
            remoteAddress: '127.0.0.1',
        ), $body);
    } finally {
        $renderer->stop();
    }

    expect($claims['r'] ?? null)->toBe('shop.catalog')
        ->and($report->status)->toBe(204)
        ->and($report->cookies())->toBe([])
        ->and($metrics->exposition())->toContain('tinvar_web_vitals_seconds_count{route="shop.catalog",metric="LCP"} 1')
        ->and($metrics->exposition())->toContain('tinvar_web_vitals_score_count{route="shop.catalog",metric="CLS"} 1');
});

// ADR 0093: every document names /_reports in its policy and in
// Reporting-Endpoints, and a browser's report -- sent with no CSRF token --
// is taken there without a session issued, counted, and answered with
// nothing.
it('names /_reports on its documents, and takes a CSP violation there with no session', function () {
    $renderer = Renderer::start('msgpack', 'telemetry-reports');
    $metrics = new Metrics();
    try {
        $kernel = Kernel::fromEnvironment(
            $renderer->socketPath,
            key: KEY,
            database: Shop::database(customers: 17),
            telemetry: new Telemetry(new Logger(new MemorySink()), $metrics),
        );

        $page = $kernel->handle(from('/'));
        $report = $kernel->handle(new Request(
            'POST',
            '/_reports',
            ['content-type' => 'application/reports+json'],
            remoteAddress: '127.0.0.1',
        ), json_encode([[
            'type' => 'csp-violation',
            'url' => 'https://pellwick.test/',
            'body' => ['effectiveDirective' => 'script-src-elem', 'disposition' => 'enforce', 'blockedURL' => 'inline'],
        ]], JSON_THROW_ON_ERROR));
    } finally {
        $renderer->stop();
    }

    expect($page->header('content-security-policy'))->toContain('report-uri /_reports; report-to default')
        ->and($page->header('reporting-endpoints'))->toBe('default="/_reports"')
        ->and($report->status)->toBe(204)
        ->and($report->body())->toBe('')
        ->and($report->cookies())->toBe([])
        ->and($metrics->exposition())->toContain('tinvar_problem_reports_total{type="csp-violation",directive="script-src-elem",disposition="enforce"} 1');
});
