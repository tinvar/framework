<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Orm\Connection;

/**
 * A queue that runs each job as it is pushed, once, in this process: for
 * tests and for local work where no worker runs.
 *
 * The job still goes through the runner -- its payload sealed and verified,
 * its tenant, panel and user entered, the request state reset after it --
 * so a job that passes here passes the checks a worker makes. What it does
 * not do is retry: a failure is thrown to whoever dispatched it, after the
 * commit it waited for.
 */
final readonly class SyncQueue implements Queue
{
    /** @param \Closure(ReservedJob): void $run runs one job; see Worker::process() */
    public function __construct(private \Closure $run) {}

    public function push(PendingJob $job): void
    {
        ($this->run)(new ReservedJob($job->id, $job->queue, $job->payload, 1, 'sync'));
    }

    public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob
    {
        return null;
    }

    public function delete(ReservedJob $job): void {}

    public function release(ReservedJob $job, int $availableAt): void {}

    public function fail(ReservedJob $job, string $error, int $failedAt): void {}

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
