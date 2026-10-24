<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Spans appended to a file, one OTLP/JSON request a line, for an
 * OpenTelemetry collector's `otlpjsonfile` receiver to read and send on.
 *
 * The export that needs no network and no credentials in PHP at all: the
 * collector beside the application holds whatever its backend wants, and
 * an append to a local file is as quick as a log line. The same locked,
 * appending writer as the log (StreamSink), so worker threads never
 * interleave half a batch.
 */
final class OtlpFileExporter implements SpanExporter
{
    private readonly StreamSink $sink;

    /** @param array<string, string> $resource the service: `service.name` at least */
    public function __construct(string $path, private readonly array $resource = ['service.name' => 'tinvar'])
    {
        $this->sink = StreamSink::file($path);
    }

    public function export(array $spans): void
    {
        if ($spans !== []) {
            $this->sink->write(OtlpJson::traces($spans, $this->resource));
        }
    }
}
