<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Orm\Connection;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\BatchRecord;
use Tinvar\Queue\BatchStep;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Queue\ReservedJob;

/**
 * A queue that runs each job as it is pushed, once, in this process: for
 * tests and for local work where no worker runs.
 *
 * The job still goes through the runner -- its payload sealed and verified,
 * its tenant, panel and user entered, and each put back after it -- so a
 * job that passes here passes the checks a worker makes. What it does not
 * do is retry: a failure is thrown to whoever dispatched it, after the
 * commit it waited for. A job that pauses itself runs again at once.
 *
 * Nor does it run the reset contract, as a worker does after each job: the
 * job runs inside the request that dispatched it, and that request goes on
 * after it. Run through Worker::process(), as this once took, the reset
 * ended the request where it stood, and the rest of it ran as no tenant
 * and nobody.
 *
 * Unique jobs, chains and batches (ADR 0100) run as a worker runs them:
 * its locks, windows and batches kept in this process (an ArrayQueue's),
 * and what follows each job's end (Continuation) run here and now -- the
 * next job of a chain, a batch's callbacks. A job that fails is counted,
 * its batch's catch and finally run, and then thrown to whoever dispatched
 * it, which ends a chain there, and a batch before the rest of its jobs
 * are pushed.
 */
final readonly class SyncQueue implements Queue, Coordination
{
    private ArrayQueue $kept;

    public function __construct(private JobRunner $runner)
    {
        $this->kept = new ArrayQueue();
    }

    /**
     * A job that pauses itself (JobControl::release()) is run again here and
     * now, from its checkpoint, as a worker would run it later: nothing
     * stops a sync job, so it pauses only when it chooses to. Past its
     * #[Queue(releases:)] it is refused, as a worker would fail it.
     */
    public function push(PendingJob $job): void
    {
        $envelope = $this->runner->open($job->payload);
        $continuation = new Continuation($this, $this->runner);

        // A job of a batch an earlier failure cancelled: counted, not run.
        if ($envelope->batch !== null && $this->kept->batch($envelope->batch)?->cancelledAt !== null) {
            $continuation->done($envelope, time(), skipped: true);

            return;
        }

        try {
            for (;;) {
                $control = $this->runner->control($envelope);
                $this->runner->run($envelope, $control);
                $release = $control->released();
                if ($release === null) {
                    break;
                }
                // A wait on a signal (a workflow's waitFor(), ADR 0119):
                // nothing can send one while this process runs the job, so
                // looking again here would spin until the deadline.
                if (!$release['counted']) {
                    throw new \LogicException("{$envelope->class} waits on a signal, which the sync queue, running it here and now, cannot wait for.");
                }
                if ($envelope->releases >= $this->runner->options($envelope)->releases) {
                    throw new JobRefused('releases', "{$envelope->class} paused more times than its #[Queue(releases:)] allows.");
                }
                $envelope = $this->runner->open($this->runner->pause($envelope, $release['checkpoint']));
            }
        } catch (\Throwable $e) {
            if (Continuation::follows($envelope)) {
                $continuation->failed($envelope, time());
            }

            throw $e;
        }

        if (Continuation::follows($envelope)) {
            $continuation->done($envelope, time());
        }
    }

    public function lock(string $key, string $job, int $until, int $now): ?string
    {
        return $this->kept->lock($key, $job, $until, $now);
    }

    public function unlock(string $key, string $job): bool
    {
        return $this->kept->unlock($key, $job);
    }

    public function slot(string $key, int $runs, int $per, int $now, bool $sliding = false): int
    {
        return $this->kept->slot($key, $runs, $per, $now, $sliding);
    }

    public function unslot(string $key, int $per, int $now, bool $sliding = false): void
    {
        $this->kept->unslot($key, $per, $now, $sliding);
    }

    public function addBatch(BatchRecord $batch): void
    {
        $this->kept->addBatch($batch);
    }

    public function batch(string $id): ?BatchRecord
    {
        return $this->kept->batch($id);
    }

    public function addToBatch(string $id, int $jobs): bool
    {
        return $this->kept->addToBatch($id, $jobs);
    }

    public function batchJobDone(string $id, string $job, int $now): ?BatchStep
    {
        return $this->kept->batchJobDone($id, $job, $now);
    }

    public function batchJobFailed(string $id, string $job, int $now): ?BatchStep
    {
        return $this->kept->batchJobFailed($id, $job, $now);
    }

    public function prune(int $now, int $finishedBefore): int
    {
        return $this->kept->prune($now, $finishedBefore);
    }

    public function atomically(\Closure $work): mixed
    {
        return $work();
    }

    public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob
    {
        return null;
    }

    public function extend(ReservedJob $job, int $until): bool
    {
        return true;
    }

    public function delete(ReservedJob $job): bool
    {
        return true;
    }

    public function release(ReservedJob $job, int $availableAt): bool
    {
        return true;
    }

    public function requeue(ReservedJob $job, int $availableAt, ?string $payload = null): bool
    {
        return true;
    }

    public function fail(ReservedJob $job, string $error, int $failedAt): bool
    {
        return true;
    }

    public function enlistsIn(Connection $connection): bool
    {
        return false;
    }

    public function size(string $queue): int
    {
        return 0;
    }

    public function failed(): array
    {
        return [];
    }

    public function retry(string $id, int $now): bool
    {
        return false;
    }
}
