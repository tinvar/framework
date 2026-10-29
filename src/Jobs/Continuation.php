<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Queue\BatchRecord;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\Queue;

/**
 * What follows a job's end (ADR 0100): its unique lock given back, the next
 * job of its chain queued, its batch counted and, at the batch's end or
 * first failure, the batch's callbacks queued.
 *
 * Written in the same transaction of the queue's store as the job's own end
 * -- its delete, or its failure -- by whoever records that end (the Worker,
 * SyncQueue), so the two cannot disagree: a worker that has lost its
 * reservation records neither, and one cut off between them records both or
 * neither. Everything it queues is sealed from the envelope the worker
 * opened and verified, or was sealed at dispatch.
 */
final readonly class Continuation
{
    public function __construct(
        private Queue&Coordination $queue,
        private JobRunner $runner,
    ) {}

    /** Whether $envelope has anything to follow its end. */
    public static function follows(Envelope $envelope): bool
    {
        return $envelope->unique !== null || $envelope->chain !== [] || $envelope->batch !== null;
    }

    /**
     * After the job ended well -- or was skipped, its batch cancelled: the
     * lock back, the chain on, the batch counted.
     */
    public function done(Envelope $envelope, int $now): void
    {
        if ($envelope->unique !== null) {
            $this->queue->unlock($envelope->unique, $envelope->id);
        }

        $next = $this->runner->next($envelope, $now);
        if ($next !== null) {
            $this->queue->push($next);
        }

        if ($envelope->batch !== null) {
            $step = $this->queue->batchJobDone($envelope->batch, $envelope->id, $now);
            if ($step !== null && $step->finished) {
                $this->pushAll($step->batch, $step->batch->failed === 0 ? [BatchRecord::THEN, BatchRecord::FINALLY] : [BatchRecord::FINALLY], $now);
            }
        }
    }

    /**
     * After the job failed for good: the lock back, the chain ended, the
     * batch counted -- and cancelled, unless it allows failures.
     */
    public function failed(Envelope $envelope, int $now): void
    {
        if ($envelope->unique !== null) {
            $this->queue->unlock($envelope->unique, $envelope->id);
        }

        if ($envelope->batch !== null) {
            $step = $this->queue->batchJobFailed($envelope->batch, $envelope->id, $now);
            if ($step === null) {
                return;
            }
            $on = [];
            if ($step->firstFailure) {
                $on[] = BatchRecord::CATCH;
            }
            if ($step->finished) {
                $on[] = BatchRecord::FINALLY;
            }
            $this->pushAll($step->batch, $on, $now);
        }
    }

    /** @param list<BatchRecord::THEN|BatchRecord::CATCH|BatchRecord::FINALLY> $on */
    private function pushAll(BatchRecord $batch, array $on, int $now): void
    {
        foreach ($on as $callback) {
            foreach ($batch->callbacksFor($callback, $now) as $job) {
                $this->queue->push($job);
            }
        }
    }
}
