<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * A job that runs on behalf of another class, whose #[Queue] and #[Panel]
 * apply to it: a queued listener's call runs under the listener's queue,
 * tries and panel, not those of the job that carries it.
 */
interface DeclaredBy
{
    /** @return class-string the class whose declarations this job is queued and run under */
    public function declaredBy(): string;
}
