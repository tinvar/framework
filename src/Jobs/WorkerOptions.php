<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Attributes\Queue as QueueOptions;

/**
 * How a worker runs: which queues, in priority order, and when it stops.
 *
 *   tinvar queue:work [--queue=mail,default] [--sleep=1] [--max-jobs=N]
 *                     [--max-time=SECONDS] [--memory=MB] [--timeout=SECONDS]
 *                     [--stop-budget=SECONDS] [--stop-when-empty]
 *
 * `timeout` is only how long a reservation lasts past a job's own timeout
 * margin: each job's timeout is its #[Queue]'s.
 *
 * `memory` is how much the process may hold, in MB, once a job is done,
 * before the worker stops for a fresh process to take over; 0 is no limit.
 * A worker that starts past it may grow by that much from where it started.
 *
 * `stop-budget` is how long the worker has from SIGTERM until it is killed
 * (StopBudget): TINVAR_STOP_BUDGET unless the flag says, 70 unless either
 * does.
 */
final readonly class WorkerOptions
{
    /** @param list<string> $queues */
    public function __construct(
        public array $queues = [QueueOptions::DEFAULT],
        public int $sleep = 1,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public int $memoryMb = 128,
        public int $timeout = 60,
        public bool $stopWhenEmpty = false,
        public bool $signals = true,
        public int $stopBudget = StopBudget::DEFAULT,
    ) {
        if ($queues === []) {
            throw new \InvalidArgumentException('A worker needs a queue to work.');
        }
        StopBudget::check($stopBudget);
    }

    /**
     * From `queue:work`'s arguments.
     *
     * @param list<string> $arguments
     * @param list<string> $queues what it works without --queue, in priority order
     * @param int $stopBudget what it has to stop in without --stop-budget: the environment's
     */
    public static function fromArguments(array $arguments, array $queues = [QueueOptions::DEFAULT], int $stopBudget = StopBudget::DEFAULT): self
    {
        $values = [];
        $stopWhenEmpty = false;
        foreach ($arguments as $argument) {
            if ($argument === '--stop-when-empty') {
                $stopWhenEmpty = true;

                continue;
            }
            if (preg_match('/^--(queue|sleep|max-jobs|max-time|memory|timeout|stop-budget)=(.+)\z/', $argument, $m) !== 1) {
                throw new \InvalidArgumentException(
                    "Unknown argument '{$argument}'. Usage: tinvar queue:work [--queue=a,b] [--sleep=S] [--max-jobs=N] "
                    . '[--max-time=S] [--memory=MB] [--timeout=S] [--stop-budget=S] [--stop-when-empty]',
                );
            }
            if ($m[1] !== 'queue' && preg_match('/^\d{1,9}\z/', $m[2]) !== 1) {
                throw new \InvalidArgumentException("--{$m[1]} takes a whole number; got '{$m[2]}'.");
            }
            $values[$m[1]] = $m[2];
        }

        return new self(
            queues: isset($values['queue']) ? array_values(array_filter(explode(',', $values['queue']), static fn (string $q): bool => $q !== '')) : $queues,
            sleep: (int) ($values['sleep'] ?? 1),
            maxJobs: (int) ($values['max-jobs'] ?? 0),
            maxTime: (int) ($values['max-time'] ?? 0),
            memoryMb: (int) ($values['memory'] ?? 128),
            timeout: (int) ($values['timeout'] ?? 60),
            stopWhenEmpty: $stopWhenEmpty,
            stopBudget: isset($values['stop-budget']) ? StopBudget::parse($values['stop-budget']) : $stopBudget,
        );
    }
}
