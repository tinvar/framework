<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Jobs\DeclaredBy;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobControl;
use Tinvar\Jobs\Payload;
use Tinvar\Jobs\Refusal;
use Tinvar\Jobs\RunsAsStarter;
use Tinvar\Jobs\UniqueJob;

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
 *
 * Unique as the workflow says: its #[Queue(unique:)], scoped by its own
 * uniqueId() where it implements UniqueJob, and else by its arguments, as
 * any unique job is (ADR 0118) -- so one order's workflow holds no lock
 * another order's waits behind.
 */
final readonly class RunWorkflow implements Job, DeclaredBy, RunsAsStarter, UniqueJob
{
    public function __construct(public Workflow $workflow) {}

    public function declaredBy(): string
    {
        return $this->workflow::class;
    }

    public function uniqueId(): string
    {
        if ($this->workflow instanceof UniqueJob) {
            return $this->workflow->uniqueId();
        }

        // Its arguments, digested: the key is a digest of this in turn, and
        // nothing of a workflow's data is written beside it.
        return hash('sha256', json_encode(Payload::encodeArguments($this->workflow), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function handle(JobControl $job, Workflows $workflows): void
    {
        $workflows->advance($this->workflow, $job);
    }

    /**
     * Its record ended as failed (ADR 0119), and the workflow's own failed()
     * hook, once a step has failed its last try.
     */
    public function failed(\Throwable $error, Workflows $workflows, JobControl $job): void
    {
        $workflows->ended($job, $error);
        $failed = [$this->workflow, 'failed'];
        if (method_exists($this->workflow, 'failed') && \is_callable($failed)) {
            $failed($error);
        }
    }

    /** Its record ended as refused: its tenant, panel, starter or epoch no longer holds. */
    public function refused(Refusal $refusal, Workflows $workflows, JobControl $job): void
    {
        $workflows->ended($job, $refusal);
    }
}
