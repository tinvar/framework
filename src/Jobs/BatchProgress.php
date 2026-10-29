<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * How far a batch has got, as its dispatcher may read it
 * (Dispatcher::batchProgress()): counts and times, nothing of its jobs.
 */
final readonly class BatchProgress
{
    public function __construct(
        public string $id,
        public int $total,
        public int $pending,
        public int $failed,
        public bool $cancelled,
        public bool $finished,
    ) {}

    /** Jobs ended, done or failed, of the total. */
    public function ended(): int
    {
        return $this->total - $this->pending;
    }
}
