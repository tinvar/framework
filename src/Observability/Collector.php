<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Values read when the metrics are scraped -- a queue's depth, whether each
 * renderer in the pool answers -- rather than counted as they happen.
 *
 * Gauges by default. A sample may say it is a counter, for a count kept
 * somewhere else that only grows -- a renderer's finished renders -- which
 * Prometheus then reads across the resets that happen when its owner is
 * replaced.
 */
interface Collector
{
    /**
     * @return list<array{name: string, help: string, type?: 'gauge'|'counter', labels?: array<string, string>, value: float|int}>
     */
    public function collect(): array;
}
