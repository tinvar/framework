<?php

declare(strict_types=1);

/**
 * Trace spans (ADR 0041): OpenTelemetry's model, exported as OTLP/JSON in
 * batches after the response -- to a file a collector reads, or POSTed to
 * one with credentials that never leave PHP.
 */

namespace Tinvar\Tests\Observability\Tracing;

use Tinvar\Observability\OtlpFileExporter;
use Tinvar\Observability\OtlpHttpExporter;
use Tinvar\Observability\RequestId;
use Tinvar\Observability\Span;
use Tinvar\Observability\SpanExporter;
use Tinvar\Observability\TraceContext;
use Tinvar\Observability\Tracer;

final class Exported implements SpanExporter
{
    /** @var list<list<Span>> */
    public array $batches = [];

    public function export(array $spans): void
    {
        $this->batches[] = $spans;
    }
}

const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

describe('a traceparent', function () {
    it('is read for its trace, its caller and whether it is sampled', function () {
        $context = TraceContext::parse('00-' . TRACE . '-00f067aa0ba902b7-01');

        expect($context)->toEqual(new TraceContext(TRACE, '00f067aa0ba902b7', true))
            ->and(TraceContext::parse('00-' . TRACE . '-00f067aa0ba902b7-00')?->sampled)->toBeFalse()
            // A later version is read for its first four fields.
            ->and(TraceContext::parse('01-' . TRACE . '-00f067aa0ba902b7-01-extra')?->traceId)->toBe(TRACE);
    });

    it('is refused when it is not one', function (?string $header) {
        expect(TraceContext::parse($header))->toBeNull();
    })->with([
        null, '', 'garbage',
        'ff-' . TRACE . '-00f067aa0ba902b7-01',
        '00-' . TRACE . '-00f067aa0ba902b7-01-extra',
        '00-00000000000000000000000000000000-00f067aa0ba902b7-01',
        '00-' . TRACE . '-0000000000000000-01',
        '00-' . TRACE . ' -00f067aa0ba902b7-01',
    ]);
});

describe('the tracer', function () {
    it('keeps a sampled request\'s spans and exports them once the batch is due', function () {
        $exported = new Exported();
        $tracer = new Tracer($exported, exportEvery: 0.0);

        $root = $tracer->startRequest(TRACE, null, 'GET');
        $child = $tracer->start('render document');
        $tracer->end($child);
        $tracer->endRequest();
        $tracer->export();

        expect($exported->batches)->toHaveCount(1)
            ->and(array_map(static fn (Span $s): string => $s->name, $exported->batches[0]))->toBe(['render document', 'GET'])
            ->and($child->traceId)->toBe(TRACE)
            ->and($child->parentSpanId)->toBe($root->spanId)
            ->and($root->parentSpanId)->toBeNull()
            ->and($root->endNs)->toBeGreaterThanOrEqual($child->endNs ?? 0);
    });

    it('continues a trusted caller\'s trace, and follows its sampling decision', function () {
        $exported = new Exported();
        $tracer = new Tracer($exported, sampleRatio: 0.0, exportEvery: 0.0);

        $root = $tracer->startRequest(TRACE, new TraceContext(TRACE, '00f067aa0ba902b7', true), 'GET');
        $tracer->endRequest();
        $tracer->startRequest(RequestId::generate(), new TraceContext(TRACE, '00f067aa0ba902b7', false), 'GET');
        $tracer->endRequest();
        $tracer->export();

        expect($root->parentSpanId)->toBe('00f067aa0ba902b7')
            ->and($exported->batches[0] ?? [])->toHaveCount(1);
    });

    it('keeps nothing of a request it does not sample, or of a span outside any request', function () {
        $exported = new Exported();
        $tracer = new Tracer($exported, sampleRatio: 0.0, exportEvery: 0.0);
        $tracer->startRequest(TRACE, null, 'GET');
        $tracer->end($tracer->start('render document'));
        $tracer->endRequest();
        $tracer->end($tracer->start('a job'));
        $tracer->export(true);

        expect($exported->batches)->toBe([]);
    });

    it('waits for a full batch or the interval, and caps what it holds', function () {
        $exported = new Exported();
        $tracer = new Tracer($exported, batchSize: 3, exportEvery: 3600.0, maxPending: 4);

        $tracer->startRequest(TRACE, null, 'GET');
        $tracer->end($tracer->start('a'));
        $tracer->export();
        expect($exported->batches)->toBe([]);

        foreach (['b', 'c', 'd', 'e', 'f'] as $name) {
            $tracer->end($tracer->start($name));
        }
        $tracer->export();

        expect($exported->batches[0] ?? [])->toHaveCount(4)
            ->and($tracer->dropped)->toBe(2);
    });

    it('redacts names, attributes and status before they leave', function () {
        $exported = new Exported();
        $tracer = new Tracer($exported, exportEvery: 0.0);
        $tracer->startRequest(TRACE, null, 'GET /orders?token=abc123');
        $span = $tracer->start('render', ['url.path' => '/customers/ada@example.com', 'session' => 'raw', 'names' => ['x@y.zz']]);
        $span->fail('failed for ada@example.com');
        $tracer->end($span);
        $tracer->endRequest();
        $tracer->export();

        [$render, $root] = $exported->batches[0];
        expect($root->name)->toBe('GET /orders?token=[redacted]')
            ->and($render->attributes)->toBe(['url.path' => '/customers/[email]', 'session' => '[redacted]', 'names' => ['[email]']])
            ->and($render->statusMessage)->toBe('failed for [email]');
    });

    it('reports an exporter that fails, and drops its batch', function () {
        $failing = new class implements SpanExporter {
            public function export(array $spans): void
            {
                throw new \RuntimeException('collector down');
            }
        };
        $reported = [];
        $tracer = new Tracer($failing, exportEvery: 0.0);
        $tracer->reportTo(static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });
        $tracer->startRequest(TRACE, null, 'GET');
        $tracer->endRequest();
        $tracer->export();

        expect($reported)->toBe(['collector down'])->and($tracer->pending())->toBe(0);
    });
});

