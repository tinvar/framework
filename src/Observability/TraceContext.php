<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * A W3C `traceparent` a proxy in front of the application sent: the trace
 * this request belongs to, the span that called it, and whether that trace
 * is being sampled.
 *
 * Read only where the application says the header can be believed
 * (Telemetry's `trustIncoming`). A browser can send one too, and believed
 * from anyone it would let a client choose the request id every log line is
 * filed under -- another visitor's, say, to bury a request among theirs.
 */
final readonly class TraceContext
{
    public function __construct(
        public string $traceId,
        public string $parentSpanId,
        public bool $sampled,
    ) {}

    /**
     * The header's context, or null for anything that is not one.
     *
     * Version 00 exactly as the recommendation spells it; a later version
     * is read for its first four fields, as the recommendation asks, and
     * `ff` is never valid. An all-zero trace or span id is refused.
     */
    public static function parse(?string $header): ?self
    {
        if ($header === null || \strlen($header) > 512) {
            return null;
        }

        $header = strtolower(trim($header));
        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(-[^\s]*)?\z/', $header, $m) !== 1) {
            return null;
        }
        [, $version, $traceId, $spanId, $flags] = $m;
        $rest = $m[5] ?? '';

        if ($version === 'ff' || ($version === '00' && $rest !== '')) {
            return null;
        }
        if (!RequestId::isValid($traceId) || $spanId === '0000000000000000') {
            return null;
        }

        return new self($traceId, $spanId, (hexdec($flags) & 1) === 1);
    }
}
