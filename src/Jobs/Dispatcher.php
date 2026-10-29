<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Queue as QueueOptions;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Queue\BatchRecord;
use Tinvar\Queue\Coordination;
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
 *
 * Chains and batches (ADR 0100) are dispatched here too, every job of one
 * sealed now, with the one tenant, user and epoch the chain or batch is
 * dispatched with, and each with the panel it is for: a worker checks each
 * again on its own when it runs, as it checks any job. A unique job's lock
 * is taken with its push, in the same transaction of the queue's store.
 * All three need a queue that keeps them (Coordination); a queue that does
 * not refuses them rather than run them as plain jobs.
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
     * A unique job (#[Queue(unique:)]) whose lock another job holds is not
     * queued: the id returned is the one already queued or running. Where
     * the push waits for a transaction to commit, the lock is taken at the
     * commit, and the id returned is this job's, which may then not be
     * queued.
     *
     * @return string the id of the job that will do the work
     */
    public function dispatch(Job $job, int $delay = 0): string
    {
        $this->assertMayDispatch($delay);
        [$user, $epoch] = $this->dispatcher([$job]);
        $now = $this->now();
        $options = QueueOptions::of(self::declarations($job));

        $unique = $options->unique > 0 ? $this->uniqueKey($job, $options, $user) : null;
        $envelope = $this->envelope($job, $user, $epoch, $now, unique: $unique);
        $pending = new PendingJob($envelope->id, $options->name, $envelope->seal($this->signer), $now + $delay);

        if ($unique === null) {
            $this->whenCommitted(fn () => $this->queue->push($pending));

            return $envelope->id;
        }

        $coordination = $this->coordination('A unique job');
        $holder = $envelope->id;
        $this->whenCommitted(function () use ($coordination, $pending, $unique, $options, $now, &$holder): void {
            $holder = $coordination->atomically(function () use ($coordination, $pending, $unique, $options, $now): string {
                $held = $coordination->lock($unique, $pending->id, $now + $options->unique, $now);
                if ($held !== null) {
                    return $held;
                }
                $this->queue->push($pending);

                return $pending->id;
            });
        });

        return $holder;
    }

    /**
     * Queues $jobs to run one after another: the first no sooner than
     * $delay seconds from now, each of the rest once the one before it is
     * done. One that fails for good ends the chain there; the rest never
     * run. Each is sealed now, as dispatch() seals one, and runs on its own
     * #[Queue] -- its queue, tries, timeout and user -- checked again on
     * its own when it runs.
     *
     * @param list<Job> $jobs
     * @return string the first job's id
     */
    public function chain(array $jobs, int $delay = 0): string
    {
        $this->assertMayDispatch($delay);
        if ($jobs === [] || \count($jobs) > Envelope::MAX_CHAIN) {
            throw new \InvalidArgumentException('A chain holds from one job to ' . Envelope::MAX_CHAIN . '.');
        }
        $this->refuseUnique($jobs, 'a chain');
        $this->coordination('A chain');
        [$user, $epoch] = $this->dispatcher($jobs);
        $now = $this->now();

        $first = array_shift($jobs);
        $links = [];
        foreach ($jobs as $job) {
            $links[] = [
                'job' => $job::class,
                'args' => Payload::encodeArguments($job),
                'by' => $job instanceof DeclaredBy ? $job->declaredBy() : null,
                'panel' => $this->panelOf($job),
            ];
        }

        $envelope = $this->envelope($first, $user, $epoch, $now, chain: $links);
        $pending = new PendingJob($envelope->id, QueueOptions::of(self::declarations($first))->name, $envelope->seal($this->signer), $now + $delay);
        $this->whenCommitted(fn () => $this->queue->push($pending));

        return $envelope->id;
    }

    /**
     * Queues a batch's jobs, all at once, counted together; its callbacks
     * run as Batch says. Each job and callback is sealed now with the
     * batch's tenant, user and epoch, and the panel each is for.
     *
     * @return string the batch's id, for batchProgress()
     */
    public function batch(Batch $batch): string
    {
        $this->assertMayDispatch(0);
        $all = [...$batch->jobs, ...$batch->then, ...$batch->catch, ...$batch->finally];
        $this->refuseUnique($all, 'a batch');
        $coordination = $this->coordination('A batch');
        [$user, $epoch] = $this->dispatcher($all);
        $now = $this->now();
        $id = Uuid::v7();

        $members = [];
        foreach ($batch->jobs as $job) {
            $envelope = $this->envelope($job, $user, $epoch, $now, batch: $id);
            $members[] = new PendingJob($envelope->id, QueueOptions::of(self::declarations($job))->name, $envelope->seal($this->signer), $now);
        }

        $callbacks = [];
        foreach ([BatchRecord::THEN => $batch->then, BatchRecord::CATCH => $batch->catch, BatchRecord::FINALLY => $batch->finally] as $on => $jobs) {
            foreach ($jobs as $job) {
                $envelope = $this->envelope($job, $user, $epoch, $now);
                $callbacks[] = ['on' => $on, 'job' => new PendingJob($envelope->id, QueueOptions::of(self::declarations($job))->name, $envelope->seal($this->signer), $now)];
            }
        }

        $record = new BatchRecord(
            id: $id,
            tenant: $this->orm->tenant(),
            panel: $this->panels?->currentOrNull() ?? '',
            user: $this->auth->id() ?? '',
            total: \count($members),
            pending: \count($members),
            failed: 0,
            allowFailures: $batch->allowFailures,
            createdAt: $now,
            callbacks: $callbacks,
        );

        $this->whenCommitted(fn () => $coordination->atomically(function () use ($coordination, $record, $members): void {
            $coordination->addBatch($record);
            foreach ($members as $member) {
                $this->queue->push($member);
            }
        }));

        return $id;
    }

    /**
     * How far batch $id has got, read only where it was dispatched: in the
     * same tenant and panel, by the same user, or by nobody where nobody
     * dispatched it. Null for any other, as for one that does not exist.
     */
    public function batchProgress(string $id): ?BatchProgress
    {
        if (!$this->queue instanceof Coordination) {
            return null;
        }
        $batch = $this->queue->batch($id);
        if ($batch === null
            || !hash_equals($batch->tenant, $this->orm->tenant())
            || !hash_equals($batch->panel, $this->panels?->currentOrNull() ?? '')
            || !hash_equals($batch->user, $this->auth->id() ?? '')) {
            return null;
        }

        return new BatchProgress($batch->id, $batch->total, $batch->pending, $batch->failed, $batch->cancelledAt !== null, $batch->finishedAt !== null);
    }

    private function assertMayDispatch(int $delay): void
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
    }

    /**
     * The user and epoch sealed with $jobs: the signed-in user's, where any
     * of them runs as its dispatcher; none otherwise. One sealed in a chain
     * or batch is read only by the jobs that ask for it.
     *
     * @param list<Job> $jobs
     * @return array{string|null, string}
     */
    private function dispatcher(array $jobs): array
    {
        foreach ($jobs as $job) {
            if (QueueOptions::of(self::declarations($job))->asUser) {
                $user = $this->auth->id() ?? throw new \LogicException(
                    $job::class . ' runs as the user who dispatched it, and nobody is signed in.',
                );

                return [$user, $this->auth->epoch()];
            }
        }

        return [null, ''];
    }

    /**
     * @param list<array{job: class-string<Job>, args: array<string, mixed>, by: class-string|null, panel: string|null}> $chain
     */
    private function envelope(Job $job, ?string $user, string $epoch, int $now, ?string $batch = null, array $chain = [], ?string $unique = null): Envelope
    {
        return new Envelope(
            id: Uuid::v7(),
            class: $job::class,
            arguments: Payload::encodeArguments($job),
            tenant: $this->orm->tenant(),
            panel: $this->panelOf($job),
            user: $user,
            epoch: $epoch,
            dispatchedAt: $now,
            declaredBy: $job instanceof DeclaredBy ? $job->declaredBy() : null,
            batch: $batch,
            chain: $chain,
            unique: $unique,
        );
    }

    /** The job's own #[Panel] or namespace's, or else the panel the dispatcher is in. */
    private function panelOf(Job $job): ?string
    {
        return DeclaredPanel::of(self::declarations($job), $this->registry)->name ?? $this->panels?->currentOrNull();
    }

    /**
     * A unique job's lock key: a digest of its class, the tenant, the panel,
     * the user where it runs as one, and its own uniqueId(). So no tenant's,
     * panel's or user's job can hold another's lock, and the store keeps no
     * word of what the job is about.
     */
    private function uniqueKey(Job $job, QueueOptions $options, ?string $user): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'job' => self::declarations($job),
            'tenant' => $this->orm->tenant(),
            'panel' => $this->panelOf($job),
            'user' => $options->asUser ? $user : null,
            'id' => $job instanceof UniqueJob ? $job->uniqueId() : '',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A unique job's lock holds a place in the queue, not in a sequence: in
     * a chain or a batch, whose jobs are already one dispatch's, it would
     * hold nothing anyone could see, or leave the rest waiting on a job that
     * was never queued.
     *
     * @param list<Job> $jobs
     */
    private function refuseUnique(array $jobs, string $in): void
    {
        foreach ($jobs as $job) {
            if (QueueOptions::of(self::declarations($job))->unique > 0) {
                throw new \LogicException($job::class . " is unique, and cannot be dispatched in {$in}: dispatch it alone.");
            }
        }
    }

    private function coordination(string $what): Coordination
    {
        if (!$this->queue instanceof Coordination) {
            throw new \LogicException("{$what} needs a queue that keeps locks, batches and windows (Coordination); " . $this->queue::class . ' does not.');
        }

        return $this->queue;
    }

    /** Pushes inside the transaction where the queue enlists in it, or once it commits. */
    private function whenCommitted(\Closure $push): void
    {
        if ($this->queue->enlistsIn($this->orm->statements())) {
            $push();
        } else {
            $this->orm->afterCommit($push);
        }
    }

    /** @return class-string whose #[Queue] and #[Panel] the job is dispatched under */
    private static function declarations(Job $job): string
    {
        return $job instanceof DeclaredBy ? $job->declaredBy() : $job::class;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
