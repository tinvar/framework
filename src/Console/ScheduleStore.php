<?php

declare(strict_types=1);

namespace Tinvar\Console;

/**
 * Which task has run for which minute: so a task due at 10:05 runs once,
 * however many schedulers -- one per server, a cron and a `schedule:work`
 * left running -- reach 10:05.
 */
interface ScheduleStore
{
    /** Whether this caller is the one to run $task for $minute (unix seconds, on the minute). */
    public function claim(string $task, int $minute): bool;
}
