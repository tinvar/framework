<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/**
 * What a queue keeps beside its jobs for the work between them (ADR 0100):
 * unique jobs' locks, rate-limited queues' windows, and batches' counts.
 *
 * Kept by the queue itself, in its own store, so each is written in the
 * same transaction as the job it is about (atomically()): a job's lock is
 * taken with its push, a batch's count moves with the delete of the job it
 * counts, and the next job of a chain is pushed with the delete of the one
 * before. None of it can say a job ran that did not, or miss one that did.
 *
 * Like the rest of a queue, it stores and counts and never reads a payload:
 * a batch's callbacks are sealed payloads it pushes as they are, verified
 * by the runner when they run.
 */
interface Coordination
{
    /**
     * The longest window a rate may hold over, in seconds -- a day, the
     * longest `per` a job's rate takes:
     * a window or a sliding start begun this long ago has ended, whatever
     * its rate, and prune() forgets it.
     */
    public const int LONGEST_WINDOW = 86_400;

    /**
     * Takes the lock $key for job $job until $until, if no other job holds
     * it unexpired: null when $job holds it now, or the id of the job that
     * does. A lock always lapses at its $until, so a job that never ends --
     * its worker killed, its record lost -- holds nothing for ever.
     */
    public function lock(string $key, string $job, int $until, int $now): ?string;

    /** Gives back lock $key, if $job still holds it; false when it does not. */
    public function unlock(string $key, string $job): bool;

    /**
     * Takes one of $runs starts allowed for $key in $per seconds, across
     * every worker: 0 when it took one, or else how many seconds until one
     * may be free -- at least 1.
     *
     * $key is what the rate counts: a queue's name, or a digest of a queue
     * and a tenant for a rate held per tenant. In fixed windows, the window
     * of $per seconds $now falls in, so up to twice $runs can start across
     * a window's edge; $sliding, the $per seconds before $now, so no $per
     * seconds anywhere hold more than $runs.
     */
    public function slot(string $key, int $runs, int $per, int $now, bool $sliding = false): int;

    /** Gives back a start slot() took at $now, when no job was there to start. */
    public function unslot(string $key, int $per, int $now, bool $sliding = false): void;

    public function addBatch(BatchRecord $batch): void;

    public function batch(string $id): ?BatchRecord;

    /**
     * $jobs more to count in batch $id, pushed in the same transaction:
     * true when it took them, false when the batch is gone, has finished
     * or was cancelled -- a batch counted to its end is not started again
     * -- or would hold more than $most. The cap is held in the same
     * conditional write as the count, so adds racing each other cannot
     * pass it together.
     */
    public function addToBatch(string $id, int $jobs, int $most): bool;

    /**
     * The batch's job $job is done -- or skipped, the batch being cancelled:
     * one fewer pending. Counted once per job, whatever runs it again -- a
     * `queue:retry` of it once failed, a copy of its row -- so the batch
     * neither finishes early nor finishes twice.
     */
    public function batchJobDone(string $id, string $job, int $now): ?BatchStep;

    /**
     * The batch's job $job has failed for good: one fewer pending, one more
     * failed, the batch cancelled unless it allows failures. Once per job,
     * as batchJobDone() is.
     */
    public function batchJobFailed(string $id, string $job, int $now): ?BatchStep;

    /**
     * Forgets batches finished before $finishedBefore -- with the ends
     * counted for them -- locks lapsed by $now, and rate windows and
     * sliding starts that began LONGEST_WINDOW or more before $now, which
     * have ended whatever their rate: how many rows went.
     */
    public function prune(int $now, int $finishedBefore): int;

    /**
     * Runs $work in one transaction of the queue's store, so everything it
     * writes there is written together or not at all.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function atomically(\Closure $work): mixed;
}
