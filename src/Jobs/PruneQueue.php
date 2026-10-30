<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Schedule;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\Queue;

/**
 * Every night, what `tinvar queue:prune` does (ADR 0100, 0118): forgets the
 * batches that finished a week ago -- their counts and the ends counted --
 * and the unique jobs' locks that have lapsed.
 *
 * A task an application lists among its scheduled tasks, beside the queue
 * it registers in its container as Queue. Nothing else prunes them: a lock
 * lapses on its own but its row stays, and a batch's row stays for
 * batchProgress() to read until this runs. A queue that keeps neither
 * (not Coordination) has nothing to prune, and the task does nothing.
 */
#[Schedule('23 4 * * *')]
final class PruneQueue
{
    /** A week: how long a finished batch's progress can still be read. */
    public const int KEEP = 604_800;

    public function __invoke(Queue $queue): void
    {
        self::prune($queue, time());
    }

    /** Prunes what finished KEEP before $now and what has lapsed by it; answers how many went. */
    public static function prune(Queue $queue, int $now): int
    {
        return $queue instanceof Coordination ? $queue->prune($now, $now - self::KEEP) : 0;
    }
}
