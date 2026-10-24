<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Orm\Connection;

/**
 * Where dispatched jobs wait for a worker.
 *
 * A queue stores sealed payloads (Jobs\Envelope) and the bookkeeping a
 * worker writes around them: when a job is next available, how many times
 * it has been taken, and whether a worker holds it now. It never reads a
 * payload -- what a job is, and as whom it runs, is the runner's to verify.
 *
 * A reservation lasts `reserveFor` seconds. A worker that dies holding a
 * job never releases it, so once the reservation lapses the job is
 * available again, its attempt already counted.
 */
interface Queue
{
    public function push(PendingJob $job): void;

    /**
     * Takes the next available job from the first of $queues that has one,
     * counting the attempt; null when none is available.
     *
     * @param list<string> $queues
     */
    public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob;

    /** Done with: removed. */
    public function delete(ReservedJob $job): void;

    /** Back on its queue, available from $availableAt. */
    public function release(ReservedJob $job, int $availableAt): void;

    /** Off its queue, kept with why it failed. */
    public function fail(ReservedJob $job, string $error, int $failedAt): void;

    /**
     * Whether a push runs on $connection, inside whatever transaction it
     * has open -- so a job pushed there commits, or rolls back, with the
     * writes around it, and needs no deferring to be safe. A queue on
     * another store says no, and the dispatcher waits for the commit.
     */
    public function enlistsIn(Connection $connection): bool;

    /** Jobs waiting or held on $queue. */
    public function size(string $queue): int;

    /** @return list<FailedJob> newest first */
    public function failed(): array;

    /** Puts a failed job back on its queue, available now; false when there is no such failure. */
    public function retry(string $id, int $now): bool;
}
