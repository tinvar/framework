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
}
