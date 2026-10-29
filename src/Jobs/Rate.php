<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * How fast a rate-limited queue's jobs may start: at most `runs` in each
 * window of `per` seconds, counted across every worker on the queue's
 * store (ADR 0100).
 *
 *     new Console(..., rates: ['mail' => new Rate(runs: 100, per: 60)]);
 *
 * Given by the application where it wires its console, not by each worker
 * started, so no worker started without a flag runs past it. The windows
 * are fixed -- the minute a start falls in, not the sixty seconds before
 * it -- so up to twice `runs` can start across a window's edge; a limit
 * that must hold over any `per` seconds takes half the runs.
 */
final readonly class Rate
{
    /** A day: past that, a window is not a rate. */
    public const int MAX_PER = 86_400;

    public function __construct(
        public int $runs,
        public int $per = 60,
    ) {
        if ($runs < 1) {
            throw new \InvalidArgumentException('A rate lets at least one job start in its window.');
        }
        if ($per < 1 || $per > self::MAX_PER) {
            throw new \InvalidArgumentException('A rate\'s window is from a second to a day.');
        }
    }
}
