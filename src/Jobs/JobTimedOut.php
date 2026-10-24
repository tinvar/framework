<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/** A try that ran past its job's #[Queue(timeout:)]. Counts as a failed try. */
final class JobTimedOut extends \RuntimeException
{
    public static function after(string $job, int $seconds): self
    {
        return new self("{$job} ran past its {$seconds}s timeout.");
    }
}
