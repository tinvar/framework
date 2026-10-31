<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Http\Request;
use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\ObservesStatements;
use Tinvar\Http\Response;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderResult;

/**
 * What the Kernel and the render channel report a request to: its id, its
 * log lines, its metrics and its spans.
 *
 * The Kernel begins a request here before anything else runs and ends it
 * once the response is built, which is when its access log line is written,
 * its counters move and its root span closes, and X-Request-Id goes on the
 * response. The render channel reports each render and each query batch.
 * What has to leave the process -- metrics to their store, spans to their
 * exporter -- waits for terminate(), which runs after the response has
 * gone (Http\Terminable).
 *
 * A request's id goes to the renderer in the render frame, and nothing
 * else of this does: no exporter's credentials, no metrics, no log. The
 * renderer writes its own log lines under the same id.
 */
final class Telemetry implements Spans
{
    public const string HTTP_REQUESTS = 'tinvar_http_requests_total';
    public const string HTTP_DURATION = 'tinvar_http_request_duration_seconds';
    public const string RENDERS = 'tinvar_renders_total';
    public const string RENDER_DURATION = 'tinvar_render_duration_seconds';
    public const string RENDER_FIRST_BYTE = 'tinvar_render_first_byte_seconds';
    public const string RENDER_QUERY_TIME = 'tinvar_render_query_seconds';
    public const string QUERY_BATCH_DURATION = 'tinvar_query_batch_duration_seconds';
    public const string QUERY_CALLS = 'tinvar_query_calls_total';
    public const string RENDER_RETRIES = 'tinvar_render_retries_total';

    /** A query batch is answered in PHP, mostly from memory or one statement: finer buckets than a request's. */
    public const array QUERY_BUCKETS = [0.0005, 0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0];

    /** The methods a request is counted under by name; any other is `other`. */
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** The routes Probes answers, logged at debug: a probe every few seconds is not traffic. */
    private const array PROBES = ['_health', '_ready', '_metrics'];

    public const string RENDER_BOUNDARY_FAILURES = 'tinvar_render_boundary_failures_total';
    public const string JOBS = 'tinvar_jobs_total';
    public const string JOB_DURATION = 'tinvar_job_duration_seconds';

    /** A job's buckets, in seconds: a mail is milliseconds, an export minutes. */
    public const array JOB_BUCKETS = [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 300.0, 900.0];

    /** The longest statement a span carries as `db.query.text`; the rest is cut. */
    public const int MAX_STATEMENT = 2048;

    /** @var array{id: string, started: int, method: string, route: string, path: string, span: ?Span}|null */
    private ?array $current = null;

    /** The trace id of work outside a request -- a job, a task -- while it runs; what its log lines carry. */
    private ?string $background = null;

    /**
     * @param bool $trustIncoming believe a `traceparent` or an `X-Request-Id`
     *        the request arrives with -- only behind a proxy that sets or
     *        strips them, since otherwise a client chooses the id its requests
     *        are logged under
     * @param string $accessLog the level each request's line is written at
     */
    public function __construct(
        public readonly Logger $logger,
        public readonly ?Metrics $metrics = null,
        public readonly ?Tracer $tracer = null,
        private readonly bool $trustIncoming = false,
        private readonly string $accessLog = 'info',
    ) {
        if (!isset(Logger::LEVELS[$accessLog])) {
            throw new \InvalidArgumentException("No log level '{$accessLog}'.");
        }

        $logger->contextFrom($this->fields(...));
        $report = function (\Throwable $e): void {
            $this->logger->exception($e, level: 'warning');
        };
        $tracer?->reportTo($report);

        if ($metrics !== null) {
            $metrics->reportTo($report);
            self::define($metrics);
        }
    }

    /**
     * Starts a request: its id, from a trusted caller or fresh, on the
     * request as RequestId::ATTRIBUTE, and its root span.
     */
    public function begin(Request $request): Request
    {
        $parent = $this->trustIncoming ? TraceContext::parse($request->header('traceparent')) : null;
        $incoming = $this->trustIncoming ? strtolower(trim($request->header(RequestId::HEADER) ?? '')) : '';
        $id = $parent->traceId ?? (RequestId::isValid($incoming) ? $incoming : RequestId::generate());
        $method = \in_array($request->method, self::METHODS, true) ? $request->method : 'other';

        $this->current = [
            'id' => $id,
            'started' => hrtime(true),
            'method' => $method,
            'route' => 'unmatched',
            'path' => $request->path,
            'span' => $this->tracer?->startRequest($id, $parent, $method, [
                'http.request.method' => $method,
                'url.path' => $request->path,
            ]),
        ];

        return $request->withAttribute(RequestId::ATTRIBUTE, $id);
    }

