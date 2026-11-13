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

    /**
     * What tells this database from every other one the same driver
     * reaches, as the server itself says it: for the import to refuse
     * production's own database handed to it as staging's over a second
     * connection, which the connection objects cannot tell apart.
     *
     * SQLite: the file's real path, or null in memory (two in-memory
     * databases are two). Postgres: the database, the schema, the port the
     * server listens on, and when the postmaster started -- the one thing
     * two servers never share. Never the address the connection reached it
     * at, which is 127.0.0.1 over TCP and nothing over a Unix socket: one
     * database reached both ways looked like two (ADR 0216). MySQL: the
     * database and the server's own uuid. Every part is what any role may
     * read.
     */
    public function identity(): ?string
    {
        if ($this->driver === 'sqlite') {
            $file = $this->file();

            return $file === null ? null : "sqlite\0{$file}";
        }

        $row = $this->pdo->query(match ($this->driver) {
            'pgsql' => "SELECT current_database() AS a, current_schema() AS b, current_setting('port') AS c, NULL AS d, pg_postmaster_start_time() AS e",
            default => 'SELECT DATABASE() AS a, @@server_uuid AS b, @@port AS c, NULL AS d, NULL AS e',
        });
        $parts = $row === false ? false : $row->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($parts)) {
            throw new ImportRefused('The database would not say which one it is, so the import cannot tell staging\'s from production\'s.');
        }

        return $this->driver . "\0" . implode("\0", array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '', $parts));
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

    /**
     * A read-only transaction over one snapshot of the database, for every
     * read the import makes of production: a row written while it runs is
     * in none of the tables, rather than in those read after the write.
     * Ended by endSnapshot(), which writes nothing.
     *
     * @return bool whether one was begun; false where the connection was in a transaction already
     */
    public function beginSnapshot(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }

        match ($this->driver) {
            // Deferred: the snapshot is taken by the first read, made here
            // at once. In WAL mode a writer goes on beside it; in a rollback
            // journal it waits for the import, as for any reader.
            'sqlite' => (function (): void {
                $this->pdo->beginTransaction();
                $this->pdo->exec('SELECT COUNT(*) FROM sqlite_master');
            })(),
            'pgsql' => (function (): void {
                $this->pdo->beginTransaction();
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            })(),
            default => (function (): void {
                // PDO's beginTransaction() names no snapshot: MySQL's own,
                // which PDO does not track, and endSnapshot() ends.
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            })(),
        };

        return true;
    }

    public function endSnapshot(): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->exec('ROLLBACK');

            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * The rows $select gives, one at a time, never the whole table held in
     * memory: SQLite steps through them as they are read; MySQL's are read
     * unbuffered; Postgres's -- whose driver otherwise holds the whole
     * result -- through a cursor, CURSOR_ROWS at a time, inside the
     * snapshot. $select is the import's own, from quoted names; $value, the
     * one parameter, is bound or quoted by the driver.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $select, ?string $where, ?string $value): \Generator
    {
        if ($this->driver === 'pgsql' && $this->pdo->inTransaction()) {
            $sql = $where === null ? $select : "{$select} WHERE {$where} = " . $this->pdo->quote((string) $value);
            $cursor = 'tinvar_import_' . bin2hex(random_bytes(4));
            $this->pdo->exec("DECLARE {$cursor} NO SCROLL CURSOR FOR {$sql}");
            try {
                do {
                    $batch = $this->pdo->query("FETCH " . self::CURSOR_ROWS . " FROM {$cursor}");
                    $rows = $batch === false ? [] : $batch->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        /** @var array<string, mixed> $row */
                        yield $row;
                    }
                } while (\count($rows) === self::CURSOR_ROWS);
            } finally {
                $this->pdo->exec("CLOSE {$cursor}");
            }

            return;
        }

        $buffered = null;
        if ($this->driver === 'mysql') {
            $buffered = $this->pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $read = $this->pdo->prepare($where === null ? $select : "{$select} WHERE {$where} = ?");
            $read->execute($where === null ? [] : [$value]);
            try {
                while (($row = $read->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    /** @var array<string, mixed> $row */
                    yield $row;
                }
            } finally {
                $read->closeCursor();
            }
        } finally {
            if ($buffered !== null) {
                $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }
    }

    /** Rows a Postgres cursor is read in at a time. */
    private const int CURSOR_ROWS = 1000;

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
