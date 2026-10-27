<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Queue as QueueOptions;
use Tinvar\Http\RequestScope;
use Tinvar\Queue\Queue;
use Tinvar\Queue\ReservedJob;

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
 *   - paused itself (JobControl::release()): back on its queue, sealed
 *     again with its checkpoint, its attempt given back -- 'paused'. Past
 *     its #[Queue(releases:)], failed.
 *   - stopped by the worker's stop (WorkerStopping), or anything it threw
 *     once stopped: back on its queue, available at once, its attempt
 *     given back -- 'handed back'.
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
 * SIGTERM, SIGINT and SIGQUIT stop it once the job in hand is done, within
 * its stop budget (StopBudget). The job is told at once, through
 * JobControl::stopping(), and may pause at its next checkpoint. One still
 * running as the budget runs out is stopped (WorkerStopping) and handed
 * back to its queue; one that will not stop even then is handed back from
 * the signal handler, its transaction rolled back, and the worker exits.
 * So a job longer than the stop runs again at once on the next worker,
 * from where it paused, rather than dying with this one and waiting out
 * its reservation.
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

    /** Seconds from SIGTERM until whatever started this worker kills it; see StopBudget. */
    private int $stopBudget = StopBudget::DEFAULT;

    /** When the stop was asked for, by hrtime(), or null before it was. */
    private ?int $stopAskedAt = null;

    /** The reservation of the job being run, while it runs: what a hand-back gives back. */
    private ?ReservedJob $holding = null;

    /** What the alarm armed now is for: the job's timeout, the stop's interrupt, or the hand-back. */
    private string $alarmFor = 'timeout';

    /** Whether the job in hand was stopped by the stop (WorkerStopping). */
    private bool $interrupted = false;

    /**
     * @param (\Closure(string): void)|null $log a line per job and per stop
     * @param (\Closure(\Throwable): void)|null $report a job's failure, for the application's log
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param (\Closure(int): void)|null $exit ends the process; exit() unless given
     * @param int $graceSeconds how long a job may run on after JobTimedOut before the worker exits
     * @param (\Closure(): int)|null $memory bytes the process holds; memory_get_usage(true) unless given
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
        private readonly ?\Closure $memory = null,
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

        // The interrupt comes with the grace and the margin still to run:
        // a budget too short for both would hand a job back after the kill.
        if ($options->stopBudget < $this->graceSeconds + StopBudget::STOP_MARGIN + 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A stop budget of %ds leaves no room for the %ds grace and the %ds margin before the kill.',
                $options->stopBudget,
                $this->graceSeconds,
                StopBudget::STOP_MARGIN,
            ));
        }

        $this->stopping = false;
        $this->stopAskedAt = null;
        $this->stopBudget = $options->stopBudget;
        $this->reservedFor = $options->timeout;
        $started = $this->now();
        $ran = 0;
        if ($options->signals) {
            $this->trapSignals();
        }

        // A boundary before the first job too: whatever booting the worker
        // left in the context is not the first job's.
        $this->scope->reset();

        // The memory limit is there to hand a process that has grown to a
        // fresh one. Counted from nothing, a worker that starts past it --
        // an application that boots big, or a worker run inside a process
        // that already holds more, as a test suite's does -- stopped after
        // every job, and its supervisor gave up restarting it. Measured,
        // the worker itself holds nothing from one job to the next: 600 of
        // the shop's emails, each rendered through the renderer, left it
        // within a kilobyte of where it began. So one that starts past its
        // limit is held to growing by that much from where it started.
        $limit = $options->memoryMb * 1024 * 1024;
        $ceiling = $limit;
        if ($limit > 0) {
            $start = $this->memory();
            if ($start >= $limit) {
                $ceiling = $start + $limit;
                $this->say(\sprintf(
                    'started at %d MB, past its %d MB limit: it stops once it has grown by %d MB',
                    intdiv($start, 1024 * 1024),
                    $options->memoryMb,
                    $options->memoryMb,
                ));
            }
        }

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
            if ($limit > 0 && $this->memory() > $ceiling) {
                $this->say($ceiling === $limit
                    ? "stopping: past {$options->memoryMb} MB, for a fresh process to take over"
                    : "stopping: grown by more than {$options->memoryMb} MB, for a fresh process to take over");
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
     * SIGTERM, SIGINT or SIGQUIT: the stop, with the stop budget running
     * from now. The job in hand sees JobControl::stopping() say yes at
     * once; if it is still running as the budget runs out, the alarm stops
     * it (onAlarm()).
     */
    public function onStop(): void
    {
        if ($this->stopAskedAt !== null) {
            return;
        }
        $this->stopping = true;
        $this->stopAskedAt = hrtime(true);
        if ($this->running !== null) {
            $this->boundByStop();
        }
    }

    /**
     * Runs one reserved job and records how it went.
     *
     * @return 'done'|'released'|'failed'|'lost'|'paused'|'handed back'
     */
    public function process(ReservedJob $job): string
    {
        $began = hrtime(true);
        $envelope = null;
        $options = null;
        $control = null;
        $error = null;
        $interrupted = false;

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

            // Asked to stop while it was being taken: back untouched, for the
            // next worker, rather than started only for the stop to cut off.
            if ($this->stopRequested()) {
                $interrupted = true;
                throw WorkerStopping::in($envelope->class, $this->stopBudget);
            }

            $control = $this->runner->control($envelope, fn (): bool => $this->stopRequested());
            $this->holding = $job;
            $this->arm($envelope->class, $options->timeout);
            try {
                $this->runner->run($envelope, $control);
            } finally {
                $interrupted = $this->interrupted;
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
            $interrupted = $interrupted || $this->interrupted;
            $this->disarm();
            $this->timedOut = false;
            $this->interrupted = false;
            $this->holding = null;
        }

        // The boundary, before anything is written: a transaction the job
        // left open is rolled back here, so the outcome below is not written
        // inside it -- and lost with it.
        $this->resetScope();

        $outcome = $this->record($job, $envelope, $options, $error, $control?->released(), $interrupted);
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

    /**
     * @param array{delay: int, checkpoint: array<array-key, mixed>|null}|null $release what the job asked for through JobControl
     * @return 'done'|'released'|'failed'|'lost'|'paused'|'handed back'
     */
    private function record(ReservedJob $job, ?Envelope $envelope, ?QueueOptions $options, ?\Throwable $error, ?array $release, bool $interrupted): string
    {
        // Each write is the holder's alone, and says whether it was: a
        // worker whose reservation another has taken over records nothing.
        if ($error === null && $release !== null && $envelope !== null && $options !== null) {
            if ($envelope->releases < $options->releases) {
                // Sealed again from the envelope that was opened and
                // verified -- the same tenant, panel, user and epoch -- with
                // the checkpoint the job gave, and its try given back.
                return $this->queue->requeue($job, $this->now() + $release['delay'], $this->runner->pause($envelope, $release['checkpoint']))
                    ? 'paused'
                    : 'lost';
            }
            $error = new JobRefused('releases', "{$envelope->class} paused {$envelope->releases} times, as many as its #[Queue(releases: {$options->releases})] allows; a job that only ever pauses is a loop.");
            $this->reportFailure($error);
        }

        // Stopped by the stop, not by anything it did: whatever it threw on
        // its way out -- the WorkerStopping itself, or what an interrupted
        // read became -- is not a failed try. Back on its queue at once,
        // from its last checkpoint.
        if ($interrupted && !$error instanceof JobRefused) {
            return $this->queue->requeue($job, $this->now()) ? 'handed back' : 'lost';
        }

        if ($error === null) {
            return $this->queue->delete($job) ? 'done' : 'lost';
        }

        if (!$error instanceof JobRefused || $error->reason !== 'releases') {
            $this->reportFailure($error);
        }

        if (!$error instanceof JobRefused && $options !== null && $job->attempts < $options->tries) {
            return $this->queue->release($job, $this->now() + $options->backoffAfter($job->attempts)) ? 'released' : 'lost';
        }

        if (!$this->queue->fail($job, self::describe($error), $this->now())) {
            return 'lost';
        }

        // The job's own last word, in its context -- not for a refusal,
        // whose context is exactly what no longer checks out. Except one
        // for attempts: its last try never finished, and its context is the
        // one it was tried in.
        if ($envelope !== null && (!$error instanceof JobRefused || \in_array($error->reason, ['attempts', 'releases'], true))) {
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
        $this->interrupted = false;
        $this->alarmFor = 'timeout';
        pcntl_alarm($timeout);
        // A stop that came while it was being taken bounds it from the start.
        if ($this->stopAskedAt !== null) {
            $this->boundByStop();
        }
    }

    private function disarm(): void
    {
        $this->running = null;
        $this->alarmFor = 'timeout';
        pcntl_alarm(0);
    }

    /**
     * Moves the alarm up to the stop's interrupt, unless the job's own
     * timeout comes first -- whose grace then ends before the hand-back
     * would have, the two graces being the same length.
     */
    private function boundByStop(): void
    {
        if ($this->timedOut || $this->interrupted || $this->stopAskedAt === null) {
            return;
        }

        $since = intdiv(hrtime(true) - $this->stopAskedAt, 1_000_000_000);
        $interruptIn = max(1, StopBudget::interruptAfter($this->stopBudget, $this->graceSeconds) - $since);
        $timeoutIn = pcntl_alarm(0);
        if ($timeoutIn > 0 && $timeoutIn <= $interruptIn) {
            pcntl_alarm($timeoutIn);

            return;
        }

        $this->alarmFor = 'stop';
        pcntl_alarm($interruptIn);
    }

    /**
     * SIGALRM: the job in hand has run past its timeout, or the stop budget
     * is nearly spent with the job still running.
     */
    public function onAlarm(): void
    {
        if ($this->running === null) {
            return;
        }

        if ($this->alarmFor === 'hand back') {
            $this->handBack();

            return;
        }

        if ($this->alarmFor === 'stop') {
            // As a timeout is: thrown inside the job, with the grace for it
            // to unwind in, and then the hand-back.
            $this->interrupted = true;
            $this->alarmFor = 'hand back';
            pcntl_alarm($this->graceSeconds);

            throw WorkerStopping::in($this->running, $this->stopBudget);
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

    /**
     * The job would not stop, and the stop budget's margin is all that is
     * left before this process is killed. Killed holding it, the job would
     * wait out its reservation -- its timeout and RESERVATION_MARGIN --
     * before anyone ran it again. So it is handed back from here: the reset
     * contract first, which rolls back whatever transaction the job has
     * open, so the hand-back is not written inside it and nothing the job
     * half-did is kept; then the job back on its queue, available at once,
     * its try given back; then the process ends, since the job's stack is
     * still running and nothing on it can be trusted to finish.
     */
    private function handBack(): void
    {
        $job = $this->holding;
        $class = $this->running ?? 'unreadable job';
        $this->running = null;
        $this->holding = null;
        pcntl_alarm(0);

        try {
            $this->scope->reset();
        } catch (\Throwable $e) {
            $this->reportFailure($e);
        }

        $handed = false;
        try {
            $handed = $job !== null && $this->queue->requeue($job, $this->now());
        } catch (\Throwable $e) {
            $this->reportFailure($e);
        }

        $this->say($handed
            ? "{$class} would not stop within the stop budget; handed back to its queue, and exiting"
            : "{$class} would not stop within the stop budget, and could not be handed back; it runs again once its reservation lapses");
        ($this->exit ?? static fn (int $status) => exit($status))(0);
    }

    private function trapSignals(): void
    {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->onStop();
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

    /** What the process holds, as the operating system sees it: what a limit on it is about. */
    private function memory(): int
    {
        return $this->memory === null ? memory_get_usage(true) : ($this->memory)();
    }
}
