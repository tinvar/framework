<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * An in-process throttle store, for tests and single-process development.
 *
 * NOT a production store, for the reason the interface gives: counters here
 * are per worker, so running four workers quadruples every limit. A limit
 * that looks enforced and is not is worse than no limit, so this is named for
 * what it is rather than offered as a default.
 */
final class ArrayThrottleStore implements ThrottleStore
{
    /** @var array<string, array{count: int, resetsAt: int}> */
    private array $windows = [];

    /** @param (\Closure(): int)|null $clock for tests; defaults to time() */
    public function __construct(private readonly ?\Closure $clock = null) {}

    public function increment(string $key, int $window): array
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        $current = $this->windows[$key] ?? null;

        // A window that has passed is a fresh window, not a continued one.
        if ($current === null || $current['resetsAt'] <= $now) {
            $current = ['count' => 0, 'resetsAt' => $now + $window];
        }

        $current['count']++;
        $this->windows[$key] = $current;

        return [$current['count'], $current['resetsAt']];
    }

    /** Clears every window. Request teardown has no business calling this. */
    public function flush(): void
    {
        $this->windows = [];
    }
}
