<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/** A job on its way onto a queue: its id, its queue, its sealed payload and when it may run. */
final readonly class PendingJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public int $availableAt,
    ) {}
}
