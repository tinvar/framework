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
    /**
     * @param list<array<string, mixed>> $meta head tags
     * @param list<string> $tagsRead cache tags the queries in this render
     *        depended on, so a fragment response can declare them and the
     *        client can re-fetch just the boundaries an action invalidated
     * @param float $streamingMs time spent handing output to the sink -- the
     *        browser, or a buffer -- which the deadline leaves out
     */
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
        public array $tagsRead = [],
        public float $streamingMs = 0.0,
    ) {}
}
