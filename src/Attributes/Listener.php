<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A method that handles an event: the event is the type of its one
 * parameter.
 *
 *     final class OrderListeners
 *     {
 *         #[Listener]
 *         public function sendConfirmation(OrderPlaced $event): void { ... }
 *     }
 *
 * Runs when the event is dispatched, in the dispatcher's transaction and
 * context. `afterCommit` waits for the transaction open at dispatch to
 * commit, and is dropped if it rolls back. On a class carrying #[Queue],
 * every listener method runs as a job instead -- after the commit, on a
 * worker, as the dispatching tenant and panel -- and its event must be able
 * to travel in a job's payload (Jobs\Queueable).
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Listener
{
    public function __construct(public bool $afterCommit = false) {}
}
