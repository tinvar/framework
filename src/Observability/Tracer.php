<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Trace spans for each request, kept until the response has gone and then
 * handed to an exporter in batches -- never while the visitor waits.
 *
 * A request's root span is a server span named `GET shop.catalog` (method
 * and route, as OpenTelemetry's HTTP conventions name one), its trace id the
 * request id; its children are its loaders, its render and the query batches
 * the render asked for, and under those the statements sent to the database
 * and the calls made out. Work outside a request -- a job, a scheduled task,
 * a mail render on the queue -- is a trace of its own, begun the same way
 * (Telemetry::trace()). A span outside any trace is not kept.
 *
 * Which span a new one is a child of is the active one: the innermost span
 * opened with activate() and not yet closed, kept per fiber, since a
 * render's fibers each run a query of their own and suspend inside it. A
 * fiber with none of its own falls back to the main stack's, then the root.
 *
 * Sampled per request: a trusted caller's `traceparent` decides, and
 * otherwise `sampleRatio`. What is pending is capped, so an exporter that
 * cannot keep up costs dropped spans, not memory; and an exporter that fails
 * is reported and its batch dropped, since a span is worth less than the
 * worker it would hold.
 */
final class Tracer
{
    /** @var list<Span> finished, awaiting export */
    private array $finished = [];

    private ?Span $root = null;

    /** @var list<Span> spans activated outside any fiber, innermost last */
    private array $active = [];

    /** @var \WeakMap<object, list<Span>> each fiber's activated spans */
    private \WeakMap $fiberActive;

    private bool $sampled = false;

    /** The wall clock and hrtime() at one instant, so a span's times are both precise and absolute. */
    private readonly int $anchorNs;

    private readonly int $anchorHr;

    private float $exportedAt = 0.0;

    private bool $exportsAtShutdown = false;

    /** Spans dropped because too many were pending. */
    public private(set) int $dropped = 0;

    /** How many of those have been reported. */
    private int $droppedReported = 0;

    /** @var (\Closure(\Throwable): void)|null */
    private ?\Closure $report = null;

    /**
     * @param float $sampleRatio the share of requests traced when no trusted caller decided
     * @param int $batchSize spans that are exported at once, however recently the last batch went
     * @param float $exportEvery seconds between exports of a batch smaller than that
     * @param int $maxPending spans held for export, at most; the rest are dropped
     */
    public function __construct(
        private readonly SpanExporter $exporter,
        private readonly float $sampleRatio = 1.0,
        private readonly int $batchSize = 512,
        private readonly float $exportEvery = 5.0,
        private readonly int $maxPending = 4096,
        private readonly Redactor $redactor = new Redactor(),
    ) {
        if ($sampleRatio < 0.0 || $sampleRatio > 1.0) {
            throw new \InvalidArgumentException('A sample ratio is between 0 and 1.');
        }
        $this->anchorNs = (int) round(microtime(true) * 1e9);
        $this->anchorHr = hrtime(true);
        // A worker's first spans wait for the interval like any others.
        $this->exportedAt = microtime(true);
        $this->fiberActive = new \WeakMap();
    }

    /** @param \Closure(\Throwable): void $report where an exporter that fails is reported */
    public function reportTo(\Closure $report): void
    {
        $this->report = $report;
    }

    /**
     * The request's root span, in the trace $traceId -- continuing $parent's
     * when a trusted caller sent one.
     *
     * @param array<string, string|int|float|bool|array<array-key, mixed>> $attributes
     */
    public function startRequest(string $traceId, ?TraceContext $parent, string $name, array $attributes = [], int $kind = Span::KIND_SERVER): Span
    {
        $this->sampled = $parent !== null
            ? $parent->sampled
            : $this->sampleRatio >= 1.0 || ($this->sampleRatio > 0.0 && random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX < $this->sampleRatio);
        $this->active = [];
        $this->fiberActive = new \WeakMap();

        return $this->root = new Span($traceId, self::spanId(), $parent?->parentSpanId, $name, $kind, $this->now(), $attributes);
    }

    /** Whether the trace in hand is kept: what an outbound `traceparent` says of it. */
    public function sampled(): bool
    {
        return $this->root !== null && $this->sampled;
    }

    /**
     * The span a new one is a child of: the innermost activated on this
     * fiber, else outside any fiber, else the trace's root.
     */
    public function active(): ?Span
    {
        $fiber = \Fiber::getCurrent();
        $stack = $fiber === null ? $this->active : ($this->fiberActive[$fiber] ?? []);
        if ($stack !== []) {
            return $stack[\count($stack) - 1];
        }

        return $this->active === [] ? $this->root : $this->active[\count($this->active) - 1];
    }

    /** Makes $span the active one on this fiber, until deactivate() is given it. */
    public function activate(Span $span): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->active[] = $span;

