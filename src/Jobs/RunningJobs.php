<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * The job running now, if one is: what the audit log reads to say a change
 * was made by a job, and who started it (ADR 0156), and what the dispatcher
 * reads to carry a job's starter on to the jobs it dispatches.
 *
 * Given to the JobRunner, which puts each job's envelope here for as long
 * as it runs -- handle(), failed() and refused() alike -- and takes it away
 * again however the job ends. A stack, since a job run sync inside another
 * is that job's while it runs. Work outside any job reads none.
 */
final class RunningJobs
{
    /** @var list<Envelope> */
    private array $running = [];

    /** The envelope of the job running now, the innermost; null outside every job. */
    public function current(): ?Envelope
    {
        return $this->running === [] ? null : $this->running[array_key_last($this->running)];
    }

    /**
     * Runs $work as $envelope's job.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function within(Envelope $envelope, \Closure $work): mixed
    {
        $this->running[] = $envelope;
        $depth = \count($this->running);
        try {
            return $work();
        } finally {
            array_splice($this->running, $depth - 1);
        }
    }

    /** Forgets every job, for the reset contract: nothing outlives the work it was for. */
    public function reset(): void
    {
        $this->running = [];
    }
}