describe('OTLP export', function () {
    it('writes one OTLP/JSON request a line, as a collector\'s otlpjsonfile receiver reads', function () {
        $path = sys_get_temp_dir() . '/tinvar-traces-' . bin2hex(random_bytes(4)) . '.jsonl';
        try {
            $tracer = new Tracer(new OtlpFileExporter($path, ['service.name' => 'shop']), exportEvery: 0.0);
            $tracer->startRequest(TRACE, null, 'GET', ['http.request.method' => 'GET']);
            $render = $tracer->start('render document', ['tinvar.render.bytes' => 1024, 'tinvar.render.first_byte_ms' => 1.5, 'tinvar.query.names' => ['availability']]);
            $render->fail('render_failed');
            $tracer->end($render);
            $root = $tracer->root();
            \assert($root instanceof Span);
            $root->set('http.response.status_code', 500);
            $tracer->endRequest();
            $tracer->export();

            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            expect($lines)->toHaveCount(1);
            $request = json_decode($lines[0], true);
            $resource = $request['resourceSpans'][0];
            [$span, $server] = $resource['scopeSpans'][0]['spans'];

            expect($resource['resource']['attributes'])->toBe([['key' => 'service.name', 'value' => ['stringValue' => 'shop']]])
                ->and($resource['scopeSpans'][0]['scope'])->toBe(['name' => 'tinvar'])
                ->and($span['traceId'])->toBe(TRACE)
                ->and($span['parentSpanId'])->toBe($server['spanId'])
                ->and($span['kind'])->toBe(Span::KIND_INTERNAL)
                ->and($server['kind'])->toBe(Span::KIND_SERVER)
                ->and($server)->not->toHaveKey('parentSpanId')
                ->and($span['startTimeUnixNano'])->toBeString()->toMatch('/^\d{19}$/')
                ->and($span['status'])->toBe(['code' => Span::STATUS_ERROR, 'message' => 'render_failed'])
                ->and($span['attributes'])->toBe([
                    ['key' => 'tinvar.render.bytes', 'value' => ['intValue' => '1024']],
                    ['key' => 'tinvar.render.first_byte_ms', 'value' => ['doubleValue' => 1.5]],
                    ['key' => 'tinvar.query.names', 'value' => ['arrayValue' => ['values' => [['stringValue' => 'availability']]]]],
                ]);
        } finally {
            @unlink($path);
        }
    });

    it('POSTs to a collector with its headers, and names it by host alone when it fails', function () {
        $sent = [];
        $status = 503;
        $exporter = new OtlpHttpExporter(
            'https://collector.internal:4318/v1/traces',
            ['Authorization' => 'Bearer otlp-secret-key-0123456789'],
            transport: static function (string $url, array $headers, string $body, float $timeout) use (&$sent, &$status): int {
                $sent[] = [$url, $headers, json_decode($body, true), $timeout];

                return $status;
            },
        );
        $span = new Span(TRACE, '00f067aa0ba902b7', null, 'GET', Span::KIND_SERVER, 1);

        try {
            $exporter->export([$span]);
            $message = null;
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        expect($sent[0][0])->toBe('https://collector.internal:4318/v1/traces')
            ->and($sent[0][1])->toBe(['Authorization' => 'Bearer otlp-secret-key-0123456789', 'Content-Type' => 'application/json'])
            ->and($sent[0][2]['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['traceId'])->toBe(TRACE)
            ->and($message)->toBe('The trace collector at https://collector.internal:4318 answered 503.')
            ->and($message)->not->toContain('otlp-secret');

        // Left alone for the backoff: one down collector costs one attempt.
        $status = 200;
        $exporter->export([$span]);
        expect($sent)->toHaveCount(1);
    });

    it('never chains what a transport said, which can quote the request', function () {
        $exporter = new OtlpHttpExporter('http://127.0.0.1:4318/v1/traces', ['X-Api-Key' => 'k'], transport: static function (): int {
            throw new \RuntimeException('POST failed with X-Api-Key: k');
        });

        try {
            $exporter->export([new Span(TRACE, '00f067aa0ba902b7', null, 'GET', Span::KIND_SERVER, 1)]);
        } catch (\RuntimeException $e) {
            expect($e->getPrevious())->toBeNull()
                ->and($e->getMessage())->toBe('The trace collector at http://127.0.0.1:4318 could not be reached (RuntimeException).');

            return;
        }
        $this->fail('The export should have failed.');
    });

    it('refuses credentials in the URL, a URL that is not http, and a header across lines', function (string $endpoint, array $headers) {
        new OtlpHttpExporter($endpoint, $headers);
    })->with([
        'userinfo' => ['https://user:pass@collector:4318/v1/traces', []],
        'scheme' => ['file:///tmp/traces', []],
        'header value' => ['http://collector:4318/v1/traces', ['X-Key' => "a\r\nX-Other: b"]],
        'header name' => ['http://collector:4318/v1/traces', ['X Key' => 'a']],
    ])->throws(\InvalidArgumentException::class);
});