    /**
     * Names what the request reached, for its metrics, its log line and its
     * span: a route's name, or an endpoint's -- never a path, whose ids would
     * make a label set per record.
     */
    public function route(string $route): void
    {
        if ($this->current !== null) {
            $this->current['route'] = $route;
        }
    }

    /**
     * Ends the request: counted, logged, its root span closed, and its id on
     * the response. A null response is a request that escaped the Kernel,
     * counted as the 500 it becomes.
     */
    public function end(?Response $response, ?\Throwable $failure = null): ?Response
    {
        $current = $this->current;
        if ($current === null) {
            return $response;
        }

        $status = $response->status ?? 500;
        $seconds = (hrtime(true) - $current['started']) / 1e9;
        ['method' => $method, 'route' => $route] = $current;

        $this->metrics?->increment(self::HTTP_REQUESTS, ['method' => $method, 'route' => $route, 'status' => $status]);
        $this->metrics?->observe(self::HTTP_DURATION, $seconds, ['method' => $method, 'route' => $route]);

        $this->logger->log(\in_array($route, self::PROBES, true) ? 'debug' : $this->accessLog, 'request', [
            'method' => $method,
            'route' => $route,
            'path' => $current['path'],
            'status' => $status,
            'duration_ms' => round($seconds * 1000, 2),
            ...($failure === null ? [] : ['failure' => $failure::class]),
        ]);

        $span = $current['span'];
        if ($span !== null) {
            $span->name = "{$method} {$route}";
            $span->set('http.route', $route)->set('http.response.status_code', $status);
            if ($status >= 500) {
                $span->fail((string) $status);
            }
            $this->tracer?->endRequest();
        }

        $this->current = null;

        return $response?->withHeader(RequestId::HEADER, $current['id']);
    }

    /**
     * Drops a request that was never ended, for the reset contract: a
     * worker's next request must not log under this one's id.
     */
    public function forget(): void
    {
        $this->current = null;
        $this->tracer?->endRequest();
    }

    /**
     * Sends what this request left to send -- metrics to their store, spans
     * to their exporter -- each when its own interval says. Runs after the
     * response has gone; never throws.
     */
    public function terminate(): void
    {
        try {
            $this->metrics?->flush();
            $this->tracer?->export();
        } catch (\Throwable $e) {
            $this->logger->exception($e, level: 'warning');
        }
    }

    /** The current request's id, or null outside a request. */
    public function requestId(): ?string
    {
        return $this->current['id'] ?? null;
    }

    /**
     * The id of the trace in hand: the request's, or that of the work
     * outside one that trace() is running -- a job, a mail. What a render
     * frame that is not a request's carries as its `rid`, so the
     * renderer's lines about it are filed under the same id as PHP's.
     */
    public function traceId(): ?string
    {
        return $this->current['id'] ?? $this->background;
    }

    /**
     * Runs $work as a trace of its own when no request or other trace is in
     * hand -- a job taken off the queue, a scheduled task, a mail render --
     * and as a span of the one in hand when there is: a job run sync inside
     * the request that dispatched it is part of that request.
     *
     * A trace of its own gets a fresh id, which every log line written
     * while it runs carries as its `request_id`, as a request's lines do;
     * its spans leave as a request's do, the moment it ends, each batch when
     * its interval says. $work is handed its span to say how it ended, or
     * null where nothing is traced. A throw marks the span failed, by its
     * class -- never its message -- and goes on.
     *
     * @template T
     * @param array<string, string|int|float|bool|array<array-key, mixed>> $attributes
     * @param \Closure(?Span): T $work
     * @return T
     */
    public function trace(string $name, array $attributes, \Closure $work, int $kind = Span::KIND_INTERNAL): mixed
    {
        if ($this->current !== null || $this->background !== null) {
            return $this->span($name, $attributes, $work, $kind);
        }

        $id = RequestId::generate();
        $this->background = $id;
        $span = $this->tracer?->startRequest($id, null, $name, $attributes, $kind);
        try {
            return $work($span);
        } catch (\Throwable $e) {
            $span?->fail($e::class);

            throw $e;
        } finally {
            $this->tracer?->endRequest();
            $this->background = null;
            $this->terminate();
        }
    }

