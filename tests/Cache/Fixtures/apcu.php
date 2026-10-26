<?php

declare(strict_types=1);

namespace Tinvar\Tests\Cache\Fixtures;

use Tinvar\Cache\ApcuStore;

/**
 * APCu in memory: one instance is one process's APCu, and every ApcuStore
 * built on it is a worker thread of that process. TTLs are honoured against
 * $now, and the memory it says is free shrinks with the keys it holds when
 * it is given a capacity.
 */
final class FakeApcu
{
    /** @var array<string, array{value: mixed, expires: int}> expires 0 for never */
    public array $entries = [];

    public int $now = 1_000_000;

    /** How many keys fill it, for free(); null for a fake that cannot say. */
    public ?int $capacity = null;

    /** Whether apcu_cas() refuses every swap, as it would for a key that never holds still. */
    public bool $casRefused = false;

    public function store(string $prefix = 'tinvar:cache:', int $maxBytes = ApcuStore::MAX_BYTES, float $minFree = ApcuStore::MIN_FREE): ApcuStore
    {
        return new ApcuStore(
            $prefix,
            $maxBytes,
            $minFree,
            clock: fn (): int => $this->now,
            fetch: function (string|array $key): mixed {
                if (\is_string($key)) {
                    return $this->live($key) ? $this->entries[$key]['value'] : false;
                }
                $found = [];
                foreach ($key as $name) {
                    if ($this->live($name)) {
                        $found[$name] = $this->entries[$name]['value'];
                    }
                }

                return $found;
            },
            store: function (string $key, mixed $value, int $ttl): bool {
                $this->entries[$key] = ['value' => $value, 'expires' => $ttl === 0 ? 0 : $this->now + $ttl];

                return true;
            },
            add: function (string $key, mixed $value, int $ttl): bool {
                if ($this->live($key)) {
                    return false;
                }
                $this->entries[$key] = ['value' => $value, 'expires' => $ttl === 0 ? 0 : $this->now + $ttl];

                return true;
            },
            inc: function (string $key, int $step, int $ttl): int|false {
                // As APCu 5.1.12 and later do: a key that is not there is
                // made, from nothing.
                if (!$this->live($key)) {
                    $this->entries[$key] = ['value' => 0, 'expires' => $ttl === 0 ? 0 : $this->now + $ttl];
                }
                if (!\is_int($this->entries[$key]['value'])) {
                    return false;
                }

                return $this->entries[$key]['value'] += $step;
            },
            cas: function (string $key, int $old, int $new): bool {
                if ($this->casRefused || !$this->live($key) || $this->entries[$key]['value'] !== $old) {
                    return false;
                }
                $this->entries[$key]['value'] = $new;

                return true;
            },
            delete: function (string $key): bool {
                $there = isset($this->entries[$key]);
                unset($this->entries[$key]);

                return $there;
            },
            clear: function (string $prefix): void {
                foreach (array_keys($this->entries) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        unset($this->entries[$key]);
                    }
                }
            },
            free: fn (): ?float => $this->capacity === null ? null : max(0.0, 1 - \count($this->entries) / $this->capacity),
        );
    }

    /** The keys held under a prefix. */
    public function keys(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->entries), static fn (string $key): bool => str_starts_with($key, $prefix)));
    }

    private function live(string $key): bool
    {
        return isset($this->entries[$key])
            && ($this->entries[$key]['expires'] === 0 || $this->entries[$key]['expires'] > $this->now);
    }
}
