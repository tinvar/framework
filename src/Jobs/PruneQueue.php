<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Schedule;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\ForgetsFailed;
use Tinvar\Queue\Queue;

/**
 * Every night, what `tinvar queue:prune` does (ADR 0100, 0118, 0221):
 * forgets the batches that finished a week ago -- their counts and the ends
 * counted -- the unique jobs' locks that have lapsed, and the jobs that
 * failed a month ago.
 *
 * A task an application lists among its scheduled tasks, beside the queue
 * it registers in its container as Queue. Nothing else prunes them: a lock
 * lapses on its own but its row stays, a batch's row stays for
 * batchProgress() to read until this runs, and a failed job -- its payload,
 * the job's arguments in the clear, and its error -- stays for
 * `queue:retry` until this runs. A queue that keeps none of them (not
 * Coordination, not ForgetsFailed) has nothing to prune, and the task does
 * nothing.
 */
#[Schedule('23 4 * * *')]
final class PruneQueue
{
    /** A week: how long a finished batch's progress can still be read. */
    public const int KEEP = 604_800;

    /** Thirty days: how long a failed job can still be retried. */
    public const int KEEP_FAILED = 2_592_000;

    /** Failed jobs forgotten a batch, and the most batches a night. */
    private const int FAILED_BATCH = 1_000;

    private const int FAILED_BATCHES = 50;

    public function __invoke(Queue $queue): void
    {
        self::prune($queue, time());
    }

    /**
     * Prunes what finished $keep seconds before $now, what failed
     * $keepFailed before it, and what has lapsed by it; answers how many went.
     */
    public static function prune(Queue $queue, int $now, int $keep = self::KEEP, int $keepFailed = self::KEEP_FAILED): int
    {
        $pruned = $queue instanceof Coordination ? $queue->prune($now, $now - $keep) : 0;
        if ($queue instanceof ForgetsFailed) {
            for ($batch = 0; $batch < self::FAILED_BATCHES; $batch++) {
                $forgot = $queue->forgetFailed($now - $keepFailed, self::FAILED_BATCH);
                $pruned += $forgot;
                if ($forgot < self::FAILED_BATCH) {
                    break;
                }
            }
        }

        return $pruned;
    }
}
