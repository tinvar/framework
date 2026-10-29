<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/**
 * A batch after one of its jobs ended: as it stands, whether that end
 * finished it, and whether it was the batch's first failure. Each is true
 * for exactly one step of a batch, so its callbacks are pushed once.
 */
final readonly class BatchStep
{
    public function __construct(
        public BatchRecord $batch,
        public bool $finished,
        public bool $firstFailure,
    ) {}
}
