<?php

declare(strict_types=1);

namespace Tinvar\Console;

/** Claims in this process's memory: for tests, and for a single scheduler that is the only one. */
final class ArrayScheduleStore implements ScheduleStore
{
    /** @var array<string, true> */
    private array $claimed = [];

    public function claim(string $task, int $minute): bool
    {
        $key = "{$task}@{$minute}";
        if (isset($this->claimed[$key])) {
            return false;
        }

        return $this->claimed[$key] = true;
    }
}
