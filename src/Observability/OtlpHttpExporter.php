<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Spans POSTed to an OpenTelemetry collector as OTLP/JSON -- OTLP/HTTP's
 * JSON encoding, which every collector and most backends accept.
 *
 * Its headers are what a backend authenticates with, so they live here, in
 * PHP, and nowhere else: never in the renderer's environment, which `tinvar
 * serve` allowlists, never in a log line or an exception message, which
 * name the collector by its host alone. Credentials in the URL itself are
 * refused for the same reason -- a URL ends up quoted.
 *
 * It runs after the response has gone, in batches (Tracer), so a slow
 * collector holds a worker between requests, never a visitor. A collector
 * that fails or answers with an error is left alone for `backoff` seconds,
 * its spans dropped meanwhile, so one that is down costs one timeout, not
 * one per request. Redirects are not followed: the headers would go with
 * them.
 */
final class OtlpHttpExporter implements SpanExporter
{
    private float $backingOffUntil = 0.0;

    /** @var \Closure(string, array<string, string>, string, float): int */
    private readonly \Closure $transport;

    private readonly string $origin;

    /**
     * @param string $endpoint the collector's traces URL, as OTEL_EXPORTER_OTLP_TRACES_ENDPOINT
     *        names one: http://127.0.0.1:4318/v1/traces
     * @param array<string, string> $headers sent with every export: a backend's key
     * @param array<string, string> $resource the service: `service.name` at least
     * @param (\Closure(string, array<string, string>, string, float): int)|null $transport
     *        posts a body and returns the status; for tests
     */
    public function __construct(
        private readonly string $endpoint,
        #[\SensitiveParameter]
        private readonly array $headers = [],
        private readonly array $resource = ['service.name' => 'tinvar'],
        private readonly float $timeout = 2.0,
        private readonly float $backoff = 30.0,
        ?\Closure $transport = null,
    ) {
        $url = parse_url($endpoint);
        if (!\is_array($url) || !\in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true) || ($url['host'] ?? '') === '') {
            throw new \InvalidArgumentException('An OTLP endpoint is an http:// or https:// URL.');
        }
        if (isset($url['user']) || isset($url['pass'])) {
            throw new \InvalidArgumentException('Give an OTLP endpoint its credentials as headers, not in its URL.');
        }
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+\z/', (string) $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \InvalidArgumentException('An OTLP header has a token for a name and a value on one line.');
            }
        }

        $this->origin = strtolower($url['scheme']) . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $this->transport = $transport ?? self::post(...);
    }

    public function export(array $spans): void
    {
        if ($spans === [] || microtime(true) < $this->backingOffUntil) {
            return;
        }

        try {
            $status = ($this->transport)(
                $this->endpoint,
                [...$this->headers, 'Content-Type' => 'application/json'],
                OtlpJson::traces($spans, $this->resource),
                $this->timeout,
            );
        } catch (\Throwable $e) {
            // Not chained: what a transport says about a failed request can
            // quote the request, headers and all.
            $this->backingOffUntil = microtime(true) + $this->backoff;
            throw new \RuntimeException("The trace collector at {$this->origin} could not be reached (" . $e::class . ').');
        }

        if ($status < 200 || $status >= 300) {
            $this->backingOffUntil = microtime(true) + $this->backoff;
            throw new \RuntimeException("The trace collector at {$this->origin} answered {$status}.");
        }
    }

    /** @param array<string, string> $headers */
    private static function post(string $url, array $headers, string $body, float $timeout): int
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('No answer.');
        }

        try {
            $meta = stream_get_meta_data($stream);
            /** @var list<string> $responseHeaders */
            $responseHeaders = $meta['wrapper_data'] ?? [];
        } finally {
            fclose($stream);
        }

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0] ?? '', $m) === 1 ? (int) $m[1] : 0;
    }
}