    /**
     * Runs $work in a span of the trace in hand, the active one while it
     * runs, so what it does inside is that span's children: a loader's
     * statements, a job's calls out. Nothing is made outside a trace, or
     * with no tracer; $work is handed null then.
     *
     * @template T
     * @param array<string, string|int|float|bool|array<array-key, mixed>> $attributes
     * @param \Closure(?Span): T $work
     * @return T
     */
    public function span(string $name, array $attributes, \Closure $work, int $kind = Span::KIND_INTERNAL): mixed
    {
        $tracer = $this->tracer;
        if ($tracer === null || $tracer->root() === null) {
            return $work(null);
        }

        $span = $tracer->start($name, $attributes, kind: $kind);
        $tracer->activate($span);
        try {
            return $work($span);
        } catch (\Throwable $e) {
            $span->fail($e::class);

            throw $e;
        } finally {
            $tracer->deactivate($span);
            $tracer->end($span);
        }
    }

    /**
     * The W3C `traceparent` a call out carries, naming $span -- the client
     * span of that call -- as its parent: null outside a trace. Only the ids
     * and the sampled flag: no state, no baggage, nothing the trace holds.
     */
    public function traceparent(?Span $span): ?string
    {
        $tracer = $this->tracer;
        if ($span === null || $tracer === null || $tracer->root() === null) {
            return null;
        }

        return "00-{$span->traceId}-{$span->spanId}-" . ($tracer->sampled() ? '01' : '00');
    }

    /**
     * Has $connection report each statement it sends as a span (statement()),
     * where there is a tracer to keep them and the connection can tell. The
     * observer is handed no bindings, so no bound value reaches a span.
     */
    public function observe(Connection $connection): void
    {
        if ($this->tracer === null || !$connection instanceof ObservesStatements) {
            return;
        }

        $system = match ($connection instanceof NamesDriver ? $connection->driver() : '') {
            'pgsql' => 'postgresql',
            'mysql' => 'mysql',
            'sqlite' => 'sqlite',
            default => 'other_sql',
        };
        $connection->observeStatements(function (string $sql, int $started, int $ended, ?\Throwable $failure) use ($system): void {
            $this->statement($sql, $system, $started, $ended, $failure);
        });
    }

    /**
     * One statement the database was sent, as a client span of whatever is
     * active: a loader, a query batch, a job. Its text is the statement's
     * with every literal taken out (StatementText) -- its bound values were
     * never handed over -- and cut to MAX_STATEMENT; its name is the
     * operation and the first table it names, as OpenTelemetry's database
     * conventions name one.
     */
    public function statement(string $sql, string $system, int $startedHr, int $endedHr, ?\Throwable $failure = null): void
    {
        // Not sampled, the span would be dropped as it ends: none is made,
        // and the statement's text is not sanitized for it.
        $tracer = $this->tracer;
        if ($tracer === null || !$tracer->sampled()) {
            return;
        }

        $text = StatementText::sanitize($sql, $system);
        $span = $tracer->start(StatementText::name($sql, $system), [
            'db.system.name' => $system,
            'db.query.text' => \strlen($text) > self::MAX_STATEMENT ? substr($text, 0, self::MAX_STATEMENT) . '...' : $text,
        ], atHr: $startedHr, kind: Span::KIND_CLIENT);
        if ($failure !== null) {
            $span->set('error.type', $failure::class)->fail($failure::class);
        }
        $tracer->end($span, $endedHr);
    }

    /** @param array<string, mixed> $frame a render, fragment or mail frame, as it is sent */
    public function renderStarted(array $frame): RenderTrace
    {
        $kind = match ($frame['t'] ?? null) {
            'fragment' => 'fragment',
            'mail' => 'mail',
            default => ($frame['rsc'] ?? false) === true ? 'payload' : 'document',
        };

        // A span only for a sampled request: it is all a render's span, and
        // the query batches and renderer phases under it, are kept for.
        $span = null;
        if ($this->tracer?->sampled() === true) {
            $view = $frame['route'] ?? $frame['template'] ?? null;
            $span = $this->tracer->start("render {$kind}", [
                'tinvar.render.kind' => $kind,
                ...(\is_string($view) ? ['tinvar.view' => $view] : []),
            ]);
        }

        return new RenderTrace($kind, $span);
    }

