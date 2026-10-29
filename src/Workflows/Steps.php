<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Jobs\JobControl;

/**
 * A workflow's view of its own progress: the steps it has finished, with
 * what each returned, and the way to take the next.
 *
 * **run() is replayed.** Every run of a workflow calls run() from the top.
 * A step that has finished is not run again: step() returns what it
 * returned the first time. The first step that has not finished runs, and
 * once it returns the run ends there -- the workflow goes back on its queue
 * with that step recorded, and the next run replays to the step after. So
 * each step is its own run on a worker, and before each one the runner
 * checks again that the tenant, the panel, the user and their auth epoch
 * the workflow was started under still hold (Tinvar\Jobs\JobRunner).
 *
 * So run() must ask for the same steps in the same order every time, given
 * what they returned: a step's name is how its result is found again, and
 * branching on anything but earlier steps' results -- the clock, a random
 * number, a row read outside a step -- can take a different path on the
 * replay. Read what a decision rests on inside a step, and branch on what
 * the step returned.
 *
 * **What a step returns is kept, sealed.** In the job's checkpoint
 * (JobControl), under the payload's MAC beside the tenant, panel, user and
 * epoch: readable by whoever reads the queue, writable by no one. Plain data
 * only -- strings, numbers, booleans, null and arrays of them -- and all of
 * a workflow's results together are at most JobControl::MAX_CHECKPOINT_BYTES
 * of JSON: an id, a status, a reference, not the work.
 *
 * **A step runs at least once.** If a worker dies after a step's writes
 * commit and before the step is recorded, the next run takes the step
 * again. Make a step's writes conditional on the state they move from
 * (`UPDATE ... WHERE status = 'placed'`), or hand idempotencyKey() to whoever
 * must not do the work twice.
 *
 * **A step that throws** fails the workflow's try: it is retried, from the
 * step that threw, as #[Queue(tries:, backoff:)] says, and its failed() hook
 * runs after the last. Every step and sleep is a pause of the job
 * (#[Queue(releases:)], 100 unless declared), so a workflow of more steps
 * than that declares more.
 */
final class Steps
{
    /** The checkpoint's version, so a later layout is not misread as this one. */
    private const int VERSION = 1;

    /** A step's name: a word, as a checkpoint key and a log line both read it. */
    private const string NAME = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/';

    /** @var array<string, mixed> finished steps' results, by name, in the order they finished */
    private array $done;

    /** @var array<string, true> the names asked for in this run, so one asked twice is caught */
    private array $asked = [];

    /** Whether this run has taken its one new step, and is ending. */
    private bool $ended = false;

    private int $delay = 0;

    /**
     * @param JobControl $job this run's, whose checkpoint holds what has finished
     */
    public function __construct(private readonly JobControl $job)
    {
        $this->done = self::read($job->checkpoint());
    }

    /**
     * Runs $work as the step $name, once: on a replay, returns what it
     * returned the first time, without running it.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function step(string $name, \Closure $work): mixed
    {
        $this->ask($name);
        if (\array_key_exists($name, $this->done)) {
            /** @var T */
            return $this->done[$name];
        }

        $this->end();
        $result = $work();
        $this->done[$name] = $result;
        $this->ended = true;

        // The run ends here, so this step is recorded before the next runs:
        // thrown past the rest of run(), and run() returning after catching
        // it changes nothing (Workflows::advance()).
        throw new StepTaken($this);
    }

    /**
     * Waits $seconds before the steps after this one, without holding a
     * worker: the workflow goes back on its queue, available then. On a
     * replay, a sleep already slept returns at once.
     */
    public function sleep(string $name, int $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('A workflow cannot sleep for a negative number of seconds.');
        }
        $this->ask($name);
        if (\array_key_exists($name, $this->done)) {
            return;
        }

        $this->end();
        $this->done[$name] = ['slept' => $seconds];
        $this->delay = $seconds;
        $this->ended = true;

        throw new StepTaken($this);
    }

    /** Whether the step $name has finished, in an earlier run or this one. */
    public function finished(string $name): bool
    {
        return \array_key_exists($name, $this->done);
    }

    /**
     * A key that is the same on every run of this workflow's step $name and
     * on no other step's or workflow's: for a step whose work must not
     * happen twice, to hand whoever does it (JobControl::idempotencyKey()).
     */
    public function idempotencyKey(string $name): string
    {
        return $this->job->idempotencyKey("workflow-step\0{$name}");
    }

    /**
     * Whether this run took a step and is ending: run() returned, or threw
     * StepTaken, after one.
     *
     * @internal Workflows::advance() asks
     */
    public function ended(): bool
    {
        return $this->ended;
    }

    /**
     * What goes back on the queue: the delay before the next run, and the
     * checkpoint with every finished step.
     *
     * @internal Workflows::advance() asks
     * @return array{delay: int, checkpoint: array{v: int, done: array<string, mixed>}}
     */
    public function pause(): array
    {
        return ['delay' => $this->delay, 'checkpoint' => ['v' => self::VERSION, 'done' => $this->done]];
    }

    private function ask(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a step name: letters, digits, '.', '_', ':' and '-', up to 64.");
        }
        if (isset($this->asked[$name])) {
            throw new \LogicException("The step '{$name}' was asked for twice in one run: each step has a name of its own.");
        }
        $this->asked[$name] = true;
    }

    /**
     * Refuses a second new step in one run. A workflow that caught the
     * StepTaken thrown past it and went on to the next step would run it
     * without the checks made before every step, and unrecorded.
     */
    private function end(): void
    {
        if ($this->ended) {
            throw new StepTaken($this);
        }
    }

    /**
     * The finished steps a checkpoint records. Only a checkpoint this class
     * wrote is read: anything else in the job's checkpoint -- there is none
     * but this, since the job is the workflow's own -- is refused rather
     * than taken for no progress, which would run every step again.
     *
     * @param array<array-key, mixed>|null $checkpoint
     * @return array<string, mixed>
     */
    private static function read(?array $checkpoint): array
    {
        if ($checkpoint === null) {
            return [];
        }
        if (($checkpoint['v'] ?? null) !== self::VERSION || !\is_array($checkpoint['done'] ?? null)) {
            throw new \UnexpectedValueException('The workflow\'s checkpoint is not one this version wrote.');
        }

        $done = [];
        foreach ($checkpoint['done'] as $name => $result) {
            $done[(string) $name] = $result;
        }

        return $done;
    }
}
