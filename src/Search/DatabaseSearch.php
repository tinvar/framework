<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;

/**
 * The index kept in the application's own database (ADR 0153): the
 * default, needing no service and no key. SqliteSearch or PostgresSearch,
 * as the connection's driver says, over the tables SearchTables makes.
 *
 * Every statement is written here, whole, with each value -- a key, a
 * tenant, a weight, the query built from SearchTerms -- bound, never
 * spliced in. Its writes are made where the rows were, after their commit:
 * one statement or two per row, nothing queued.
 */
abstract class DatabaseSearch implements SearchBackend
{
    /** The most values bound in one IN list: SQLite's lowest limit leaves room for the rest. */
    protected const int CHUNK = 500;

    public function __construct(protected readonly Connection $connection) {}

    /** The backend for $connection's database: SQLite or Postgres; MySQL is refused, naming what to use. */
    public static function for(Connection $connection): self
    {
        $driver = $connection instanceof NamesDriver ? $connection->driver() : 'sqlite';

        return match ($driver) {
            'sqlite' => new SqliteSearch($connection),
            'pgsql' => new PostgresSearch($connection),
            default => throw new \InvalidArgumentException(
                "Search keeps no index in a {$driver} database: set TINVAR_SEARCH_BACKEND=meilisearch and keep it in Meilisearch, or use SQLite or Postgres.",
            ),
        };
    }

    public function remote(): bool
    {
        return false;
    }

    /**
     * Runs $work, a missing index table said as what to do about it.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    protected function guarded(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'no such table') || str_contains($message, 'does not exist')) {
                throw new SearchFailed(
                    'The search index has no table here: add a migration that runs SearchTables::create($schema), then `tinvar migrate` and `tinvar search:reindex`.',
                    0,
                    $e,
                );
            }
            throw $e;
        }
    }

    /**
     * `AND tenant_id IN (?, ...)` for the tenants named, or nothing for
     * every tenant, with its bindings.
     *
     * @param list<string>|null $tenants
     * @return array{string, list<string>}
     */
    protected static function tenantPredicate(?array $tenants, string $column = 'tenant_id'): array
    {
        if ($tenants === null) {
            return ['', []];
        }
        if ($tenants === []) {
            // No tenant to read is nothing to read, never everything.
            return [' AND 1 = 0', []];
        }

        return [" AND {$column} IN (" . implode(', ', array_fill(0, \count($tenants), '?')) . ')', $tenants];
    }

    /** @param list<mixed> $values */
    protected static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, \count($values), '?'));
    }
}
