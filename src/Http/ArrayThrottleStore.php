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

    /**
     * When to sweep next. Doubled past what a sweep leaves behind: with that
     * many windows still live, sweeping on every hit made each one cost the
     * whole array -- the growth this bound exists to stop, paid in CPU.
     */
    private int $sweepAt = self::SWEEP_AT;

    /** @param (\Closure(): int)|null $clock for tests; defaults to time() */
    public function __construct(private readonly ?\Closure $clock = null) {}

    public function increment(string $key, int $window): array
    {
        $now = $this->clock === null ? time() : ($this->clock)();

        if (\count($this->windows) >= $this->sweepAt) {
            $this->sweep($now);
            $this->sweepAt = max(self::SWEEP_AT, 2 * \count($this->windows));
        }

        // Fixed windows aligned to the clock, as every other store keeps
        // them: the window a hit falls in is floor(now / window), not one
        // its first hit began. And one count per key and window size, as
        // theirs are, so a test that passes here passes in production.
        $window = max(1, $window);
        $entry = "{$window}:{$key}";
        $current = $this->windows[$entry] ?? null;

        // A window that has passed is a fresh window, not a continued one.
        if ($current === null || $current['resetsAt'] <= $now) {
            $current = ['count' => 0, 'resetsAt' => (intdiv($now, $window) + 1) * $window];
        }

        $current['count']++;
        $this->windows[$entry] = $current;

        return [$current['count'], $current['resetsAt']];
    }

    public function release(string $key, int $window, int $hits = 1): void
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        $entry = max(1, $window) . ":{$key}";
        $current = $this->windows[$entry] ?? null;
        if ($current === null || $current['resetsAt'] <= $now) {
            return;
        }

        $this->windows[$entry]['count'] = max(0, $current['count'] - max(0, $hits));
    }

    /** Clears every window. Request teardown has no business calling this. */
    public function flush(): void
    {
        $this->windows = [];
        $this->sweepAt = self::SWEEP_AT;
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
