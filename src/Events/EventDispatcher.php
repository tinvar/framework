<?php

declare(strict_types=1);

namespace Tinvar\Events;

use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Queueable;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;

/**
 * Hands an event to the #[Listener] methods registered for it.
 *
 * "save(), job dispatch and event dispatch throw inside" loaders and query
 * bodies, "because hovering a link runs them" -- refused there, before any
 * listener runs, since a listener is exactly where a write would be.
 *
 * How each listener runs is its own declaration:
 *
 *   - plain: now, in the dispatcher's transaction and context. A throw
 *     propagates to the dispatcher, as a throw from the code it replaced
 *     would.
 *   - `afterCommit`: once the transaction open now commits (Orm::afterCommit),
 *     and not at all if it rolls back.
 *   - queued (its class has #[Queue]): as a job, through the job dispatcher
 *     -- after the commit, on a worker, as the dispatching tenant and panel.
 *
 * Listeners are built by the resolver each dispatch, as pages and actions
 * are, so one does not outlive the request that used it.
 */
final readonly class EventDispatcher implements Events
{
    /** @param \Closure(class-string): object $resolver */
    public function __construct(
        private EventRegistry $registry,
        private Orm $orm,
        private \Closure $resolver,
        private ?Dispatcher $jobs = null,
    ) {}

    public function dispatch(object $event): void
    {
        if ($this->orm->isReadOnly()) {
            throw ReadOnlyViolation::write('Event dispatch', 'a loader or query body');
        }

        foreach ($this->registry->listenersFor($event) as $listener) {
            if ($listener->queued) {
                if ($this->jobs === null) {
                    throw new \LogicException("{$listener->class} is a queued listener, and the event dispatcher was given no job dispatcher.");
                }
                \assert($event instanceof Queueable);
                $this->jobs->dispatch(new CallQueuedListener($listener->class, $listener->method, $event));

                continue;
            }

            $call = fn () => $this->call($listener, $event);
            $listener->afterCommit ? $this->orm->afterCommit($call) : $call();
        }
    }

    private function call(ListenerDefinition $listener, object $event): void
    {
        $instance = ($this->resolver)($listener->class);
        $method = [$instance, $listener->method];
        if (!\is_callable($method)) {
            throw new \LogicException("{$listener->class}::{$listener->method}() is not callable.");
        }

        $method($event);
    }
}
