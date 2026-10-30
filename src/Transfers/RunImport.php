<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobControl;
use Tinvar\Jobs\Refusal;

/**
 * Applies one import's rows, on a worker, as the user who started it, in
 * their tenant and panel -- see RunExport for what `asUser` holds the job
 * to. Tried once: its rows are checked whole before any is written, so a
 * refused file changes nothing, and the user is told on their record.
 *
 * Longer than a stop gives a worker, it pauses between two chunks and
 * picks up on the next worker where its record says (TransferRunner::
 * import()): a pause is not a try, and each row is written once.
 */
#[Queue(Transfers::QUEUE, tries: 1, timeout: 600, asUser: true)]
final readonly class RunImport implements Job
{
    /** @param array<string, mixed> $arguments the import's, as the start resolved them */
    public function __construct(
        public string $transfer,
        public array $arguments = [],
    ) {}

    public function handle(TransferRunner $runner, JobControl $job): void
    {
        $runner->import($this->transfer, $this->arguments, $job);
    }

    /**
     * Refused on the worker -- its user's epoch moved, their membership
     * gone: its record fails now, where its user reads it, and not an hour
     * on (ADR 0107).
     */
    public function refused(TransferRunner $runner, Refusal $refusal, JobControl $job): void
    {
        $runner->refused($this->transfer, $refusal, $job);
    }
}
