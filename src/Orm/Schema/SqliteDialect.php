<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;

/**
 * SQLite: the shop's database, and every test's.
 *
 * Types are SQLite's affinities -- it stores what it is given -- so a uuid,
 * a timestamp and JSON are all TEXT, as the ORM writes them. Schema changes
 * are transactional, so a migration that fails leaves nothing behind.
 */
final class SqliteDialect extends Dialect
{
    /** @var array{resource, string}|null the lock file's handle and path, while this dialect holds it */
    private ?array $lock = null;

    public function name(): string
    {
        return 'sqlite';
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    protected function quoteOne(string $identifier): string
    {
        // Doubled, though a checked name has none: dropAllTables() quotes
        // whatever names the database lists.
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function type(Column $column): string
    {
        return match ($column->type) {
            'uuid', 'string', 'text', 'json', 'timestamp', 'date' => 'TEXT',
            'integer', 'bigInteger', 'boolean' => 'INTEGER',
            'float' => 'REAL',
            'decimal' => 'NUMERIC',
            'binary' => 'BLOB',
            default => $column->type,
        };
    }

    protected function caseInsensitiveCollation(): string
    {
        return ' COLLATE NOCASE';
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name"),
        );
    }

    public function hasTable(Connection $connection, string $table): bool
    {
        return $connection->select("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== [];
    }

    public function columns(Connection $connection, string $table): array
    {
        $columns = [];
        foreach ($connection->select('SELECT name, type, "notnull", pk FROM pragma_table_info(?) ORDER BY cid', [$table]) as $row) {
            $columns[self::text($row, 'name')] = [
                'type' => self::text($row, 'type'),
                // A primary key column SQLite lets hold NULL -- a quirk it
                // keeps for old schemas -- still never does from the ORM.
                'nullable' => self::text($row, 'notnull') === '0' && self::text($row, 'pk') === '0',
            ];
        }

        return $columns;
    }

    public function indexes(Connection $connection, string $table): array
    {
        $indexes = [];
        foreach ($connection->select('SELECT name, "unique" FROM pragma_index_list(?)', [$table]) as $row) {
            $name = self::text($row, 'name');
            $indexes[] = [
                'name' => $name,
                'columns' => array_map(
                    static fn (array $column): string => self::text($column, 'name'),
                    $connection->select('SELECT name FROM pragma_index_info(?) ORDER BY seqno', [$name]),
                ),
                'unique' => self::text($row, 'unique') === '1',
            ];
        }

        // An INTEGER PRIMARY KEY is the rowid, and has no index of its own
        // to list.
        $primary = [];
        foreach ($connection->select('SELECT name, pk FROM pragma_table_info(?) WHERE pk > 0 ORDER BY pk', [$table]) as $row) {
            $primary[] = self::text($row, 'name');
        }
        if ($primary !== [] && !\in_array($primary, array_column($indexes, 'columns'), true)) {
            $indexes[] = ['name' => 'PRIMARY', 'columns' => $primary, 'unique' => true];
        }

        return $indexes;
    }

    /**
     * A lock file beside the database, held with flock(): the operating
     * system lets it go when the process holding it ends, however it ends,
     * so a deploy killed mid-migration leaves no lock for the next one to
     * wait out. A row in a table would have to be cleared by hand.
     *
     * The file is removed as the lock is let go, so it is there only while
     * a migration runs. Which means a waiter can be granted the lock on a
     * file already unlinked, while a newcomer locks the new one at the same
     * path: so the lock counts only once the path still names the file it
     * is held on, and otherwise is tried again on the file there now.
     *
     * An in-memory database takes no lock: no other connection can see it.
     */
    public function lock(Connection $connection, float $timeout): void
    {
        $file = $this->databaseFile($connection);
        if ($file === '') {
            return;
        }

        $path = "{$file}-migrate.lock";
        $held = null;
        $taken = self::waitFor(static function () use ($path, &$held): bool {
            $held = self::tryLock($path);

            return $held !== null;
        }, $timeout);

        if (!$taken || $held === null) {
            throw MigrationRefused::locked($timeout, $path);
        }

        $this->lock = [$held, $path];
    }

    /**
     * One attempt at the lock file: its handle, locked and still the file
     * at $path, or null.
     *
     * @return resource|null
     */
    private static function tryLock(string $path): mixed
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Could not open the migration lock file {$path}.");
        }

        if (flock($handle, LOCK_EX | LOCK_NB)) {
            clearstatcache(true, $path);
            $onDisk = @stat($path);
            $held = fstat($handle);
            if ($onDisk !== false && $held !== false && $onDisk['ino'] === $held['ino']) {
                return $handle;
            }
        }

        fclose($handle);

        return null;
    }

    public function unlock(Connection $connection): void
    {
        if (\is_array($this->lock)) {
            [$handle, $path] = $this->lock;
            // Unlinked while still held, so no one can lock this file again
            // after it is let go and believe it current.
            @unlink($path);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->lock = null;
    }

    /**
     * Every table, with foreign keys off while they go -- SQLite refuses to
     * drop a table another still references -- and back as they were.
     * PRAGMA foreign_keys does nothing inside a transaction, so this runs
     * outside one.
     */
    public function dropAllTables(Connection $connection): array
    {
        $tables = $this->tables($connection);
        $enforced = self::text($connection->select('PRAGMA foreign_keys')[0] ?? [], 'foreign_keys') === '1';
        $connection->execute('PRAGMA foreign_keys = OFF');

        try {
            foreach ($tables as $table) {
                $connection->execute('DROP TABLE IF EXISTS ' . $this->quoteOne($table));
            }
        } finally {
            if ($enforced) {
                $connection->execute('PRAGMA foreign_keys = ON');
            }
        }

        return $tables;
    }

    /** The main database's file, or '' for one in memory. */
    private function databaseFile(Connection $connection): string
    {
        foreach ($connection->select('PRAGMA database_list') as $row) {
            if (self::text($row, 'name') === 'main') {
                return self::text($row, 'file');
            }
        }

        return '';
    }
}
