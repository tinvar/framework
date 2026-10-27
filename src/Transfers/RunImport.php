<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;

/**
 * Applies one import's rows, on a worker, as the user who started it, in
 * their tenant and panel -- see RunExport for what `asUser` holds the job
 * to. Tried once: its rows are applied in one transaction, so a failure
 * changed nothing, and the user is told on their record.
 */
#[Queue(Transfers::QUEUE, tries: 1, timeout: 600, asUser: true)]
final readonly class RunImport implements Job
{
    /** @param array<string, mixed> $arguments the import's, as the start resolved them */
    public function __construct(
        public string $transfer,
        public array $arguments = [],
    ) {}

    public function handle(TransferRunner $runner): void
    {
        $runner->import($this->transfer, $this->arguments);
    }
}
