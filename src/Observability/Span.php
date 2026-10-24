<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * One timed piece of a request -- the request itself, its render, a batch
 * of queries -- in OpenTelemetry's model: ids in its hex forms, times in
 * nanoseconds since the epoch, a kind and a status as its enums number
 * them, so an exporter writes it as OTLP without translating anything.
 *
 * Attributes are the caller's to keep free of personal data; the Tracer
 * passes every string through the Redactor as a second line of defence.
 */
final class Span
{
    public const int KIND_INTERNAL = 1;
    public const int KIND_SERVER = 2;

    public const int STATUS_UNSET = 0;
    public const int STATUS_ERROR = 2;

    public ?int $endNs = null;

    public int $status = self::STATUS_UNSET;

    public string $statusMessage = '';

    /**
     * @param array<string, string|int|float|bool|array<array-key, mixed>> $attributes
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public string $name,
        public readonly int $kind,
        public readonly int $startNs,
        public array $attributes = [],
    ) {}

    /** @param string|int|float|bool|array<array-key, mixed> $value */
    public function set(string $key, string|int|float|bool|array $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /** Marks the span failed, with a short reason -- a class or a code, never a message. */
    public function fail(string $reason): self
    {
        $this->status = self::STATUS_ERROR;
        $this->statusMessage = $reason;

        return $this;
    }
}
