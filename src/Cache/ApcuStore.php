<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * Cached query results in APCu, shared by every worker thread of one
 * FrankenPHP process.
 *
 * ArrayStore keeps a worker's entries in that worker's memory, and under
 * FrankenPHP's worker mode every thread has its own: an action's
 * forgetTags() in one thread never reached the entries another held, so a
 * cached query went on serving the answer from before the write, in every
 * thread but the one that wrote, until its TTL ran out. APCu is one memory
 * for the whole process, as it is for the throttle counts
 * (ApcuThrottleStore).
 *
 * Not shared between servers, nor with the queue worker and the scheduler,
 * which run as processes of their own: an application on several servers
 * needs a store they all reach (PdoStore).
 *
 * Tags are versions, not indexes. A tag is a counter in APCu, and an entry
 * is kept with the version each of its tags had when it was written; a read
 * compares them and misses on any difference. Forgetting a tag moves its
 * counter -- one atomic write, however many entries carry the tag and
 * whichever threads put them -- and every thread's next read of those
 * entries misses. An index of the keys under each tag would be a list every
 * put adds to and every forget walks, edited from many threads at once with
 * no lock APCu holds across two entries; an update lost there is an entry
 * no forget can reach, which is the failure this class exists to end. What
 * versions cannot do is count: forgetTags() visits no entry, and returns 0.
 *
 * Every forget takes its version from one clock, a counter moved once per
 * forgetTags(), so a tag's version only rises and says when it was last
 * forgotten. That is how a put refuses an answer read before a forget that
 * landed after the read (Misses).
 *
 * Fails closed. An entry is good only while every tag it was written under
 * holds the version it recorded, so a tag APCu has dropped -- expired,
 * evicted, cleared -- reads as a difference and its entries miss. A tag
 * made again starts at the clock as it stands, which is above every version
 * taken before it unless nothing at all was forgotten in between, when the
 * entries that match it are still good. A tag that cannot be moved empties
 * the store rather than leaving its entries standing.
 *
 * Memory is bounded twice. An entry larger than $maxBytes, measured as
 * serialize() writes it, is not kept. And a put that finds less than
 * $minFree of APCu's memory free clears this store's entries and tags
 * first, and keeps nothing if that was not enough: APCu, once full, empties
 * itself whole -- the throttle counts and the metrics with it -- so a caller
 * minting distinct cache keys would otherwise hand everyone a fresh sign-in
 * budget. The clock survives a clear, so versions still only rise.
 *
 * Entries are APCu's own copies of PHP values. Nothing outside this process
 * writes its APCu, so what comes back is what was put, Bin objects
 * included; a store whose rows anyone else can write is PdoStore, which
 * checks.
 */
final class ApcuStore implements Store
{
    /** The largest entry kept, in bytes as serialize() writes it, unless the store is given another. */
    public const int MAX_BYTES = 1_048_576;

    /** The share of APCu's memory a put leaves free, unless the store is given another. */
    public const float MIN_FREE = 0.25;

    /**
     * How long a tag's counter lives. Its entries are good only while it
     * does, so one cached for longer misses early once anything has been
     * forgotten since; the TTL is what lets APCu reclaim the counters of
     * tags no longer used.
     */
    public const int TAG_TTL = 604_800;

    /** @var \Closure(string|list<string>): mixed */
    private readonly \Closure $fetch;

    /** @var \Closure(string, mixed, int): bool */
    private readonly \Closure $store;

    /** @var \Closure(string, mixed, int): bool */
    private readonly \Closure $add;

    /** @var \Closure(string, int, int): (int|false) */
    private readonly \Closure $inc;

    /** @var \Closure(string, int, int): bool */
    private readonly \Closure $cas;

    /** @var \Closure(string): bool */
    private readonly \Closure $delete;

    /** @var \Closure(string): void */
    private readonly \Closure $clear;

    /** @var \Closure(): ?float */
    private readonly \Closure $free;

    private readonly Misses $misses;

