<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/** A job taken off its queue for good, and why. */
final readonly class FailedJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public string $error,
        public int $failedAt,
    ) {}
}
