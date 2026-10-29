<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

/** One command in the outbox, as a reader sees it: the envelope, and where it goes. */
final readonly class OutboxEntry
{
    public function __construct(
        public string $id,
        public string $node,
        public string $sealed,
        public int $createdAt,
    ) {}
}
