<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Counts in this process alone: for tests, and for a process that is the
 * only one serving -- never for FrankenPHP's worker threads, where each
 * would count its own share and a scrape would see whichever thread
 * answered it.
 */
final class ArrayMetricsStore implements MetricsStore
{
    /** @var array<string, int> */
    private array $values = [];

    public function add(array $deltas): void
    {
        foreach ($deltas as $key => $delta) {
            $this->values[$key] = ($this->values[$key] ?? 0) + $delta;
        }
    }

    public function all(): array
    {
        return $this->values;
    }
}