    /**
     * One batch of query calls the renderer sent, and how long PHP took to
     * answer it. Its query names come from the renderer, so only those on the
     * route's allowlist are named; any other is `_refused`, as it was.
     *
     * @param list<array{id: int, name: string, args: array<string, mixed>}> $calls
     * @param list<string> $allowed
     */
    public function queryBatch(RenderTrace $trace, array $calls, array $allowed, int $startedHr, int $endedHr): void
    {
        $this->metrics?->observe(self::QUERY_BATCH_DURATION, ($endedHr - $startedHr) / 1e9, ['kind' => $trace->kind]);
        $this->metrics?->increment(self::QUERY_CALLS, ['kind' => $trace->kind], \count($calls));

        if ($trace->span === null || $this->tracer === null) {
            return;
        }

        $names = [];
        foreach ($calls as $call) {
            $names[\in_array($call['name'], $allowed, true) ? $call['name'] : '_refused'] = true;
        }
        $span = $this->tracer->start('query batch', [
            'tinvar.query.calls' => \count($calls),
            'tinvar.query.names' => \array_slice(array_map(strval(...), array_keys($names)), 0, 32),
        ], $trace->span, $startedHr);
        $this->tracer->end($span, $endedHr);
    }

    public function renderFinished(RenderTrace $trace, RenderResult $result): void
    {
        $labels = ['kind' => $trace->kind];
        $this->metrics?->increment(self::RENDERS, [...$labels, 'outcome' => 'ok']);
        $this->metrics?->observe(self::RENDER_DURATION, $result->totalMs / 1000, $labels);
        $this->metrics?->observe(self::RENDER_FIRST_BYTE, $result->timeToFirstByteMs / 1000, $labels);
        $this->metrics?->observe(self::RENDER_QUERY_TIME, $result->queryMs / 1000, $labels);

        if ($trace->span !== null) {
            $this->rendererPhases($trace->span, $result->phases);
            $trace->span->set('tinvar.render.bytes', $result->bytes)
                ->set('tinvar.render.chunks', $result->chunks)
                ->set('tinvar.render.query_calls', $result->queryCalls)
                ->set('tinvar.render.query_batches', $result->queryBatches)
                ->set('tinvar.render.first_byte_ms', round($result->timeToFirstByteMs, 3))
                ->set('tinvar.render.renderer_wait_ms', round($result->waitingOnRendererMs, 3));
            $this->tracer?->end($trace->span);
        }
    }

    /**
     * What the renderer timed of its own work (ADR 0128) -- its Flight
     * payload, its HTML shell, the HTML after it, an email -- drawn as
     * child spans of the render's, named `renderer flight` and so on.
     *
     * The renderer reports offsets from when it was handed the frame, on
     * its own clock, and this process places them from when it started the
     * render, a connect and a write earlier: each phase is drawn that much
     * early, and held within the render's span, which ends now. Only the
     * name, from a fixed list, and the two times cross: the renderer sends
     * nothing else of telemetry, and holds nothing to send it with.
     *
     * @param list<array{name: string, start: float, end: float}> $phases
     */
    private function rendererPhases(Span $render, array $phases): void
    {
        $tracer = $this->tracer;
        if ($tracer === null || $phases === []) {
            return;
        }

        $now = $tracer->now();
        foreach ($phases as $phase) {
            $to = min($render->startNs + (int) round($phase['end'] * 1e6), $now);
            $from = min($render->startNs + (int) round($phase['start'] * 1e6), $to);
            $span = $tracer->start("renderer {$phase['name']}", ['tinvar.renderer.phase' => $phase['name']], $render, $tracer->toHr($from));
            $tracer->end($span, $tracer->toHr($to));
        }
    }

    /** A render that failed, counted by how the renderer's failure was classified. */
    public function renderFailed(RenderTrace $trace, \Throwable $e): void
    {
        $outcome = match (true) {
            $e instanceof RendererException => $e->failure()->value,
            $e instanceof ProtocolException => 'protocol_violation',
            default => 'error',
        };
        $this->metrics?->increment(self::RENDERS, ['kind' => $trace->kind, 'outcome' => $outcome]);

        if ($trace->span !== null) {
            $trace->span->set('tinvar.render.failure', $outcome)->fail($outcome);
            $this->tracer?->end($trace->span);
        }
    }

