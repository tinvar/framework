<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Support\Signer;

/**
 * A running job's view of its worker: whether the worker is stopping, where
 * the job last paused, and a way to pause -- to go back on its queue with a
 * checkpoint, and pick up from it on the next run.
 *
 *     public function handle(Orders $orders, JobControl $job): void
 *     {
 *         $after = $job->checkpoint()['after'] ?? null;
 *         foreach ($orders->pagesAfter($after) as $page) {
 *             $after = $this->send($page);           // committed per page
 *             if ($job->stopping()) {
 *                 $job->release(checkpoint: ['after' => $after]);
 *
 *                 return;
 *             }
 *         }
 *     }
 *
 * Asked for by type in handle(), as a service is; built for each run by the
 * worker, never shared between jobs.
 *
 * **A pause is not a try.** The job is put back with its attempt given back,
 * so a job paused at every deploy is still tried as often as #[Queue(tries:)]
 * says. Pauses are counted on their own, in the sealed payload, and a job
 * pausing more than #[Queue(releases:)] times is failed: a job that only
 * ever pauses is a loop.
 *
 * **The checkpoint is sealed.** It travels in the job's payload under the
 * payload's MAC, beside the tenant, panel, user and epoch the job was
 * dispatched with (Envelope), so the job reads back exactly what it wrote,
 * and it runs again as exactly who it ran as. Plain data only -- strings,
 * numbers, booleans, null and arrays of them -- and at most
 * MAX_CHECKPOINT_BYTES of JSON: a place to pick up from, not the work. It is
 * readable by whoever can read the queue, as the job's arguments are, so
 * nothing secret goes in it.
 *
 * **A checkpoint covers what is committed.** Write it after the work it
 * says is done is durable -- the transaction committed, the file stored --
 * or a run that is cut off after the work and before the pause does the
 * work again, and one cut off before the commit skips it. Work that cannot
 * be done twice carries idempotencyKey() to whoever does it.
 */
final class JobControl
{
    /** The largest checkpoint, as JSON. */
    public const int MAX_CHECKPOINT_BYTES = 16_384;

    /** How deep a checkpoint may nest. */
    public const int MAX_DEPTH = 8;

    /** @var array{delay: int, checkpoint: array<array-key, mixed>|null, counted: bool}|null */
    private ?array $release = null;

    /**
     * @param \Closure(): bool $stopping whether the worker has been asked to stop
     * @param Signer $signer the payload's, for idempotency keys
     */
    public function __construct(
        private readonly Envelope $envelope,
        private readonly \Closure $stopping,
        private readonly Signer $signer,
    ) {}

    /**
     * Whether the worker running this job has been asked to stop. A job that
     * can pause looks between units of work and, once this says yes, pauses
     * with release() and returns. One that does not is let run until the
     * worker's stop budget is nearly spent, and then stopped (WorkerStopping)
     * and handed back to its queue from its last checkpoint.
     *
     * @phpstan-impure
     */
    public function stopping(): bool
    {
        return ($this->stopping)();
    }

    /**
     * Where the job last paused, as it gave it to release(); null on a run
     * that is not picking up from a pause.
     *
     * @return array<array-key, mixed>|null
     */
    public function checkpoint(): ?array
    {
        return $this->envelope->checkpoint;
    }

    /**
     * The batch this job counts in, if any: what Dispatcher::addToBatch()
     * takes this control for, to add to the batch the running job is part
     * of, proven by its verified payload.
     */
    public function batch(): ?string
    {
        return $this->envelope->batch;
    }

    /**
     * The job's id: the same on every run of it, and what a workflow's
     * progress and signals are kept under (ADR 0119). It grants nothing.
     */
    public function id(): string
    {
        return $this->envelope->id;
    }

    /** How many times this job has paused before this run. */
    public function releases(): int
    {
        return $this->envelope->releases;
    }

