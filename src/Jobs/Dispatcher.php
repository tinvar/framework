<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Queue as QueueOptions;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

/**
 * Puts a job on its queue, sealed with the context it was dispatched in.
 *
 * Three rules from tinvar-spec.md hold here, where a job leaves the code that
 * made it:
 *
 *   - "Jobs run as the dispatching tenant." The tenant in force now is
 *     sealed into the payload (Envelope), and the job runs as it. Dispatch
 *     inside acrossTenants() is refused: no one tenant is dispatching, and
 *     a job that re-entered an unscoped context on a worker would lift
 *     scoping outside the block that opened it.
 *   - "Every non-HTTP context ... has a defined panel, serialized with the
 *     work." The job's own #[Panel] or namespace, or else the panel the
 *     dispatcher is in.
 *   - "save(), job dispatch and event dispatch throw inside" loaders and
 *     queries, "because hovering a link runs them." Refused there.
 *
 * And it waits for the commit. A job dispatched inside transaction() is
 * pushed once the transaction commits, and never if it rolls back -- unless
 * the queue writes on the same connection, where the push is part of the
 * transaction and commits or rolls back with it (Queue::enlistsIn()).
 * Either way a worker never sees a job for writes it cannot see.
 */
final readonly class Dispatcher
{
    /**
     * @param Signer $signer built for Envelope::PURPOSE from the application key
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        private Queue $queue,
        private Orm $orm,
        private Signer $signer,
        private AuthContext $auth,
        private ?PanelContext $panels = null,
        private ?PanelRegistry $registry = null,
        private ?\Closure $clock = null,
    ) {}

    /**
     * Queues $job to run no sooner than $delay seconds from now.
     *
     * @return string the job's id
     */
    public function dispatch(Job $job, int $delay = 0): string
    {
        if ($this->orm->isReadOnly()) {
            throw ReadOnlyViolation::write('Job dispatch', 'a loader or query body');
        }
        if ($this->orm->isUnscoped()) {
            throw new \LogicException(
                'A job cannot be dispatched inside acrossTenants(): it runs as the dispatching tenant, and here there is none. '
                . 'Dispatch it inside actAs() for the tenant it is for.',
            );
        }
        if ($delay < 0) {
            throw new \InvalidArgumentException('A job cannot be delayed by a negative number of seconds.');
        }

        $declaredBy = $job instanceof DeclaredBy ? $job->declaredBy() : null;
        $declarations = $declaredBy ?? $job::class;
        $options = QueueOptions::of($declarations);
        $user = null;
        $epoch = '';
        if ($options->asUser) {
            $user = $this->auth->id() ?? throw new \LogicException(
                $job::class . ' runs as the user who dispatched it, and nobody is signed in.',
            );
            $epoch = $this->auth->epoch();
        }

        $now = $this->now();
        $envelope = new Envelope(
            id: Uuid::v7(),
            class: $job::class,
            arguments: Payload::encodeArguments($job),
            tenant: $this->orm->tenant(),
            panel: DeclaredPanel::of($declarations, $this->registry)->name ?? $this->panels?->currentOrNull(),
            user: $user,
            epoch: $epoch,
            dispatchedAt: $now,
            declaredBy: $declaredBy,
        );
        $pending = new PendingJob($envelope->id, $options->name, $envelope->seal($this->signer), $now + $delay);

        if ($this->queue->enlistsIn($this->orm->statements())) {
            $this->queue->push($pending);
        } else {
            $this->orm->afterCommit(fn () => $this->queue->push($pending));
        }

        return $envelope->id;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
