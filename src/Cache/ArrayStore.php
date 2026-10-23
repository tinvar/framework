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

    /** @var array<string, list<string>> tag => keys */
    private array $byTag = [];

    private int $hits = 0;
    private int $misses = 0;

    /** @param (\Closure(): float)|null $clock injectable so a test can move time */
    public function __construct(private readonly ?\Closure $clock = null) {}

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
        $this->entries[$key] = [
            'value' => $value,
            'expires' => $ttlSeconds === null ? null : $this->now() + $ttlSeconds,
        ];

        foreach ($tags as $tag) {
            if (!\in_array($key, $this->byTag[$tag] ?? [], true)) {
                $this->byTag[$tag][] = $key;
            }
        }
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);

        foreach ($this->byTag as $tag => $keys) {
            $remaining = array_values(array_filter($keys, static fn (string $k): bool => $k !== $key));
            if ($remaining === []) {
                unset($this->byTag[$tag]);
            } else {
                $this->byTag[$tag] = $remaining;
            }
        }
    }

    public function forgetTags(array $tags): int
    {
        $gone = 0;

        foreach ($tags as $tag) {
            foreach ($this->byTag[$tag] ?? [] as $key) {
                if (isset($this->entries[$key])) {
                    unset($this->entries[$key]);
                    $gone++;
                }
            }
            unset($this->byTag[$tag]);
        }

        return $gone;
    }

    public function flush(): void
    {
        $this->entries = [];
        $this->byTag = [];
    }

    /** @return array{hits: int, misses: int, entries: int} */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses, 'entries' => \count($this->entries)];
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }
}