    /**
     * Pauses the job once handle() returns: back on its queue, to run again
     * no sooner than $delay seconds from now, as the same job in the same
     * context, with $checkpoint for checkpoint() to read. Its attempt is
     * given back. A handle() that throws after this has failed, and is not
     * paused.
     *
     * @param array<array-key, mixed>|null $checkpoint
     */
    public function release(int $delay = 0, ?array $checkpoint = null): void
    {
        $this->pauseFor($delay, $checkpoint, true);
    }

    /**
     * A pause that is not counted against #[Queue(releases:)]: a workflow
     * waiting on a signal, looking again until its deadline (ADR 0119).
     * Bounded by that deadline, which its checkpoint seals, rather than by
     * a count -- a wait of a day, looked at every few minutes, would spend
     * any count a loop should be stopped by.
     *
     * @internal Workflows calls it; a job pauses with release()
     * @param array<array-key, mixed> $checkpoint
     */
    public function wait(int $delay, array $checkpoint): void
    {
        $this->pauseFor(max(1, $delay), $checkpoint, false);
    }

    /** @param array<array-key, mixed>|null $checkpoint */
    private function pauseFor(int $delay, ?array $checkpoint, bool $counted): void
    {
        if ($delay < 0) {
            throw new \InvalidArgumentException('A job cannot pause for a negative number of seconds.');
        }
        if ($checkpoint !== null) {
            self::assertData($checkpoint, 0);
            $json = json_encode($checkpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (\strlen($json) > self::MAX_CHECKPOINT_BYTES) {
                throw new \LengthException(\sprintf(
                    'A checkpoint is at most %d bytes of JSON, and this one is %d: keep where to pick up from, not the work.',
                    self::MAX_CHECKPOINT_BYTES,
                    \strlen($json),
                ));
            }
        }

        $this->release = ['delay' => $delay, 'checkpoint' => $checkpoint, 'counted' => $counted];
    }

    /**
     * What release() or wait() asked for, if either was called: `counted`
     * says whether the pause counts against #[Queue(releases:)].
     *
     * @return array{delay: int, checkpoint: array<array-key, mixed>|null, counted: bool}|null
     */
    public function released(): ?array
    {
        return $this->release;
    }

    /**
     * A key that is the same on every run of this job -- each retry, each
     * pause, a `queue:retry` of it once failed -- and on no other job's, for
     * $scope: to hand whoever does work that must not happen twice (a
     * payment provider's Idempotency-Key, a message's Message-ID, a unique
     * column), so a run that is cut off after the work and runs again is
     * recognised as the same.
     *
     * Keyed with the application's key, so it cannot be worked out from a
     * job's id by whoever reads the queue.
     */
    public function idempotencyKey(string $scope = ''): string
    {
        return Signer::encode($this->signer->sign("idempotency\0{$this->envelope->id}\0{$scope}"));
    }

    /**
     * Whether $checkpoint is one release() would take: plain data, nested
     * no deeper than MAX_DEPTH, at most MAX_CHECKPOINT_BYTES of JSON. For
     * a caller that must know before it pauses, rather than fail there.
     *
     * @param array<array-key, mixed> $checkpoint
     */
    public static function fits(array $checkpoint): bool
    {
        try {
            self::assertData($checkpoint, 0);

            return \strlen(json_encode($checkpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) <= self::MAX_CHECKPOINT_BYTES;
        } catch (\InvalidArgumentException|\JsonException) {
            return false;
        }
    }

    private static function assertData(mixed $value, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('A checkpoint nests at most ' . self::MAX_DEPTH . ' deep.');
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertData($item, $depth + 1);
            }

            return;
        }
        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('A checkpoint holds finite numbers only.');
        }
        if ($value !== null && !\is_scalar($value)) {
            throw new \InvalidArgumentException(
                'A checkpoint is plain data -- strings, numbers, booleans, null and arrays of them -- and this holds ' . get_debug_type($value) . '.',
            );
        }
    }
}
