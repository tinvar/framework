<?php

declare(strict_types=1);

namespace Tinvar\Events;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
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
 *     and not at all if it rolls back -- in the context it was dispatched
 *     in: its tenant, narrowed, and its user, epoch and panel.
 *   - queued (its class has #[Queue]): as a job, through the job dispatcher
 *     -- after the commit, on a worker, as the dispatching tenant and panel.
 *
 * Listeners are built by the resolver each dispatch, as pages and actions
 * are, so one does not outlive the request that used it.
 */
final readonly class EventDispatcher implements Events
{
    /**
     * @param \Closure(class-string): object $resolver
     * @param AuthContext|null $auth the request's, to run an afterCommit listener as the user it was dispatched by
     * @param PanelContext|null $panels the request's, to run an afterCommit listener in the panel it was dispatched in
     */
    public function __construct(
        private EventRegistry $registry,
        private Orm $orm,
        private \Closure $resolver,
        private ?Dispatcher $jobs = null,
        private ?AuthContext $auth = null,
        private ?PanelContext $panels = null,
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
            $listener->afterCommit ? $this->afterCommitAsDispatcher($call) : $call();
        }
    }

    /**
     * $call, run in the context the event was dispatched in: its tenant,
     * user, auth epoch and panel.
     *
     * The commit can come in another context than the dispatch: a
     * transaction opened as one tenant, the event dispatched inside actAs()
     * for another -- forEachTenant() inside one transaction, a reseller's
     * action acting as each customer in turn; or as one user and panel, the
     * event dispatched acting as another. The listener is about the
     * dispatcher's event, as a queued one is, and running it in whichever
     * context the commit happened in would read, write and notify as that.
     *
     * Narrowed to the tenant, never widened: the listener runs inside
     * actAs() for it, with no subtree granted, even where the tenant's name
     * is the one in force at the commit. A transaction opened inside
     * acrossTenants() or withinSubtree() commits there, and a listener run
     * as it stood would read every tenant's rows, or the subtree's.
     * Untouched only where it runs at once -- outside any transaction, in
     * the dispatcher's own context.
     *
     * The user and epoch, and the panel with the membership check that let
     * the work into it, are put back as they were at the dispatch, where the
     * dispatcher was given the contexts to read them from.
     *
     * @param \Closure(): void $call
     */
    private function afterCommitAsDispatcher(\Closure $call): void
    {
        $tenant = $this->orm->tenant();
        $user = $this->auth?->user();
        $epoch = $this->auth?->epoch() ?? '';
        $panel = $this->panels?->currentOrNull();
        $granted = $this->panels?->granted();
        // Inline while afterCommit() is being called: a callback it runs
        // then runs at once, outside any transaction, in the dispatcher's
        // own context.
        $inline = new \ArrayObject(['now' => true]);

        $this->orm->afterCommit(function () use ($inline, $tenant, $user, $epoch, $panel, $granted, $call): void {
            if ($inline['now'] === true) {
                $call();

                return;
            }

            $work = $call;
            if ($this->panels !== null) {
                $work = $this->inPanel($this->panels, $panel, $granted, $work);
            }
            $auth = $this->auth;
            if ($auth !== null) {
                $inner = $work;
                $work = static fn () => $auth->actingAs($user, $inner, $epoch);
            }

            $this->orm->withoutSubtreeGrant(fn () => $this->orm->actAs($tenant, $work));
        });
        $inline['now'] = false;
    }

    /**
     * $work, in the panel the event was dispatched in, with the binding its
     * membership was checked against then -- and never that binding's
     * subtree, nor whatever was granted around the commit.
     *
     * The listener runs with the ORM's subtree grant lifted, and a binding
     * still saying `subtree` would key the query cache's entries and file
     * its tags as the subtree's while the ORM read the tenant alone. One
     * granted around the commit, to another user or for another panel, was
     * never this dispatch's to skip a membership check with.
     *
     * @param \Closure(): void $work
     * @return \Closure(): void
     */
    private function inPanel(PanelContext $panels, ?string $panel, ?PanelBinding $granted, \Closure $work): \Closure
    {
        $granted = $granted === null ? null : new PanelBinding($granted->name, $granted->kinds, $granted->permissions);

        return static function () use ($panels, $panel, $granted, $work): void {
            if ($panel === null) {
                // PanelContext has no scoped "none": a commit in a panel of
                // work dispatched in none can only come from a reset in
                // between, and the listener is not run in a panel it was
                // never let into.
                $current = $panels->currentOrNull();
                if ($current !== null) {
                    throw new \LogicException("An afterCommit listener dispatched in no panel cannot run inside panel '{$current}'.");
                }
                $panels->grantedWithin(null, $work);

                return;
            }

            $panels->within($panel, static fn () => $panels->grantedWithin($granted, $work));
        };
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
