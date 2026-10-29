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
     * Takes the lock $key for job $job until $until, if no other job holds
     * it unexpired: null when $job holds it now, or the id of the job that
     * does. A lock always lapses at its $until, so a job that never ends --
     * its worker killed, its record lost -- holds nothing for ever.
     */
    public function lock(string $key, string $job, int $until, int $now): ?string;

    /** Gives back lock $key, if $job still holds it; false when it does not. */
    public function unlock(string $key, string $job): bool;

    /**
     * Takes one of $runs starts allowed on $queue in the window of $per
     * seconds $now falls in, across every worker: false when the window's
     * are spent.
     */
    public function slot(string $queue, int $runs, int $per, int $now): bool;

    /** Gives back a start slot() took, when no job was there to start. */
    public function unslot(string $queue, int $per, int $now): void;

    public function addBatch(BatchRecord $batch): void;

    public function batch(string $id): ?BatchRecord;

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
     * Forgets batches finished before $finishedBefore and locks lapsed by
     * $now: how many rows went.
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
