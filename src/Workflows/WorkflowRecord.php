<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

/**
 * A workflow's progress, kept beside its sealed payload (ADR 0119): where
 * it was started and by whom, how far it has got, and whether it has
 * ended -- never what its steps returned, which stays in the sealed
 * checkpoint, the workflow's alone to read.
 *
 * Written by the framework as the workflow goes -- at its start, after
 * each step, at its end -- and read through Workflows::progress(), only
 * where it was started. What it says is a report, not the workflow's state:
 * a run cut off between a step's record and this one's write leaves it a
 * step behind until the next run writes it again.
 *
 *   - `status`: queued, running, sleeping, waiting (on a signal), or how it
 *     ended -- finished, failed, refused.
 *   - `step`: the step it last took, or the signal it waits on.
 *   - `steps`: how many of its steps, sleeps and waits have finished.
 *   - `wakeAt`: when it next runs, while it sleeps or waits.
 */
final readonly class WorkflowRecord
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string SLEEPING = 'sleeping';
    public const string WAITING = 'waiting';
    public const string FINISHED = 'finished';
    public const string FAILED = 'failed';
    public const string REFUSED = 'refused';

    /** The statuses a workflow ends in, past which nothing changes. */
    public const array ENDED = [self::FINISHED, self::FAILED, self::REFUSED];

    /**
     * @param class-string<Workflow> $workflow
     * @param string $tenant '' for none
     * @param string $panel '' for none
     * @param string $user who started it; '' for nobody
     */
    public function __construct(
        public string $id,
        public string $workflow,
        public string $tenant,
        public string $panel,
        public string $user,
        public string $status,
        public ?string $step,
        public int $steps,
        public ?int $wakeAt,
        public int $startedAt,
        public int $updatedAt,
        public ?int $finishedAt = null,
    ) {}

    public function ended(): bool
    {
        return \in_array($this->status, self::ENDED, true);
    }
}
