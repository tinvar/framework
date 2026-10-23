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
    /**
     * How many windows to hold before sweeping the expired ones.
     *
     * Without this the array only ever grew: a window was reset when its own
     * key came back, and a key never seen again stayed forever. On the
     * long-lived worker this framework is built around, a caller cycling
     * source addresses grows it until the worker dies -- reachable from
     * unauthenticated traffic, which is not covered by "this is not a
     * production store".
     */
    public const int SWEEP_AT = 10_000;

    /** @var array<string, array{count: int, resetsAt: int}> */
    private array $windows = [];

    /** @param (\Closure(): int)|null $clock for tests; defaults to time() */
    public function __construct(private readonly ?\Closure $clock = null) {}

    public function increment(string $key, int $window): array
    {
        $now = $this->clock === null ? time() : ($this->clock)();

        if (\count($this->windows) >= self::SWEEP_AT) {
            $this->sweep($now);
        }

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

    /**
     * Drops windows that have passed.
     *
     * An expired window carries no information: the next hit on that key
     * starts a fresh one either way, so forgetting it is free.
     */
    private function sweep(int $now): void
    {
        foreach ($this->windows as $key => $window) {
            if ($window['resetsAt'] <= $now) {
                unset($this->windows[$key]);
            }
        }
    }

    /** How many windows are held. For tests and the dev toolbar. */
    public function count(): int
    {
        return \count($this->windows);
    }
}
