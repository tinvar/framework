<?php

declare(strict_types=1);

namespace Tinvar\Events;

/** One #[Listener] method: where it is, which event it takes, and how it runs. */
final readonly class ListenerDefinition
{
    /**
     * @param class-string $class
     * @param class-string $event
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $event,
        public bool $afterCommit,
        public bool $queued,
    ) {}
}
