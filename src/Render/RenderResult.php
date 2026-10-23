<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * What one render produced, plus the timings the benchmark reports.
 *
 * Time is split so the renderer hop can be priced on its own: how long PHP
 * waited on the socket versus how long it spent answering queries.
 */
final readonly class RenderResult
{
    /** @param list<array<string, mixed>> $meta head tags */
    public function __construct(
        public array $meta,
        public int $bytes,
        public int $chunks,
        public int $queryCalls,
        public int $queryBatches,
        public float $timeToFirstByteMs,
        public float $totalMs,
        public float $queryMs,
        public float $waitingOnRendererMs,
    ) {}
}