            return;
        }
        $stack = $this->fiberActive[$fiber] ?? [];
        $stack[] = $span;
        $this->fiberActive[$fiber] = $stack;
    }

    /** Takes $span, and whatever was activated inside it and left, off this fiber's stack. */
    public function deactivate(Span $span): void
    {
        $fiber = \Fiber::getCurrent();
        $stack = $fiber === null ? $this->active : ($this->fiberActive[$fiber] ?? []);
        $at = array_search($span, $stack, true);
        if ($at === false) {
            return;
        }
        $stack = \array_slice($stack, 0, $at);
        if ($fiber === null) {
            $this->active = $stack;
        } else {
            $this->fiberActive[$fiber] = $stack;
        }
    }

    /**
     * A span inside the trace: a child of $parent, or of the active span.
     * Started now, or at $atHr, an hrtime(true) reading.
     *
     * @param array<string, string|int|float|bool|array<array-key, mixed>> $attributes
     */
    public function start(string $name, array $attributes = [], ?Span $parent = null, ?int $atHr = null, int $kind = Span::KIND_INTERNAL): Span
    {
        $parent ??= $this->active();

        return new Span(
            $parent->traceId ?? RequestId::generate(),
            self::spanId(),
            $parent?->spanId,
            $name,
            $kind,
            $atHr === null ? $this->now() : $this->toNs($atHr),
            $attributes,
        );
    }

    /** Ends $span, now or at $atHr, and keeps it for export if its request is sampled. */
    public function end(Span $span, ?int $atHr = null): void
    {
        $span->endNs = $atHr === null ? $this->now() : $this->toNs($atHr);

        if ($this->root === null || !$this->sampled || $span->traceId !== $this->root->traceId) {
            return;
        }
        if (\count($this->finished) >= $this->maxPending) {
            $this->dropped++;

            return;
        }

        $this->finished[] = $span;
    }

    /** The current request's root span, or null outside one. */
    public function root(): ?Span
    {
        return $this->root;
    }

    /** Ends the request's root span; spans ended after this are not kept. */
    public function endRequest(): void
    {
        if ($this->root !== null) {
            $this->end($this->root);
        }
        $this->root = null;
        $this->active = [];
        $this->fiberActive = new \WeakMap();
    }

    /**
     * Hands what is pending to the exporter, once a batch is full or
     * `exportEvery` has passed -- or now, when forced. Called after the
     * response has gone.
     */
    public function export(bool $force = false): void
    {
        if ($this->finished === []) {
            return;
        }

        if (!$force && \count($this->finished) < $this->batchSize && microtime(true) - $this->exportedAt < $this->exportEvery) {
            if (!$this->exportsAtShutdown) {
                $this->exportsAtShutdown = true;
                register_shutdown_function(function (): void {
                    $this->export(true);
                });
            }

            return;
        }

        $spans = $this->finished;
        $this->finished = [];
        $this->exportedAt = microtime(true);

        try {
            $this->exporter->export(array_map($this->redacted(...), $spans));
        } catch (\Throwable $e) {
            if ($this->report !== null) {
                ($this->report)($e);
            }
        }

        // Spans dropped for too many pending, said once each export finds
        // more of them, as a failed export is: counted alone, nobody heard.
        if ($this->dropped > $this->droppedReported) {
            $more = $this->dropped - $this->droppedReported;
            $this->droppedReported = $this->dropped;
            if ($this->report !== null) {
                ($this->report)(new \RuntimeException(\sprintf(
                    '%d span%s dropped: more than %d waited for export.',
                    $more,
                    $more === 1 ? ' was' : 's were',
                    $this->maxPending,
                )));
            }
        }
    }

    /** How many spans are waiting for export. */
    public function pending(): int
    {
        return \count($this->finished);
    }

    /** Now, in nanoseconds since the epoch. */
    public function now(): int
    {
        return $this->toNs(hrtime(true));
    }

    /** An hrtime(true) reading, in nanoseconds since the epoch. */
    public function toNs(int $hr): int
    {
        return $this->anchorNs + ($hr - $this->anchorHr);
    }

    /** Nanoseconds since the epoch, as the hrtime(true) reading toNs() reads them from. */
    public function toHr(int $ns): int
    {
        return $this->anchorHr + ($ns - $this->anchorNs);
    }

    /**
     * The span with what is in it passed through the Redactor, as a log
     * line's context is: an attribute named like a secret is replaced
     * whatever it holds -- a list, a number, a map -- and a map's own keys
     * are held to the same rule all the way down. Other numbers and
     * booleans pass as they are.
     */
    private function redacted(Span $span): Span
    {
        $span->name = $this->redactor->text($span->name);
        $span->statusMessage = $this->redactor->text($span->statusMessage);
        foreach ($span->attributes as $key => $value) {
            $span->attributes[$key] = match (true) {
                $this->redactor->isSensitiveKey($key) => Redactor::REDACTED,
                \is_string($value) => $this->redactor->text($value),
                \is_array($value) => self::attributeArray($this->redactor->value($value)),
                default => $value,
            };
        }

        return $span;
    }

    /**
     * What Redactor::value() made of an array, kept to what an attribute
     * holds: a nested array is one, as are the scalars and the strings it
     * leaves; anything else it would not write.
     *
     * @return array<array-key, mixed>
     */
    private static function attributeArray(mixed $value): array
    {
        return \is_array($value) ? $value : [(string) (\is_scalar($value) ? $value : '')];
    }

    private static function spanId(): string
    {
        do {
            $id = bin2hex(random_bytes(8));
        } while ($id === '0000000000000000');

        return $id;
    }
}
