<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Orm\Connection;

/**
 * Where dispatched jobs wait for a worker.
 *
 * A queue stores sealed payloads (a job's envelope) and the bookkeeping a
 * worker writes around them: when a job is next available, how many times
 * it has been taken, and whether a worker holds it now. It never reads a
 * payload -- what a job is, and as whom it runs, is the runner's to verify.
 *
 * A reservation lasts `reserveFor` seconds. A worker that dies holding a
 * job never releases it, so once the reservation lapses the job is
 * available again, its attempt already counted.
 *
 * Every change after a reservation is made by its holder alone: extend,
 * delete, release and fail act only while the job's current token is the
 * one the caller holds, and say whether they did. A worker that ran past
 * its reservation, while another took the job, cannot mark that other
 * worker's run done, failed or due again.
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

    /**
     * Holds the reservation until $until, for a job whose own timeout is
     * longer than the one it was reserved for; false when it is no longer
     * this holder's to extend.
     */
    public function extend(ReservedJob $job, int $until): bool;

    /** Done with: removed. False when the reservation is no longer this holder's. */
    public function delete(ReservedJob $job): bool;

    /** Back on its queue, available from $availableAt. False when the reservation is no longer this holder's. */
    public function release(ReservedJob $job, int $availableAt): bool;

    /** Off its queue, kept with why it failed. False when the reservation is no longer this holder's. */
    public function fail(ReservedJob $job, string $error, int $failedAt): bool;

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
