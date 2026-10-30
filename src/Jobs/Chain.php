<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * Jobs that run one after another, and what runs if one of them fails for
 * good (Dispatcher::chain(), ADR 0100, 0118):
 *
 *     $jobs->chain(Chain::of(new Pack($order), new Label($order), new Ship($order))
 *         ->catch(new FlagForStaff($order)));
 *
 * Each job is queued once the one before it is done; a failure ends the
 * chain there, and its catch runs -- once per failure, sealed now with the
 * chain's tenant, user and epoch and the panel it declares, and checked
 * again when it runs as every job is. A job's error never reaches it: its
 * arguments are what it was dispatched with, and what failed is in the
 * failed jobs.
 *
 * A chain may be one of a batch's jobs (Batch::of()): the batch counts it
 * once, when its last job is done or any of its jobs fails for good.
 */
final readonly class Chain
{
    /**
     * @param non-empty-list<Job> $jobs
     */
    private function __construct(
        public array $jobs,
        public ?Job $catch = null,
    ) {
        if (\count($jobs) > Envelope::MAX_CHAIN) {
            throw new \InvalidArgumentException('A chain holds from one job to ' . Envelope::MAX_CHAIN . '.');
        }
    }

    public static function of(Job $first, Job ...$rest): self
    {
        return new self([$first, ...array_values($rest)]);
    }

    /** Runs if a job of the chain fails for good. One catch a chain. */
    public function catch(Job $job): self
    {
        if ($this->catch !== null) {
            throw new \LogicException('A chain has one catch.');
        }

        return new self($this->jobs, $job);
    }
}
