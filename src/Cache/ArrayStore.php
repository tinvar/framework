<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * An in-process store, for tests and single-worker development.
 *
 * Deliberately not the production default: entries live in one worker's
 * memory, so two workers would disagree and a revalidation in one would not
 * reach the other.
 */
final class ArrayStore implements Store
{
    /** @var array<string, array{value: mixed, expires: float|null}> */
    private array $entries = [];

    /**
     * tag => key => true.
     *
     * A set, not a list: membership and removal were O(n), so 20k puts under
     * one shared tag took half a second.
     *
     * @var array<string, array<string, true>>
     */
    private array $byTag = [];

    /**
     * key => tags, so forgetting a key can clean every tag it carried.
     *
     * Without the inverse, forgetTags() deleted the entry and unset only the
     * tag it was asked about; every other tag kept pointing at a key that no
     * longer existed, forever.
     *
     * @var array<string, list<string>>
     */
    private array $tagsOf = [];

    private int $hits = 0;
    private int $misses = 0;

    /** Puts past the cap before sweep() next walks every entry for the expired; the first walks at once. */
    private int $putsUntilWalk = 0;

    /**
     * @param (\Closure(): float)|null $clock injectable so a test can move time
     * @param int $maxEntries a cap, because expiry alone is read-triggered:
     *        an entry nobody reads is otherwise immortal, and a caller can
     *        mint distinct keys without limit
     */
    public function __construct(
        private readonly ?\Closure $clock = null,
        private readonly int $maxEntries = 10_000,
    ) {}

    public function get(string $key): array
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            $this->misses++;

            return ['hit' => false, 'value' => null];
        }

        if ($entry['expires'] !== null && $entry['expires'] <= $this->now()) {
            $this->forget($key);
            $this->misses++;

            return ['hit' => false, 'value' => null];
        }

        $this->hits++;

        return ['hit' => true, 'value' => $entry['value']];
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        // Re-putting a key replaces its tags rather than adding to them, or a
        // tag it no longer carries would still invalidate it.
        $this->detach($key);

        // Unset first: assigning to an existing key keeps its place in the
        // array, and sweep() evicts by that order, oldest first. A key put
        // again kept the place of its first write and went before keys
        // written since it.
        unset($this->entries[$key]);
        $this->entries[$key] = [
            'value' => $value,
            'expires' => $ttlSeconds === null ? null : $this->now() + $ttlSeconds,
        ];
        $this->tagsOf[$key] = array_values(array_unique($tags));

        foreach ($this->tagsOf[$key] as $tag) {
            $this->byTag[$tag][$key] = true;
        }

        $this->sweep();
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
        $this->detach($key);
    }

    public function forgetTags(array $tags): int
    {
        $gone = 0;

        foreach ($tags as $tag) {
            foreach (array_keys($this->byTag[$tag] ?? []) as $key) {
                if (isset($this->entries[$key])) {
                    unset($this->entries[$key]);
                    $gone++;
                }
                // Every tag this key carried, not just the one asked about.
                $this->detach($key);
            }
            unset($this->byTag[$tag]);
        }

        return $gone;
    }

    public function flush(): void
    {
        $this->entries = [];
        $this->byTag = [];
        $this->tagsOf = [];
    }

    /** Removes a key from every tag index it appears in. */
    private function detach(string $key): void
    {
        foreach ($this->tagsOf[$key] ?? [] as $tag) {
            unset($this->byTag[$tag][$key]);
            if (($this->byTag[$tag] ?? []) === []) {
                unset($this->byTag[$tag]);
            }
        }

        unset($this->tagsOf[$key]);
    }

    /**
     * Drops expired entries, and the oldest if the cap is still exceeded.
     *
     * Amortised over writes: expiry is otherwise read-triggered, so an entry
     * nobody reads never goes, and a caller minting distinct keys grows the
     * worker's heap until the process dies.
     */
    private function sweep(): void
    {
        if (\count($this->entries) <= $this->maxEntries) {
            return;
        }

        // The walk for the expired is every entry, so a full store does it
        // once every sixteenth of its cap in puts past it, not on each one:
        // walked on every put, a full worker paid for all its entries on
        // every miss. Between walks the oldest goes, which is where the
        // expired mostly are.
        if (--$this->putsUntilWalk <= 0) {
            $this->putsUntilWalk = max(1, intdiv($this->maxEntries, 16));
            $now = $this->now();
            foreach ($this->entries as $key => $entry) {
                if ($entry['expires'] !== null && $entry['expires'] <= $now) {
                    $this->forget($key);
                }
            }
        }

        // Insertion order is oldest first.
        while (\count($this->entries) > $this->maxEntries && ($oldest = array_key_first($this->entries)) !== null) {
            $this->forget($oldest);
        }
    }

    /** @return array{hits: int, misses: int, entries: int, tags: int} */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'entries' => \count($this->entries),
            'tags' => \count($this->byTag),
        ];
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }
}
