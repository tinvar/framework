<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobControl;

/**
 * Writes one export's file, on a worker, as the user who started it.
 *
 * `asUser`: the job carries the user and the auth epoch it was started on,
 * sealed with the tenant and the panel (Jobs\Envelope), and JobRunner runs
 * it only while all of them still hold -- the user there, on that epoch, a
 * member of that panel in that tenant. Tried once: a failure is told to
 * the user on their record, and starting again is theirs to choose.
 *
 * Longer than a stop gives a worker, it pauses between two pages and picks
 * up from the next on the next worker (TransferRunner::export()): a pause
 * is not a try, so its one try is still there.
 */
#[Queue(Transfers::QUEUE, tries: 1, timeout: 600, asUser: true)]
final readonly class RunExport implements Job
{
    /** @param array<string, mixed> $arguments the export's, as the start resolved them */
    public function __construct(
        public string $transfer,
        public array $arguments = [],
    ) {}

    public function handle(TransferRunner $runner, JobControl $job): void
    {
        $runner->export($this->transfer, $this->arguments, $job);
    }
}
