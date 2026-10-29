<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * Jobs dispatched together, and what runs when they are all done
 * (Dispatcher::batch(), ADR 0100):
 *
 *     $id = $jobs->batch(
 *         Batch::of(...array_map(fn (Order $o) => new SendReceipt($o), $orders))
 *             ->then(new ReceiptsSent($run))
 *             ->catch(new ReceiptsFailed($run))
 *             ->finally(new CloseRun($run)),
 *     );
 *
 * `then` runs once every job is done and none failed; `catch` once, at the
 * first that fails for good; `finally` once every job has ended, however.
 * A failure cancels the batch unless it allowFailures(): its jobs still
 * waiting are counted as ended, not run. Each callback is a job of its own,
 * sealed at dispatch with the batch's tenant, panel, user and epoch, and
 * checked again when it runs as every job is.
 */
final readonly class Batch
{
    /** The most jobs one batch holds. */
    public const int MAX_JOBS = 1_000;

    /**
     * @param list<Job> $jobs
     * @param list<Job> $then
     * @param list<Job> $catch
     * @param list<Job> $finally
     */
    private function __construct(
        public array $jobs,
        public array $then = [],
        public array $catch = [],
        public array $finally = [],
        public bool $allowFailures = false,
    ) {
        if ($jobs === []) {
            throw new \InvalidArgumentException('A batch holds at least one job.');
        }
        if (\count($jobs) > self::MAX_JOBS) {
            throw new \InvalidArgumentException('A batch holds at most ' . self::MAX_JOBS . ' jobs; dispatch more as more batches.');
        }
    }

    public static function of(Job ...$jobs): self
    {
        return new self(array_values($jobs));
    }

    /** Runs once every job is done, none having failed. */
    public function then(Job $job): self
    {
        return new self($this->jobs, [...$this->then, $job], $this->catch, $this->finally, $this->allowFailures);
    }

    /** Runs once, at the first job that fails for good. */
    public function catch(Job $job): self
    {
        return new self($this->jobs, $this->then, [...$this->catch, $job], $this->finally, $this->allowFailures);
    }

    /** Runs once every job has ended, done or failed. */
    public function finally(Job $job): self
    {
        return new self($this->jobs, $this->then, $this->catch, [...$this->finally, $job], $this->allowFailures);
    }

    /** Lets the rest run on after a failure, rather than cancel them. */
    public function allowFailures(): self
    {
        return new self($this->jobs, $this->then, $this->catch, $this->finally, true);
    }
}