    /**
     * A subtree the renderer rendered as an error row -- a Suspense
     * boundary's in a page, or a fragment's -- in a render that went on:
     * counted by kind and by how it was classified, and an event on the
     * render's span, which stays OK. The response is still a 200; this is
     * how a dashboard sees the boundaries that were not.
     */
    public function boundaryFailed(RenderTrace $trace, string $failure): void
    {
        $this->metrics?->increment(self::RENDER_BOUNDARY_FAILURES, ['kind' => $trace->kind, 'failure' => $failure]);
        if ($trace->span !== null) {
            $failed = $trace->span->attributes['tinvar.render.boundary_failures'] ?? 0;
            $trace->span->set('tinvar.render.boundary_failures', (\is_int($failed) ? $failed : 0) + 1);
        }
    }

    /**
     * A failed render the retry hook is trying again (ADR 0093): counted by
     * how it failed, and said in the log, at warning -- a renderer that
     * crashes or cannot be reached is worth a look even when the next try
     * hides it from the visitor.
     */
    public function renderRetried(RenderTrace $trace, \Throwable $e, int $retry): void
    {
        $failure = $e instanceof RendererException ? $e->failure()->value : 'error';
        $this->metrics?->increment(self::RENDER_RETRIES, ['kind' => $trace->kind, 'failure' => $failure]);
        $this->logger->warning('render retried', ['kind' => $trace->kind, 'failure' => $failure, 'retry' => $retry]);

        // Not a failed render -- that is counted only for a render that is
        // not tried again (renderFailed()) -- but this try's span ends here,
        // failed, saying it was tried again.
        if ($trace->span !== null) {
            $trace->span->set('tinvar.render.failure', $failure)->set('tinvar.render.retried', true)->fail($failure);
            $this->tracer?->end($trace->span);
        }
    }

    /**
     * One job a worker took off its queue, and how it went: counted by its
     * queue, its class and its outcome, and timed by queue and class. The
     * class is the one its signed payload names, so a label holds only the
     * application's own jobs -- or `unreadable`, for a payload that would
     * not open. Counted where it ran, and flushed as the job's trace ends
     * (trace()), into whatever store this process writes: the queue
     * worker's is one the server reads too.
     */
    public function jobFinished(string $queue, ?string $class, string $outcome, float $seconds): void
    {
        $job = $class ?? 'unreadable';
        $this->metrics?->increment(self::JOBS, ['queue' => $queue, 'job' => $job, 'outcome' => $outcome]);
        $this->metrics?->observe(self::JOB_DURATION, $seconds, ['queue' => $queue, 'job' => $job]);
    }

    /** @return array<string, string> what every log line carries: the request's id */
    private function fields(): array
    {
        $id = $this->current['id'] ?? $this->background;

        return $id === null ? [] : ['request_id' => $id];
    }

    /** The framework's own metrics, on whichever registry the application gave. */
    private static function define(Metrics $metrics): void
    {
        $metrics
            ->counter(self::HTTP_REQUESTS, 'HTTP requests answered, by method, route and status.', ['method', 'route', 'status'])
            ->histogram(self::HTTP_DURATION, 'Seconds from a request reaching the Kernel to its response being built.', ['method', 'route'])
            ->counter(self::RENDERS, 'Renders, by kind and outcome: ok, or the failure as the renderer classified it.', ['kind', 'outcome'])
            ->histogram(self::RENDER_DURATION, 'Seconds a successful render took, from connecting to the renderer to its done frame.', ['kind'])
            ->histogram(self::RENDER_FIRST_BYTE, 'Seconds from the start of a render to its first output.', ['kind'])
            ->histogram(self::RENDER_QUERY_TIME, 'Seconds one render spent answering its query calls.', ['kind'])
            ->histogram(self::QUERY_BATCH_DURATION, 'Seconds PHP took to answer one batch of query calls.', ['kind'], self::QUERY_BUCKETS)
            ->counter(self::QUERY_CALLS, 'Query calls renders sent.', ['kind'])
            ->counter(self::RENDER_RETRIES, 'Failed renders tried again by the retry hook, by kind and failure.', ['kind', 'failure'])
            ->counter(self::RENDER_BOUNDARY_FAILURES, 'Subtrees rendered as an error row in a render that went on -- a Suspense boundary, a fragment -- by kind and failure.', ['kind', 'failure'])
            ->counter(self::JOBS, 'Jobs workers took off their queues, by queue, class and outcome: done, released, failed, paused, handed back, skipped or lost.', ['queue', 'job', 'outcome'])
            ->histogram(self::JOB_DURATION, 'Seconds one try of a job took on its worker, by queue and class.', ['queue', 'job'], self::JOB_BUCKETS);
    }
}
