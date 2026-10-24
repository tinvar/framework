<?php

declare(strict_types=1);

namespace Tinvar\Events;

use Tinvar\Attributes\ClassAttributes;
use Tinvar\Attributes\Listener;
use Tinvar\Attributes\Queue;
use Tinvar\Container\Container;
use Tinvar\Jobs\DeclaredBy;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\Queueable;

/**
 * A queued listener's call, as a job: the listener, its method, the event.
 *
 * Queued under the listener class's own #[Queue] -- its queue, tries,
 * backoff and timeout -- which the dispatcher reads from the class (see
 * EventDispatcher), not this one's.
 */
final readonly class CallQueuedListener implements Job, DeclaredBy
{
    /**
     * @param class-string $listener
     */
    public function __construct(
        public string $listener,
        public string $method,
        public Queueable $event,
    ) {}

    public function declaredBy(): string
    {
        return $this->listener;
    }

    public function handle(Container $container): void
    {
        $definition = null;
        foreach (new \ReflectionClass($this->listener)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === $this->method && $method->getAttributes(Listener::class) !== []) {
                $definition = $method;
            }
        }
        // Only a method that still says it listens: the payload names a
        // class and a method, and a deploy since may have renamed either.
        if ($definition === null || ClassAttributes::of($this->listener, Queue::class) === []) {
            throw new JobRefused('listener_missing', "{$this->listener}::{$this->method}() is no longer a queued #[Listener].");
        }

        $definition->invoke($container->get($this->listener), $this->event);
    }
}