    /**
     * @param string $prefix  set apart from anything else in APCu -- the application's other
     *        stores, and any other application sharing the process
     * @param int $maxBytes   the largest entry kept
     * @param float $minFree  the share of APCu's memory a put leaves free
     * @param (\Closure(): int)|null $clock for tests; defaults to time()
     * @param (\Closure(string|list<string>): mixed)|null $fetch for tests; defaults to apcu_fetch()
     * @param (\Closure(string, mixed, int): bool)|null $store for tests; defaults to apcu_store()
     * @param (\Closure(string, mixed, int): bool)|null $add for tests; defaults to apcu_add()
     * @param (\Closure(string, int, int): (int|false))|null $inc for tests; defaults to apcu_inc()
     * @param (\Closure(string, int, int): bool)|null $cas for tests; defaults to apcu_cas()
     * @param (\Closure(string): bool)|null $delete for tests; defaults to apcu_delete()
     * @param (\Closure(string): void)|null $clear deletes every key starting with the
     *        string; for tests, defaults to apcu_delete() over an APCUIterator
     * @param (\Closure(): ?float)|null $free the share of APCu's memory free, or null when
     *        it cannot say; for tests, defaults to apcu_sma_info()
     */
    public function __construct(
        private readonly string $prefix = 'tinvar:cache:',
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly float $minFree = self::MIN_FREE,
        private readonly ?\Closure $clock = null,
        ?\Closure $fetch = null,
        ?\Closure $store = null,
        ?\Closure $add = null,
        ?\Closure $inc = null,
        ?\Closure $cas = null,
        ?\Closure $delete = null,
        ?\Closure $clear = null,
        ?\Closure $free = null,
    ) {
        if ($prefix === '') {
            throw new \InvalidArgumentException('An APCu cache store needs a prefix of its own.');
        }

        $real = $fetch === null || $store === null || $add === null || $inc === null
            || $cas === null || $delete === null || $clear === null || $free === null;
        if ($real && !self::available()) {
            throw new \RuntimeException(
                'APCu is not available: load the apcu extension, and set apc.enable_cli=1 to use it from the CLI.',
            );
        }

        $this->fetch = $fetch ?? static fn (string|array $key): mixed => apcu_fetch($key);
        $this->store = $store ?? static fn (string $key, mixed $value, int $ttl): bool => apcu_store($key, $value, $ttl);
        $this->add = $add ?? static fn (string $key, mixed $value, int $ttl): bool => apcu_add($key, $value, $ttl);
        $this->inc = $inc ?? static function (string $key, int $step, int $ttl): int|false {
            $success = false;

            return apcu_inc($key, $step, $success, $ttl);
        };
        $this->cas = $cas ?? static fn (string $key, int $old, int $new): bool => apcu_cas($key, $old, $new);
        $this->delete = $delete ?? static fn (string $key): bool => apcu_delete($key) === true;
        $this->clear = $clear ?? static function (string $prefix): void {
            apcu_delete(new \APCUIterator('/^' . preg_quote($prefix, '/') . '/', APC_ITER_KEY));
        };
        $this->free = $free ?? static function (): ?float {
            $info = apcu_sma_info(true);
            if (!\is_array($info)) {
                return null;
            }
            $size = (int) ($info['num_seg'] ?? 0) * (int) ($info['seg_size'] ?? 0);

            return $size > 0 ? (float) ($info['avail_mem'] ?? 0) / $size : null;
        };
        $this->misses = new Misses();
    }

    /** Whether APCu is loaded and enabled in this SAPI. */
    public static function available(): bool
    {
        return \function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key): array
    {
        $entry = ($this->fetch)($this->entry($key));

        if (\is_array($entry) && ($entry['k'] ?? null) === $key && \array_key_exists('v', $entry) && $this->good($entry)) {
            $this->misses->take($key);

            return ['hit' => true, 'value' => $entry['v']];
        }

        // Noted for the put that follows (Misses). No clock is one that
        // could not be made, and a put after such a miss keeps nothing it
        // would have to tag.
        $this->misses->note($key, $this->clock() ?? PHP_INT_MIN);

        return ['hit' => false, 'value' => null];
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $since = $this->misses->take($key);
        $name = $this->entry($key);

        // To APCu a TTL of 0 is forever; to a caller it is already gone.
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            ($this->delete)($name);

            return;
        }

        $entry = [
            'k' => $key,
            'v' => $value,
            'x' => $ttlSeconds === null ? null : $this->now() + $ttlSeconds,
            't' => [],
        ];

        // A put replaces: a value that is not kept must not leave the one
        // before it to be read in its place.
        if (!$this->fits($entry) || !$this->makeRoom()) {
            ($this->delete)($name);

            return;
        }

        $versions = $this->versions(array_values(array_unique(array_map($this->tag(...), $tags))));
        if ($versions === null) {
            ($this->delete)($name);

            return;
        }

        // Forgotten after the miss this answers: the body may have read the
        // rows from before the write. Whatever is under the name now is left
        // alone -- an entry from before the forget already misses, and one
        // put since is newer than this.
        if ($since !== null) {
            foreach ($versions as $version) {
                if ($version > $since) {
                    return;
                }
            }
        }

