<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Attributes\Queue as QueueOptions;
use Tinvar\Http\RequestScope;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\JobRunner;
use Tinvar\Jobs\JobTimedOut;

/**
 * `tinvar queue:work`: takes jobs off the queue and runs them, one at a time,
 * until told to stop.
 *
 * Each job is a boundary, as each request is on the HTTP worker: the reset
 * contract (RequestScope) runs after every one -- rolling back a transaction
 * left open, clearing the identity map, the user, the panel and the
 * container's request-lived services -- before the outcome is recorded, so
 * nothing one job did reaches the next, and a release or a failure is never
 * written inside a transaction the job left behind.
 *
 * Outcomes, per the job's #[Queue]:
 *
 *   - returned: deleted.
 *   - threw, with tries left: released with the backoff for that attempt.
 *   - threw on its last try: failed, kept with the error, and its failed()
 *     hook run.
 *   - refused (JobRefused): failed at once, never retried.
 *   - ran past its timeout: a failed try. SIGALRM throws JobTimedOut
 *     inside the job; one that swallows it and is still running after a
 *     grace period is past saving in-process, and the worker exits
 *     (EXIT_TIMED_OUT) for its supervisor to start a fresh one. The job's
 *     reservation lapses and it is tried again, its attempt counted.
 *
 * A job runs only while its reservation covers the longest it can run. It
 * is reserved for the worker's timeout, then -- once its own is known --
 * held for the longer of the two plus RESERVATION_MARGIN, which outlasts
 * the grace: so no other worker can take it while it runs, whatever its
 * #[Queue(timeout:)]. Held once, up front, rather than by a heartbeat: a
 * heartbeat from inside this process would be a write on the job's own
 * connection, from a signal handler, inside whatever transaction the job
 * has open -- and rolled back with it. The timeout is what keeps the job
 * within its reservation, which is why the worker refuses to start
 * without pcntl to enforce it. Every outcome is recorded only by the
 * holder of the reservation's token; a worker that has lost it records
 * nothing ('lost').
 *
 * SIGTERM, SIGINT and SIGQUIT stop it once the job in hand is done.
 */
final class Worker
{
    /** The status a worker exits with when a job would not stop at its timeout. */
    public const int EXIT_TIMED_OUT = 75;

    /** Seconds a job may run past its timeout after JobTimedOut before the worker gives up on it. */
    public const int GRACE_SECONDS = 5;

    /**
     * Seconds a reservation outlasts a job's timeout, so a slow-but-alive
     * try is not taken twice. More than the grace, so a try that runs to
     * the very end of it is still held.
     */
    public const int RESERVATION_MARGIN = 30;

    /** The worker's own timeout, which a job is first reserved for; see run(). */
    private int $reservedFor = 60;

    private bool $stopping = false;

    /** The job being run, while it runs; what SIGALRM is about. */
    private ?string $running = null;

    private int $runningTimeout = 0;

    private bool $timedOut = false;

    /**
     * @param (\Closure(string): void)|null $log a line per job and per stop
     * @param (\Closure(\Throwable): void)|null $report a job's failure, for the application's log
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param (\Closure(int): void)|null $exit ends the process; exit() unless given
     * @param int $graceSeconds how long a job may run on after JobTimedOut before the worker exits
     */
    public function __construct(
        private readonly Queue $queue,
        private readonly JobRunner $runner,
        private readonly RequestScope $scope,
        private readonly ?\Closure $log = null,
        private readonly ?\Closure $report = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $exit = null,
        private readonly int $graceSeconds = self::GRACE_SECONDS,
    ) {
        if ($graceSeconds < 1 || $graceSeconds >= self::RESERVATION_MARGIN) {
            throw new \InvalidArgumentException(
                'The grace after a timeout must be at least a second and shorter than the reservation margin, or a job could still run once another worker has taken it.',
            );
        }
    }

    /**
     * Works until stopped, or until an option says to stop.
     *
     * @return int how many jobs it ran
     */
    public function run(WorkerOptions $options): int
    {
        // Without an alarm nothing bounds how long a job runs, so nothing
        // keeps it inside its reservation: another worker could take it and
        // run it again while it is still going.
        if (!\function_exists('pcntl_alarm')) {
            throw new \LogicException('A queue worker needs the pcntl extension: it is what stops a job at its timeout, inside the reservation that keeps other workers off it.');
        }

        $this->stopping = false;
        $this->reservedFor = $options->timeout;
        $started = $this->now();
        $ran = 0;
        if ($options->signals) {
            $this->trapSignals();
        }

        // A boundary before the first job too: whatever booting the worker
        // left in the context is not the first job's.
        $this->scope->reset();

        while (!$this->stopRequested()) {
            $job = $this->queue->reserve($options->queues, $this->now(), $this->reservedFor + self::RESERVATION_MARGIN);
            if ($job === null) {
                if ($options->stopWhenEmpty) {
                    break;
                }
                $this->idle($options->sleep);

                continue;
            }

            $this->process($job);
            $ran++;

            if ($options->maxJobs > 0 && $ran >= $options->maxJobs) {
                $this->say('stopping: ran the most jobs it was told to');
                break;
            }
            if ($options->maxTime > 0 && $this->now() - $started >= $options->maxTime) {
                $this->say('stopping: ran for as long as it was told to');
                break;
            }
            if ($options->memoryMb > 0 && memory_get_usage(true) > $options->memoryMb * 1024 * 1024) {
                $this->say("stopping: past {$options->memoryMb} MB, for a fresh process to take over");
                break;
            }
        }

        return $ran;
    }

