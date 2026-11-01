<?php

declare(strict_types=1);

namespace Tinvar\Console;

/**
 * Claims in this process's memory: for tests, and for a single scheduler
 * that is the only one. Kept as long as the database store keeps its, a
 * day, so a schedule:work that runs for months holds a day of claims, not
 * every minute it was ever due in.
 */
final class ArrayScheduleStore implements ScheduleStore
{
    /** @var array<string, int> each claim, and the minute it is for */
    private array $claimed = [];

    public function claim(string $task, int $minute): bool
    {
        $key = "{$task}@{$minute}";
        if (isset($this->claimed[$key])) {
            return false;
        }
        $this->claimed = array_filter($this->claimed, static fn (int $at): bool => $at >= $minute - DatabaseScheduleStore::KEEP_SECONDS);
        $this->claimed[$key] = $minute;

        return true;
    }
}
