<?php

declare(strict_types=1);

namespace Tinvar\Staging;

/**
 * The tables and columns one database really has, read from the database
 * itself, for the staging import to hold its plan to: SQLite, Postgres
 * (the connection's current schema) and MySQL (its current database).
 *
 * Names the database gives that are not plain identifiers are refused
 * rather than quoted: every name the import writes into SQL has passed
 * ScrubPlan::identifier().
 *
 * @internal StagingImport's
 */
final class Schema
{
    private readonly string $driver;

    /** @var array<string, list<string>>|null */
    private ?array $tables = null;

    public function __construct(private readonly \PDO $pdo)
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!\in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new ImportRefused("The staging import reads SQLite, Postgres and MySQL; this connection is {$driver}.");
        }
        $this->driver = $driver;
    }

    /** The SQLite file this is, or null for one in memory or a server's database. */
    public function file(): ?string
    {
        if ($this->driver !== 'sqlite') {
            return null;
        }
        $statement = $this->pdo->query('PRAGMA database_list');
        foreach ($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if (($database['name'] ?? null) === 'main' && \is_string($database['file'] ?? null) && $database['file'] !== '') {
                return realpath($database['file']) ?: $database['file'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys($this->read());
    }

    public function has(string $table): bool
    {
        return isset($this->read()[$table]);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return $this->read()[$table] ?? [];
    }

    public function quote(string $identifier): string
    {
        if (!ScrubPlan::identifier($identifier)) {
            throw new ImportRefused("'{$identifier}' is not a name the staging import writes into SQL.");
        }

        return $this->driver === 'mysql' ? "`{$identifier}`" : "\"{$identifier}\"";
    }

    /**
     * The table's primary key columns, in key order; [] where it has none.
     *
     * @return list<string>
     */
    public function primaryKey(string $table): array
    {
        if ($this->driver === 'sqlite') {
            $statement = $this->pdo->query('PRAGMA table_info(' . $this->quote($table) . ')');
            $key = [];
            foreach ($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                if ((int) $column['pk'] > 0) {
                    $key[(int) $column['pk']] = (string) $column['name'];
                }
            }
            ksort($key);

            return array_values($key);
        }

        $statement = $this->pdo->prepare(
            'SELECT kcu.column_name FROM information_schema.table_constraints tc '
            . 'JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name '
            . 'AND kcu.table_schema = tc.table_schema AND kcu.table_name = tc.table_name '
            . "WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = ? AND tc.table_schema = "
            . ($this->driver === 'pgsql' ? 'current_schema()' : 'DATABASE()') . ' ORDER BY kcu.ordinal_position',
        );
        $statement->execute([$table]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * An INSERT of $columns that writes over the row with the same $key
     * rather than failing on it, in the database's own syntax.
     *
     * @param list<string> $columns
     * @param non-empty-list<string> $key
     */
    public function upsert(string $table, array $columns, array $key): string
    {
        $insert = 'INSERT INTO ' . $this->quote($table) . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        $rest = array_values(array_diff($columns, $key));

        if ($this->driver === 'mysql') {
            $set = $rest === []
                ? [$this->quote($key[0]) . ' = ' . $this->quote($key[0])]
                : array_map(fn (string $column): string => $this->quote($column) . ' = VALUES(' . $this->quote($column) . ')', $rest);

            return "{$insert} ON DUPLICATE KEY UPDATE " . implode(', ', $set);
        }

        $conflict = ' ON CONFLICT (' . implode(', ', array_map($this->quote(...), $key)) . ')';

        return $rest === []
            ? "{$insert}{$conflict} DO NOTHING"
            : "{$insert}{$conflict} DO UPDATE SET "
                . implode(', ', array_map(fn (string $column): string => $this->quote($column) . ' = excluded.' . $this->quote($column), $rest));
    }

    /** A transaction whose foreign keys are checked at its end, where the database lets them be. */
    public function begin(): void
    {
        $this->pdo->beginTransaction();
        match ($this->driver) {
            'sqlite' => $this->pdo->exec('PRAGMA defer_foreign_keys = ON'),
            'pgsql' => $this->pdo->exec('SET CONSTRAINTS ALL DEFERRED'),
            // MySQL checks each statement and defers nothing; the plan's
            // order is the order rows are written in.
            default => null,
        };
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string, list<string>> */
    private function read(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $names = match ($this->driver) {
            'sqlite' => $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name"),
            'pgsql' => $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name"),
            default => $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"),
        };

        $tables = [];
        foreach (($names === false ? [] : $names->fetchAll(\PDO::FETCH_COLUMN)) as $name) {
            $name = (string) $name;
            if (!ScrubPlan::identifier($name)) {
                throw new ImportRefused("The database has a table named '{$name}', which the staging import does not take.");
            }
            $tables[$name] = $this->columnsOf($name);
        }

        return $this->tables = $tables;
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        if ($this->driver === 'sqlite') {
            $statement = $this->pdo->query('PRAGMA table_info(' . $this->quote($table) . ')');
            $columns = array_values(array_map(static fn (array $row): string => (string) $row['name'], $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC)));
        } else {
            $statement = $this->pdo->prepare(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND table_schema = '
                . ($this->driver === 'pgsql' ? 'current_schema()' : 'DATABASE()') . ' ORDER BY ordinal_position',
            );
            $statement->execute([$table]);
            $columns = array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
        }

        foreach ($columns as $column) {
            if (!ScrubPlan::identifier($column)) {
                throw new ImportRefused("{$table} has a column named '{$column}', which the staging import does not take.");
            }
        }

        return $columns;
    }
}
