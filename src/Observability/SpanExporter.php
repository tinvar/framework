<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Where finished spans go: called after the response has gone, with a
 * batch. May throw; the Tracer reports it and drops the batch.
 */
interface SpanExporter
{
    /** @param list<Span> $spans */
    public function export(array $spans): void;
}
