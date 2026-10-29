<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Jobs\DeclaredBy;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobControl;
use Tinvar\Jobs\RunsAsStarter;

/**
 * The job a workflow travels in: one run of it, which replays its finished
 * steps and takes the next (Steps).
 *
 * Declared by the workflow (DeclaredBy), so the workflow's own #[Queue] and
 * #[Panel] are the ones it is queued and run under; run as its starter
 * (RunsAsStarter), so the user who started it and their auth epoch are
 * sealed with it and checked before every step, beside the tenant and the
 * panel. A job in every other respect: retried, paused, handed back at a
 * stop and refused as any job is.
 */
final readonly class RunWorkflow implements Job, DeclaredBy, RunsAsStarter
{
    public function __construct(public Workflow $workflow) {}

    public function declaredBy(): string
    {
        return $this->workflow::class;
    }

    public function handle(JobControl $job, Workflows $workflows): void
    {
        $workflows->advance($this->workflow, $job);
    }

    /** The workflow's own failed() hook, once a step has failed its last try. */
    public function failed(\Throwable $error): void
    {
        $failed = [$this->workflow, 'failed'];
        if (method_exists($this->workflow, 'failed') && \is_callable($failed)) {
            $failed($error);
        }
    }
}
