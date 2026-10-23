<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * "Keyed by connection, tenant, class and ID, and reset per request, per job and
 * on every tenant switch. The same row is the same object, which lazy batching
 * depends on." (tinvar-spec.md, "Engine layers")
 *
 * The invariant matters beyond object identity: a relation loaded for one parent
 * has to be visible to every other holder of that same row, or a batched load
 * would be wasted.
 */
final class IdentityMap
{
    /** @var array<string, array<string, object>> bucket => id => model */
    private array $rows = [];

    private string $tenant = '';

    public function __construct(private readonly string $connection = 'default') {}

    /** @template T of object @param class-string<T> $class @return T|null */
    public function get(string $class, string $id): ?object
    {
        /** @var T|null */
        return $this->rows[$this->bucket($class)][$id] ?? null;
    }

    /**
     * Stores the model unless the row is already mapped, and returns whichever
     * instance is canonical. Callers must use the return value.
     *
     * @template T of object @param T $model @return T
     */
    public function remember(string $class, string $id, object $model): object
    {
        $bucket = $this->bucket($class);
        /** @var T|null $existing */
        $existing = $this->rows[$bucket][$id] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        $this->rows[$bucket][$id] = $model;

        return $model;
    }

    public function has(string $class, string $id): bool
    {
        return isset($this->rows[$this->bucket($class)][$id]);
    }

    /**
     * Switches tenant, which is also a reset: identity is scoped to a tenant, so
     * rows from the previous one must not stay reachable.
     */
    public function switchTenant(string $tenant): void
    {
        if ($tenant !== $this->tenant) {
            $this->tenant = $tenant;
        }
    }

    public function tenant(): string
    {
        return $this->tenant;
    }

    /** Called at every request, job, actAs and acrossTenants boundary. */
    public function flush(): void
    {
        $this->rows = [];
    }

    public function count(): int
    {
        return array_sum(array_map(\count(...), $this->rows));
    }

    private function bucket(string $class): string
    {
        return "{$this->connection}|{$this->tenant}|{$class}";
    }
}
