<?php

declare(strict_types=1);

namespace Tinvar\Scenarios;

/**
 * What a `#[Scenario]` class does: seed its rows, and say which users it
 * made. Called only through Scenarios, which refuses a profile that does
 * not allow them.
 */
interface SeedsScenario
{
    /**
     * @param array<string, mixed> $input what the caller asked for: a test's JSON, a load test's size
     * @throws \InvalidArgumentException for input it refuses
     */
    public function seed(array $input): Seeded;
}
