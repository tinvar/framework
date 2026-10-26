<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * Where a shared store's invalidation clock stood when each key last missed,
 * for the put that follows the miss.
 *
 * A miss is followed by the query body, then by put(). A write committed and
 * its tags forgotten between the two -- by another worker, or another server
 * -- leaves the body holding rows from before the write, and put() would
 * keep them for the whole TTL: the forget came before the entry it was meant
 * to drop. So put() asks whether any of the entry's tags was forgotten after
 * the miss, and keeps nothing if one was. Every forget takes its version
 * from the clock, so "after the miss" is "a version above the clock read at
 * the miss".
 *
 * Per store instance, which is per worker, and a bounded number of keys: a
 * miss whose put never comes -- the body failed, its tags could not be
 * worked out -- is dropped oldest first. A put with no miss noted is kept
 * as it would have been without this.
 *
 * @internal for ApcuStore and PdoStore
 */
final class Misses
{
    /** Misses remembered at most; more than any one request's queries. */
    public const int KEPT = 1024;

    /** @var array<string, int> key => the clock at its last miss */
    private array $at = [];

    public function note(string $key, int $clock): void
    {
        // Moved to the end, so the oldest is first.
        unset($this->at[$key]);
        $this->at[$key] = $clock;

        if (\count($this->at) > self::KEPT) {
            unset($this->at[array_key_first($this->at)]);
        }
    }

    /** The clock at the key's last miss, forgotten as it is read; null for none noted. */
    public function take(string $key): ?int
    {
        $clock = $this->at[$key] ?? null;
        unset($this->at[$key]);

        return $clock;
    }
}
