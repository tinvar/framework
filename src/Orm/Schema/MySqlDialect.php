<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;

/**
 * MySQL 8, as a dialect layer under the same builder.
 *
 * MySQL commits every schema change as it runs, so a migration here is not
 * one transaction: one that fails part way leaves the changes before the
 * failure in place, and MigrationFailed lists them. Tables default to a
 * binary collation, so text compares by case as it does on SQLite and
 * Postgres; caseInsensitive() asks for the other. Indexes are declared
 * inside CREATE TABLE, which is how CREATE TABLE IF NOT EXISTS covers them:
 * MySQL has no CREATE INDEX IF NOT EXISTS.
 */
final class MySqlDialect extends Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function transactionalDdl(): bool
    {
        return false;
    }

    protected function quoteOne(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function type(Column $column): string
    {
        return match ($column->type) {
            'uuid' => 'CHAR(36)',
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'TEXT',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'float' => 'DOUBLE',
            'decimal' => 'DECIMAL(' . ($column->length ?? 10) . ', ' . ($column->scale ?? 2) . ')',
            'json' => 'JSON',
            'timestamp' => 'DATETIME(6)',
            'date' => 'DATE',
            'binary' => 'LONGBLOB',
            default => $column->type,
        };
    }

    protected function caseInsensitiveCollation(): string
    {
        return ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    protected function tableOptions(): string
    {
        return ' ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin';
    }

    /** Backslashes too: MySQL reads one in a string literal as an escape unless told not to. */
    protected function stringLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    protected function indexesInTable(Table $table): array
    {
        $lines = [];
        foreach ($table->indexes as $index) {
            $columns = [];
            foreach ($index->columns as [$column, $direction]) {
                $columns[] = $this->quote($column) . ($direction === '' ? '' : " {$direction}");
            }
            $lines[] = \sprintf('%sINDEX %s (%s)', $index->unique ? 'UNIQUE ' : '', $this->quote($index->name), implode(', ', $columns));
        }

        return $lines;
    }

    protected function indexesAfterTable(Table $table): array
    {
        return [];
    }

    public function createIndex(Index $index, bool $ifNotExists): string
    {
        // CREATE TABLE IF NOT EXISTS declares its indexes inline; an index
        // added to a table later has no IF NOT EXISTS to ask for.
        return parent::createIndex($index, false);
    }

    public function dropIndex(string $table, string $index): string
    {
        return "DROP INDEX {$this->quote($index)} ON {$this->quote($table)}";
    }

    /** A column's REFERENCES is ignored here, so a foreign key added later is a constraint of its own. */
    protected function addColumn(string $table, Column $column): array
    {
        $statements = ["ALTER TABLE {$this->quote($table)} ADD COLUMN {$this->columnDefinition($column, inlineReference: false, inlineUnique: false)}"];
        if ($column->references !== null) {
            $statements[] = "ALTER TABLE {$this->quote($table)} ADD " . $this->foreignKey($table, $column);
        }

        return $statements;
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select(
                "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name",
            ),
        );
    }

    public function hasTable(Connection $connection, string $table): bool
    {
        return $connection->select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        ) !== [];
    }

    public function columns(Connection $connection, string $table): array
    {
        $columns = [];
        foreach ($connection->select(
            'SELECT column_name AS name, column_type AS type, is_nullable AS nullable FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        ) as $row) {
            $columns[self::text($row, 'name')] = ['type' => self::text($row, 'type'), 'nullable' => self::text($row, 'nullable') === 'YES'];
        }

        return $columns;
    }

    public function indexes(Connection $connection, string $table): array
    {
        $indexes = [];
        foreach ($connection->select(
            'SELECT index_name AS name, non_unique AS non_unique, column_name AS col FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index',
            [$table],
        ) as $row) {
            $name = self::text($row, 'name');
            $indexes[$name] ??= ['name' => $name, 'columns' => [], 'unique' => self::text($row, 'non_unique') === '0'];
            $indexes[$name]['columns'][] = self::text($row, 'col');
        }

        return array_values($indexes);
    }

    /**
     * GET_LOCK, which MySQL lets go when the session ends. Named for the
     * database, so two databases on one server migrate side by side; a
     * hash, since a lock's name is capped at 64 characters.
     */
    public function lock(Connection $connection, float $timeout): void
    {
        $taken = $connection->select(
            "SELECT GET_LOCK(CONCAT('tinvar_migrations:', SHA1(COALESCE(DATABASE(), ''))), ?) AS taken",
            [(int) ceil(max(0.0, $timeout))],
        )[0]['taken'] ?? null;

        if ((string) (\is_scalar($taken) ? $taken : '') !== '1') {
            throw MigrationRefused::locked($timeout, 'the MySQL named lock');
        }
    }

    public function unlock(Connection $connection): void
    {
        $connection->select("SELECT RELEASE_LOCK(CONCAT('tinvar_migrations:', SHA1(COALESCE(DATABASE(), '')))) AS released");
    }

    public function dropAllTables(Connection $connection): array
    {
        $tables = $this->tables($connection);
        $connection->execute('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $connection->execute('DROP TABLE IF EXISTS ' . $this->quoteOne($table));
            }
        } finally {
            $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $tables;
    }
}
