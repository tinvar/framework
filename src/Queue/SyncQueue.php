<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Jobs\JobRunner;
use Tinvar\Orm\Connection;

/**
 * A queue that runs each job as it is pushed, once, in this process: for
 * tests and for local work where no worker runs.
 *
 * The job still goes through the runner -- its payload sealed and verified,
 * its tenant, panel and user entered, and each put back after it -- so a
 * job that passes here passes the checks a worker makes. What it does not
 * do is retry: a failure is thrown to whoever dispatched it, after the
 * commit it waited for.
 *
 * Nor does it run the reset contract, as a worker does after each job: the
 * job runs inside the request that dispatched it, and that request goes on
 * after it. Run through Worker::process(), as this once took, the reset
 * ended the request where it stood, and the rest of it ran as no tenant
 * and nobody.
 */
final readonly class SyncQueue implements Queue
{
    public function __construct(private JobRunner $runner) {}

    public function push(PendingJob $job): void
    {
        $this->runner->run($this->runner->open($job->payload));
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
