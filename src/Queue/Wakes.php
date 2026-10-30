<?php

declare(strict_types=1);

namespace Tinvar\Queue;

/**
 * A queue that can make a waiting job available now: what a workflow's
 * signal does to the workflow waiting on it (ADR 0119), so it runs at once
 * rather than when it next looks.
 *
 * Only a job waiting and held by no worker is woken. One a worker holds is
 * left as it is: the run in hand ends with the job back on its queue for
 * when it said, and a workflow waiting on a signal looks again by then.
 */
interface Wakes
{
    /** Makes job $id available from $now if it is waiting for later and no worker holds it; whether it did. */
    public function wake(string $id, int $now): bool;
}
