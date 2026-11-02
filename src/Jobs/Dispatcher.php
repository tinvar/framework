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
 * Chains and batches (ADR 0100, 0118) are dispatched here too, every job of one
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
        // The job running now, if this dispatch is made from one: its
        // starter is the starter of what it dispatches (ADR 0156).
        private ?RunningJobs $running = null,
        // Who is signed in as the user, where the application lets staff
        // act as someone else: their id, or null. Sealed with the job, so
        // its audit entries name them as the request's would (ADR 0156).
        private ?\Closure $impersonator = null,
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
     * run, and the chain's catch, if it has one, does (Chain). Each is
     * sealed now, as dispatch() seals one, and runs on its own #[Queue] --
     * its queue, tries, timeout and user -- checked again on its own when it
     * runs.
     *
     * @param list<Job>|Chain $jobs
     * @return string the first job's id
     */
    public function chain(array|Chain $jobs, int $delay = 0): string
    {
        $this->assertMayDispatch($delay);
        if (\is_array($jobs)) {
            if ($jobs === [] || \count($jobs) > Envelope::MAX_CHAIN) {
                throw new \InvalidArgumentException('A chain holds from one job to ' . Envelope::MAX_CHAIN . '.');
            }
            $jobs = Chain::of(...$jobs);
        }
        $all = self::jobsOf([$jobs]);
        $this->refuseUnique($all, 'a chain');
        $this->coordination('A chain');
        [$user, $epoch] = $this->dispatcher($all);
        $now = $this->now();

        $pending = $this->chained($jobs, $user, $epoch, $now, $delay, null);
        $this->whenCommitted(fn () => $this->queue->push($pending));

        return $pending->id;
    }

    /**
     * Queues a batch's jobs, all at once, counted together; its callbacks
     * run as Batch says. Each job and callback is sealed now with the
     * batch's tenant, user and epoch, and the panel each is for; a chain
     * among its jobs is counted once, at its end.
     *
     * @return string the batch's id, for batchProgress() and addToBatch()
     */
    public function batch(Batch $batch): string
    {
        $this->assertMayDispatch(0);
        $all = [...self::jobsOf($batch->jobs), ...$batch->then, ...$batch->catch, ...$batch->finally];
        $this->refuseUnique($all, 'a batch');
        $coordination = $this->coordination('A batch');
        [$user, $epoch] = $this->dispatcher($all);
        $now = $this->now();
        $id = Uuid::v7();

        $members = $this->members($batch->jobs, $id, $user, $epoch, $now);

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
     * Adds $jobs to a batch still running (ADR 0118): counted with the rest,
     * its then waiting for them too. Named by its id where batchProgress()
     * would read it -- the same tenant, panel and user that dispatched it --
     * or by a job of the batch, with the JobControl its run was given, whose
     * verified payload is what proves it is one.
     *
     * Each job is sealed as dispatch() seals one, for whoever is signed in
     * now. False when the batch is not one this caller may add to, or has
     * finished or been cancelled: a batch counted to its end is not started
     * again. Where the push waits for a transaction to commit, the batch is
     * asked at the commit, and one that ended in between takes nothing.
     */
    public function addToBatch(string|JobControl $batch, Job|Chain ...$jobs): bool
    {
        $this->assertMayDispatch(0);
        if ($jobs === []) {
            throw new \InvalidArgumentException('Add at least one job to a batch.');
        }
        $jobs = array_values($jobs);
        $coordination = $this->coordination('A batch');
        $all = self::jobsOf($jobs);
        $this->refuseUnique($all, 'a batch');

        $id = $batch instanceof JobControl ? $batch->batch() : $batch;
        if ($id === null) {
            return false;
        }
        $record = $coordination->batch($id);
        if ($record === null || !hash_equals($record->tenant, $this->orm->tenant())) {
            return false;
        }
        // By id, only where it was dispatched; by a running job, that job's
        // own batch, whatever panel or user the job runs in.
        if (!$batch instanceof JobControl && !$this->isWhereDispatched($record)) {
            return false;
        }
        if ($record->finishedAt !== null || $record->cancelledAt !== null || \count($jobs) + $record->total > Batch::MAX_JOBS) {
            return false;
        }

        [$user, $epoch] = $this->dispatcher($all);
        $members = $this->members($jobs, $id, $user, $epoch, $this->now());
        $this->whenCommitted(fn () => $coordination->atomically(function () use ($coordination, $id, $members): bool {
            // The cap asked again where the count is written: two adds
            // that each fit the total read above may not both fit it.
            if (!$coordination->addToBatch($id, \count($members), Batch::MAX_JOBS)) {
                return false;
            }
            foreach ($members as $member) {
                $this->queue->push($member);
            }

            return true;
        }));

        return true;
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
        if ($batch === null || !$this->isWhereDispatched($batch)) {
            return null;
        }

        return new BatchProgress($batch->id, $batch->total, $batch->pending, $batch->failed, $batch->cancelledAt !== null, $batch->finishedAt !== null);
    }

    /** Whether $batch was dispatched here: this tenant, this panel, this user -- compared in constant time. */
    private function isWhereDispatched(BatchRecord $batch): bool
    {
        return hash_equals($batch->tenant, $this->orm->tenant())
            && hash_equals($batch->panel, $this->panels?->currentOrNull() ?? '')
            && hash_equals($batch->user, $this->auth->id() ?? '');
    }

    /**
     * A batch's jobs as they are pushed, each sealed with the batch's id: a
     * chain by its first job, carrying the rest.
     *
     * @param list<Job|Chain> $jobs
     * @return list<PendingJob>
     */
    private function members(array $jobs, string $batch, ?string $user, string $epoch, int $now): array
    {
        $members = [];
        foreach ($jobs as $job) {
            if ($job instanceof Chain) {
                $members[] = $this->chained($job, $user, $epoch, $now, 0, $batch);
                continue;
            }
            $envelope = $this->envelope($job, $user, $epoch, $now, batch: $batch);
            $members[] = new PendingJob($envelope->id, QueueOptions::of(self::declarations($job))->name, $envelope->seal($this->signer), $now);
        }

        return $members;
    }

    /** A chain's first job, sealed with the rest of it and its catch, in $batch where it is one's. */
    private function chained(Chain $chain, ?string $user, string $epoch, int $now, int $delay, ?string $batch): PendingJob
    {
        $jobs = $chain->jobs;
        $first = array_shift($jobs);
        $envelope = $this->envelope(
            $first,
            $user,
            $epoch,
            $now,
            batch: $batch,
            chain: array_map($this->link(...), $jobs),
            catch: $chain->catch === null ? null : $this->link($chain->catch),
        );

        return new PendingJob($envelope->id, QueueOptions::of(self::declarations($first))->name, $envelope->seal($this->signer), $now + $delay);
    }

    /**
     * A job as a chain carries it, to be sealed by the worker when its turn
     * comes: its class, its encoded arguments, its declarations and the
     * panel it was dispatched for.
     *
     * @return array{job: class-string<Job>, args: array<string, mixed>, by: class-string|null, panel: string|null}
     */
    private function link(Job $job): array
    {
        return [
            'job' => $job::class,
            'args' => Payload::encodeArguments($job),
            'by' => $job instanceof DeclaredBy ? $job->declaredBy() : null,
            'panel' => $this->panelOf($job),
        ];
    }

    /**
     * Every job in $jobs, each chain's jobs and catch among them.
     *
     * @param list<Job|Chain> $jobs
     * @return list<Job>
     */
    private static function jobsOf(array $jobs): array
    {
        $all = [];
        foreach ($jobs as $job) {
            if ($job instanceof Chain) {
                array_push($all, ...$job->jobs);
                if ($job->catch !== null) {
                    $all[] = $job->catch;
                }
                continue;
            }
            $all[] = $job;
        }

        return $all;
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
     * of them runs as its dispatcher, or runs as its starter (RunsAsStarter)
     * with someone signed in; none otherwise. One sealed in a chain or batch
     * is read only by the jobs that ask for it.
     *
     * @param list<Job> $jobs
     * @return array{string|null, string}
     */
    private function dispatcher(array $jobs): array
    {
        $starter = false;
        foreach ($jobs as $job) {
            if (QueueOptions::of(self::declarations($job))->asUser) {
                $user = $this->auth->id() ?? throw new \LogicException(
                    $job::class . ' runs as the user who dispatched it, and nobody is signed in.',
                );

                return [$user, $this->auth->epoch()];
            }
            $starter = $starter || $job instanceof RunsAsStarter;
        }

        // A job that runs as its starter carries whoever is signed in, and
        // nobody when nobody is.
        $user = $this->auth->id();
        if ($starter && $user !== null) {
            return [$user, $this->auth->epoch()];
        }

        return [null, ''];
    }

    /**
     * @param list<array{job: class-string<Job>, args: array<string, mixed>, by: class-string|null, panel: string|null}> $chain
     * @param array{job: class-string<Job>, args: array<string, mixed>, by: class-string|null, panel: string|null}|null $catch
     */
    private function envelope(Job $job, ?string $user, string $epoch, int $now, ?string $batch = null, array $chain = [], ?string $unique = null, ?array $catch = null): Envelope
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
            catch: $catch,
            // Who started the work, sealed whether or not the job runs as
            // them, for the audit log to name: whoever is signed in, or the
            // starter of the job dispatching this one.
            startedBy: $this->auth->id() ?? $this->running?->current()?->startedBy,
            startedByImpersonator: $this->impersonatorNow(),
        );
    }

    /**
     * Who is signed in as the user dispatching, if anyone is: the
     * application's answer while someone is signed in, else the impersonator
     * the job dispatching this one was started under.
     */
    private function impersonatorNow(): ?string
    {
        if ($this->auth->id() === null) {
            return $this->running?->current()?->startedByImpersonator;
        }
        $impersonator = $this->impersonator === null ? null : ($this->impersonator)();

        return \is_string($impersonator) && $impersonator !== '' ? $impersonator : null;
    }

    /** The job's own #[Panel] or namespace's, or else the panel the dispatcher is in. */
    private function panelOf(Job $job): ?string
    {
        return DeclaredPanel::of(self::declarations($job), $this->registry)->name ?? $this->panels?->currentOrNull();
    }

    /**
     * A unique job's lock key: a digest of its class, the tenant, the panel,
     * the user where it runs as one, and what makes it this job -- its own
     * uniqueId(), or else its arguments (ADR 0118). So no tenant's, panel's
     * or user's job can hold another's lock, and the store keeps no word of
     * what the job is about.
     *
     * By its arguments unless it says otherwise: the same job with the same
     * data is queued once, and the same job for another order or another
     * customer is another job. Keyed by its class alone, as it was, one
     * user's job for their own account held the place of everyone else's
     * for theirs until it ran; a job that is one per class says so with a
     * uniqueId() of ''.
     */
    private function uniqueKey(Job $job, QueueOptions $options, ?string $user): string
    {
        return hash('sha256', json_encode([
            'v' => 2,
            'job' => self::declarations($job),
            'tenant' => $this->orm->tenant(),
            'panel' => $this->panelOf($job),
            'user' => $options->asUser ? $user : null,
            'id' => $job instanceof UniqueJob ? $job->uniqueId() : Payload::encodeArguments($job),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
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