        $entry['t'] = $versions;
        if (!($this->store)($name, $entry, $ttlSeconds ?? 0)) {
            ($this->delete)($name);
        }
    }

    public function forget(string $key): void
    {
        $this->misses->take($key);
        ($this->delete)($this->entry($key));
    }

    public function forgetTags(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        $at = $this->tick();
        foreach (array_unique(array_map($this->tag(...), $tags)) as $name) {
            if ($at === null || !$this->raise($name, $at)) {
                // A tag left where it was would leave its entries good, the
                // stale answer this is here to end; every entry goes instead.
                $this->clear();

                return 0;
            }
        }

        return 0;
    }

    public function flush(): void
    {
        $this->clear();
    }

    /**
     * Whether every tag an entry was written under still has the version it
     * recorded, and it has not expired.
     *
     * @param array<array-key, mixed> $entry
     */
    private function good(array $entry): bool
    {
        $expires = $entry['x'] ?? null;
        if ($expires !== null && (!\is_int($expires) || $expires <= $this->now())) {
            return false;
        }

        $recorded = $entry['t'] ?? null;
        if (!\is_array($recorded)) {
            return false;
        }
        if ($recorded === []) {
            return true;
        }

        $current = ($this->fetch)(array_map('strval', array_keys($recorded)));
        if (!\is_array($current)) {
            return false;
        }
        foreach ($recorded as $name => $version) {
            if (($current[$name] ?? null) !== $version) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each tag's version, made at the clock where APCu has none; null when
     * one could not be read or made.
     *
     * @param list<string> $names
     * @return array<string, int>|null
     */
    private function versions(array $names): ?array
    {
        if ($names === []) {
            return [];
        }

        $found = ($this->fetch)($names);
        $versions = [];
        $missing = [];
        foreach ($names as $name) {
            $version = \is_array($found) ? ($found[$name] ?? null) : null;
            if (\is_int($version)) {
                $versions[$name] = $version;
            } else {
                $missing[] = $name;
            }
        }

        if ($missing === []) {
            return $versions;
        }

        $clock = $this->clock();
        if ($clock === null) {
            return null;
        }
        foreach ($missing as $name) {
            // Added, not stored: another thread's forget may have made it a
            // moment ago, at a version this must not take back.
            ($this->add)($name, $clock, self::TAG_TTL);
            $version = ($this->fetch)($name);
            if (!\is_int($version)) {
                return null;
            }
            $versions[$name] = $version;
        }

        return $versions;
    }

    /**
     * Moves a tag's version up to $at, and never down: two forgets finishing
     * out of order leave the later version. Made at $at when APCu has none.
     */
    private function raise(string $name, int $at): bool
    {
        // Retried while other threads move it under this one; a tag that
        // never holds still is answered by clearing the store.
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $version = ($this->fetch)($name);
            if (!\is_int($version)) {
                if (($this->add)($name, $at, self::TAG_TTL)) {
                    return true;
                }
                continue;
            }
            if ($version >= $at || ($this->cas)($name, $version, $at)) {
                return true;
            }
        }

        return false;
    }

    /** The clock, made if APCu has none; null when APCu will not keep it. */
    private function clock(): ?int
    {
        $name = $this->prefix . 'clock';
        $at = ($this->fetch)($name);
        if (\is_int($at)) {
            return $at;
        }

        // From the time, in microseconds, not from zero: a clock APCu lost
        // and this made again starts above every version taken from the one
        // before -- unless that one moved more than a million times a second
        // over its whole life -- so a miss noted against the old clock is
        // still answered right by the new.
        ($this->add)($name, $this->now() * 1_000_000, 0);
        $at = ($this->fetch)($name);

        return \is_int($at) ? $at : null;
    }

    /** The clock moved on by one, for a forget; null when APCu will not keep it. */
    private function tick(): ?int
    {
        // Made first: an increment of a key APCu does not have starts it
        // from nothing.
        if ($this->clock() === null) {
            return null;
        }
        $at = ($this->inc)($this->prefix . 'clock', 1, 0);

        return \is_int($at) ? $at : null;
    }

    /**
     * Whether an entry is small enough to keep. A value serialize() refuses
     * -- a closure -- is not kept either.
     *
     * @param array<string, mixed> $entry
     */
    private function fits(array $entry): bool
    {
        try {
            return \strlen(serialize($entry)) <= $this->maxBytes;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether there is room to write: with less than $minFree of APCu free,
     * this store's own entries and tags are cleared first, and if that is
     * not enough, nothing is written.
     */
    private function makeRoom(): bool
    {
        $free = ($this->free)();
        if ($free === null || $free >= $this->minFree) {
            return true;
        }

        $this->clear();
        $free = ($this->free)();

        return $free === null || $free >= $this->minFree;
    }

    /** Every entry and tag of this store's; the clock stays, so versions only rise. */
    private function clear(): void
    {
        ($this->clear)($this->prefix . 'e:');
        ($this->clear)($this->prefix . 't:');
    }

    private function entry(string $key): string
    {
        return $this->prefix . 'e:' . EntryName::of($key);
    }

    private function tag(string $tag): string
    {
        return $this->prefix . 't:' . EntryName::of($tag);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
