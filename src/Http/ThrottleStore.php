<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Where throttle counters live.
 *
 * An interface because the only correct production implementation is shared
 * across workers -- a per-process counter multiplies every limit by the
 * number of workers, which is the kind of limit that looks enforced and is
 * not. ArrayThrottleStore is for tests and single-process development, and
 * says so.
 */
interface ThrottleStore
{
    /**
     * Counts one hit against a key and returns the running total.
     *
     * Must be atomic: two concurrent requests must never both read the same
     * count and both write count+1, or the limit is advisory.
     *
     * @return array{int, int} the count including this hit, and when the
     *         window resets as a unix timestamp
     */
    public function increment(string $key, int $window): array;

    /**
     * Takes back hits counted against a key in its current window.
     *
     * For a budget that counts only some outcomes -- a sign-in counts its
     * failures. Whether an attempt fails is known only after it ran, and
     * checking a count before running would let concurrent attempts all
     * pass a check none of them had counted yet; so an attempt is counted
     * first, atomically, and taken back here once it turns out not to count.
     *
     * Never below zero, and never a window of its own: taking back from a
     * window that has passed, or was never started, does nothing. Pass
     * PHP_INT_MAX to clear the key's window.
     */
    public function release(string $key, int $window, int $hits = 1): void;
}
