<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * Thrown inside a job still running as its worker's stop budget runs out:
 * the job did not pause itself when JobControl::stopping() said so, and the
 * worker is about to be killed.
 *
 * Not a failed try. The worker hands the job back to its queue, available
 * at once and its attempt not counted, for the next worker to run again --
 * from its last checkpoint, if it paused before, or from the start.
 */
final class WorkerStopping extends \RuntimeException
{
    public static function in(string $job, int $budget): self
    {
        return new self("{$job} was still running as its worker's {$budget}s stop budget ran out; it is handed back to its queue.");
    }
}
