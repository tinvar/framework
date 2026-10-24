<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Where Metrics keeps its counts between processes.
 *
 * Every value is a sum that only grows -- a counter, a histogram's buckets,
 * its sum and its count -- in millionths, as an integer, so a store needs
 * nothing but atomic addition: APCu's `apcu_inc`, an upsert adding to a
 * column. Gauges are not stored at all; a Collector reads them when the
 * metrics are scraped.
 */
interface MetricsStore
{
    /** @param array<string, int> $deltas series key => amount to add, in millionths */
    public function add(array $deltas): void;

    /** @return array<string, int> every series key and its total, in millionths */
    public function all(): array;
}
