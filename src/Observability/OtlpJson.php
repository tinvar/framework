<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Spans as an OTLP/JSON `ExportTraceServiceRequest`: what an OpenTelemetry
 * collector takes on POST /v1/traces, and what its `otlpjsonfile` receiver
 * reads, a line at a time, from a file.
 *
 * OTLP's JSON mapping, followed where it differs from plain JSON: trace and
 * span ids as hex, 64-bit integers -- times among them -- as decimal
 * strings, enums as their numbers.
 */
final class OtlpJson
{
    /**
     * @param list<Span> $spans
     * @param array<string, string> $resource the service these spans are from: `service.name` at least
     */
    public static function traces(array $spans, array $resource): string
    {
        return json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => self::attributes($resource)],
                'scopeSpans' => [[
                    'scope' => ['name' => 'tinvar'],
                    'spans' => array_map(self::span(...), $spans),
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function span(Span $span): array
    {
        $encoded = [
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'name' => $span->name,
            'kind' => $span->kind,
            'startTimeUnixNano' => (string) $span->startNs,
            'endTimeUnixNano' => (string) ($span->endNs ?? $span->startNs),
            'attributes' => self::attributes($span->attributes),
        ];
        if ($span->parentSpanId !== null) {
            $encoded['parentSpanId'] = $span->parentSpanId;
        }
        if ($span->status !== Span::STATUS_UNSET) {
            $encoded['status'] = ['code' => $span->status, 'message' => $span->statusMessage];
        }

        return $encoded;
    }

    /**
     * @param array<string, string|int|float|bool|list<string>> $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private static function attributes(array $attributes): array
    {
        $encoded = [];
        foreach ($attributes as $key => $value) {
            $encoded[] = ['key' => (string) $key, 'value' => self::value($value)];
        }

        return $encoded;
    }

    /**
     * @param string|int|float|bool|list<string> $value
     * @return array<string, mixed>
     */
    private static function value(string|int|float|bool|array $value): array
    {
        return match (true) {
            \is_bool($value) => ['boolValue' => $value],
            \is_int($value) => ['intValue' => (string) $value],
            \is_float($value) => is_finite($value) ? ['doubleValue' => $value] : ['stringValue' => (string) $value],
            \is_array($value) => ['arrayValue' => ['values' => array_map(static fn (string $item): array => ['stringValue' => $item], $value)]],
            default => ['stringValue' => $value],
        };
    }
}
