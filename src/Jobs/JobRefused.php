<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * A job that must not run, now or on any retry: its payload does not verify,
 * names a class that is not a job, carries a tenant, panel or user that no
 * longer checks out, or a model that is gone.
 *
 * Failed at once rather than retried. Nothing about trying again changes a
 * forged signature or a revoked membership, and retrying would only run the
 * checks that refused it again, with backoff, for no outcome.
 */
final class JobRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
