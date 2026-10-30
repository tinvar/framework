<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Where work runs as a span of the trace in hand: what a loader and a call
 * out are given, rather than the telemetry, which reads renders and the
 * renderer's failures. Outbound HTTP and the reads stand below the renderer
 * (ADR 0114), so they ask this, and Telemetry is what answers.
 */
interface Spans
{
    /**
     * Runs $work in a span of the trace in hand, the active one while it
     * runs; outside a trace, or with no tracer, $work is handed null.
     *
     * @template T
     * @param array<string, string|int|float|bool|array<array-key, mixed>> $attributes
     * @param \Closure(?Span): T $work
     * @return T
     */
    public function span(string $name, array $attributes, \Closure $work, int $kind = Span::KIND_INTERNAL): mixed;

    /** The W3C `traceparent` a call out carries, naming $span as its parent: null outside a trace. */
    public function traceparent(?Span $span): ?string;
}
