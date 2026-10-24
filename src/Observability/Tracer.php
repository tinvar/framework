<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Trace spans for each request, kept until the response has gone and then
 * handed to an exporter in batches -- never while the visitor waits.
 *
 * A request's root span is a server span named `GET shop.catalog` (method
 * and route, as OpenTelemetry's HTTP conventions name one), its trace id the
 * request id; its children are the render and the query batches the render
 * asked for. A span outside any request is not kept: a job has no trace yet.
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

    private bool $sampled = false;

    /** The wall clock and hrtime() at one instant, so a span's times are both precise and absolute. */
    private readonly int $anchorNs;

    private readonly int $anchorHr;

    private float $exportedAt = 0.0;

    private bool $exportsAtShutdown = false;

    /** Spans dropped because too many were pending. */
    public private(set) int $dropped = 0;

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
    public function startRequest(string $traceId, ?TraceContext $parent, string $name, array $attributes = []): Span
    {
        $this->sampled = $parent !== null
            ? $parent->sampled
            : $this->sampleRatio >= 1.0 || ($this->sampleRatio > 0.0 && random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX < $this->sampleRatio);

        return $this->root = new Span($traceId, self::spanId(), $parent?->parentSpanId, $name, Span::KIND_SERVER, $this->now(), $attributes);
    }

    /**
     * A span inside the request: a child of $parent, or of the request's
     * root. Started now, or at $atHr, an hrtime(true) reading.
     *
     * @param array<string, string|int|float|bool|array<array-key, mixed>> $attributes
     */
    public function start(string $name, array $attributes = [], ?Span $parent = null, ?int $atHr = null): Span
    {
        $parent ??= $this->root;

        return new Span(
            $parent->traceId ?? RequestId::generate(),
            self::spanId(),
            $parent?->spanId,
            $name,
            Span::KIND_INTERNAL,
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