    /** Asks the worker to stop once the job in hand is done. */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Runs one reserved job and records how it went.
     *
     * @return 'done'|'released'|'failed'|'lost'
     */
    public function process(ReservedJob $job): string
    {
        $began = hrtime(true);
        $envelope = null;
        $options = null;
        $error = null;

        try {
            $envelope = $this->runner->open($job->payload);
            $options = $this->runner->options($envelope);
            if ($job->attempts > $options->tries) {
                // Taken more times than it may be tried: a worker died or
                // gave up on it mid-try, and its reservation lapsed.
                throw new JobRefused('attempts', "{$envelope->class} was taken {$job->attempts} times and may be tried {$options->tries}; the last try never finished.");
            }

            // Held for as long as this try can run -- its own timeout, if
            // longer than the one it was reserved for, and the grace after
            // it -- before it starts. Not held any more: another worker took
            // it, and it is that worker's to run.
            $hold = max($this->reservedFor, $options->timeout) + self::RESERVATION_MARGIN;
            if (!$this->queue->extend($job, $this->now() + $hold)) {
                $this->say("{$envelope->class} {$job->id} lost: its reservation was taken before it could start");

                return 'lost';
            }

            $this->arm($envelope->class, $options->timeout);
            try {
                $this->runner->run($envelope);
            } finally {
                $this->disarm();
            }
            // Swallowed inside the job, and then it returned: still a try
            // that ran past its timeout.
            if ($this->timedOut) {
                throw JobTimedOut::after($envelope->class, $options->timeout);
            }
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            $this->disarm();
            $this->timedOut = false;
        }

        // The boundary, before anything is written: a transaction the job
        // left open is rolled back here, so the outcome below is not written
        // inside it -- and lost with it.
        $this->resetScope();

        $outcome = $this->record($job, $envelope, $options, $error);
        $this->say(sprintf(
            '%s %s %s (attempt %d, %.0f ms)%s',
            $envelope->class ?? 'unreadable job',
            $job->id,
            $outcome,
            $job->attempts,
            (hrtime(true) - $began) / 1e6,
            $error === null ? '' : ': ' . $error::class . ': ' . $error->getMessage(),
        ));

        return $outcome;
    }

    /** @return 'done'|'released'|'failed'|'lost' */
    private function record(ReservedJob $job, ?Envelope $envelope, ?QueueOptions $options, ?\Throwable $error): string
    {
        // Each write is the holder's alone, and says whether it was: a
        // worker whose reservation another has taken over records nothing.
        if ($error === null) {
            return $this->queue->delete($job) ? 'done' : 'lost';
        }

        $this->reportFailure($error);

        if (!$error instanceof JobRefused && $options !== null && $job->attempts < $options->tries) {
            return $this->queue->release($job, $this->now() + $options->backoffAfter($job->attempts)) ? 'released' : 'lost';
        }

        if (!$this->queue->fail($job, self::describe($error), $this->now())) {
            return 'lost';
        }

        // The job's own last word, in its context -- not for a refusal,
        // whose context is exactly what no longer checks out.
        if ($envelope !== null && !$error instanceof JobRefused) {
            try {
                $this->runner->failed($envelope, $error);
            } catch (\Throwable $e) {
                $this->reportFailure($e);
            } finally {
                $this->resetScope();
            }
        }

        return 'failed';
    }

    private function resetScope(): void
    {
        try {
            $this->scope->reset();
        } catch (\Throwable $e) {
            // A reset that fails leaves exactly the state it was meant to
            // clear, and the next job would inherit it. Stop here, as the
            // HTTP worker does, for a fresh process to start clean.
            $this->reportFailure($e);
            $this->say('stopping: the reset after a job failed, so the next job cannot start clean');
            ($this->exit ?? static fn (int $status) => exit($status))(1);
        }
    }

    /** What a failed job is kept with: the error, its class, and where it was thrown. */
    private static function describe(\Throwable $e): string
    {
        $text = $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $text .= "\nCaused by " . $previous::class . ': ' . $previous->getMessage();
        }

        return mb_strcut($text, 0, 16_000);
    }

    private function arm(string $class, int $timeout): void
    {
        $this->running = $class;
        $this->runningTimeout = $timeout;
        $this->timedOut = false;
        pcntl_alarm($timeout);
    }

    private function disarm(): void
    {
        $this->running = null;
        pcntl_alarm(0);
    }

    /** SIGALRM: the job in hand has run past its timeout. */
    public function onAlarm(): void
    {
        if ($this->running === null) {
            return;
        }

        if ($this->timedOut) {
            // It caught JobTimedOut and carried on, and the grace is over.
            // Nothing in this process can stop it now; a fresh one can.
            $this->say("{$this->running} would not stop at its timeout; exiting for a fresh worker");
            ($this->exit ?? static fn (int $status) => exit($status))(self::EXIT_TIMED_OUT);

            return;
        }

        $this->timedOut = true;
        pcntl_alarm($this->graceSeconds);

        throw JobTimedOut::after($this->running, $this->runningTimeout);
    }

    private function trapSignals(): void
    {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
        pcntl_signal(SIGALRM, function (): void {
            $this->onAlarm();
        });
    }

    /**
     * Whether a stop has been asked for. A signal sets it at any moment, so
     * two calls in a row can answer differently.
     *
     * @phpstan-impure
     */
    private function stopRequested(): bool
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->stopping;
    }

    /** Waits for work, a tenth of a second at a time so a stop is not kept waiting. */
    private function idle(int $seconds): void
    {
        $until = microtime(true) + max(0, $seconds);
        while (microtime(true) < $until && !$this->stopRequested()) {
            usleep(100_000);
        }
    }

    private function reportFailure(\Throwable $e): void
    {
        if ($this->report !== null) {
            ($this->report)($e);
        }
    }

    private function say(string $line): void
    {
        if ($this->log !== null) {
            ($this->log)($line);
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
