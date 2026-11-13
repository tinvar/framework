<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/**
 * A queue that can forget its failed jobs once they are old (ADR 0221): a
 * failed job keeps its payload -- the job's arguments, in the clear -- and
 * its error, which is there for `queue:retry`, not for good. The nightly
 * queue prune forgets those that failed a month ago.
 */
interface ForgetsFailed
{
    /**
     * Forgets up to $limit jobs that failed before $failedBefore, oldest
     * first: how many it forgot.
     */
    public function forgetFailed(int $failedBefore, int $limit): int;
}
