<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\RowLevelSecurity;

/**
 * Postgres: the dialect the spec names first, and the one with row-level
 * security, which a tenant column turns on (see Table::tenant()).
 *
 * Schema changes are transactional, so a migration that fails leaves
 * nothing behind. Names resolve on the search path, as the application's
 * own queries do, so a schema-per-test or schema-per-tenant setup migrates
 * the schema it is pointed at.
 */
final class PostgresDialect extends Dialect
{
    /**
     * The advisory lock's first key, 'tinv' as an int4; the second is the
     * current schema's hash, so migrations of two schemas in one database
     * do not wait on each other.
     */
    public const int LOCK_KEY = 0x74696E76;

    public function name(): string
    {
        return 'pgsql';
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    protected function quoteOne(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function type(Column $column): string
    {
        if ($column->caseInsensitive) {
            return 'citext';
        }

        return match ($column->type) {
            'uuid' => 'uuid',
            'string' => 'varchar(' . ($column->length ?? 255) . ')',
            'text' => 'text',
            'integer' => 'integer',
            'bigInteger' => 'bigint',
            'boolean' => 'boolean',
            'float' => 'double precision',
            'decimal' => 'numeric(' . ($column->length ?? 10) . ', ' . ($column->scale ?? 2) . ')',
            'json' => 'jsonb',
            'timestamp' => 'timestamptz',
            'date' => 'date',
            'binary' => 'bytea',
            default => $column->type,
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    /**
     * citext is a contrib extension, trusted since Postgres 13, so a role
     * that may create in the database can install it; it is installed on
     * the search path's first schema, the one the table is made in.
     */
    protected function beforeColumns(Table $table): array
    {
        foreach ($table->columns as $column) {
            if ($column->caseInsensitive) {
                return ['CREATE EXTENSION IF NOT EXISTS citext'];
            }
        }

        return [];
    }

    /**
     * The tenant policy for a tenant column added here: the column is a
     * varchar, which compares with the setting cast to text and keeps its
     * index.
     */
    protected function afterColumns(Table $table): array
    {
        return $table->tenantColumn === null ? [] : RowLevelSecurity::policy($table->name, $table->tenantColumn, 'text');
    }

    public function dropIndex(string $table, string $index): string
    {
        // An index lives in its table's schema.
        $dot = strrpos($table, '.');

        return 'DROP INDEX ' . $this->quote(($dot === false ? '' : substr($table, 0, $dot + 1)) . $index);
    }

    /**
     * The policy for a column already there, cast to the column's own type
     * -- a uuid to uuid, text and varchar to text -- so the comparison can
     * use its index rather than casting every row.
     */
    public function tenantPolicy(Connection $connection, string $table, string $column): array
    {
        $type = $connection->select(
            'SELECT format_type(a.atttypid, a.atttypmod) AS type FROM pg_attribute a '
            . 'WHERE a.attrelid = to_regclass(?) AND a.attname = ? AND a.attnum > 0 AND NOT a.attisdropped',
            [$this->quote($table), $column],
        )[0]['type'] ?? null;
        if (!\is_string($type)) {
            throw new \InvalidArgumentException("Table '{$table}' has no column '{$column}' to hold to a tenant policy.");
        }

        $cast = match (true) {
            str_starts_with($type, 'character'), $type === 'text', $type === 'citext' => 'text',
            default => (string) preg_replace('/\(.*\z/', '', $type),
        };

        return RowLevelSecurity::policy($table, $column, $cast);
    }

    public function dropTenantPolicy(string $table): array
    {
        $name = $this->quote($table);

        return [
            'DROP POLICY IF EXISTS ' . RowLevelSecurity::POLICY . " ON {$name}",
            "ALTER TABLE {$name} NO FORCE ROW LEVEL SECURITY",
            "ALTER TABLE {$name} DISABLE ROW LEVEL SECURITY",
        ];
    }

    public function hasTenantPolicy(Connection $connection, string $table): bool
    {
        $row = $connection->select(
            'SELECT c.relrowsecurity AND c.relforcerowsecurity AND EXISTS ('
            . 'SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = ?) AS held '
            . 'FROM pg_class c WHERE c.oid = to_regclass(?)',
            [RowLevelSecurity::POLICY, $this->quote($table)],
        )[0] ?? null;

        return $row !== null && ($row['held'] ?? false) === true;
    }

    /**
     * Lifted for the transaction alone where there is one, so a rollback
     * or commit puts it back whatever $work did; otherwise for the session,
     * and put back after. Postgres would take the setting back with an
     * aborted transaction anyway, so a restore that fails there is let go.
     */
    public function acrossTenants(Connection $connection, \Closure $work): mixed
    {
        $local = $connection->inTransaction();
        $previous = $connection->select('SELECT current_setting(?, true) AS v', [RowLevelSecurity::ACROSS_SETTING])[0]['v'] ?? null;
        $connection->select('SELECT set_config(?, ?, ?)', [RowLevelSecurity::ACROSS_SETTING, 'on', $local ? 'true' : 'false']);

        try {
            return $work();
        } finally {
            try {
                $connection->select('SELECT set_config(?, ?, ?)', [
                    RowLevelSecurity::ACROSS_SETTING,
                    \is_string($previous) && $previous !== '' ? $previous : 'off',
                    $local ? 'true' : 'false',
                ]);
            } catch (\PDOException) {
            }
        }
    }

    public function tables(Connection $connection): array
    {
        return array_map(
            static fn (array $row): string => self::text($row, 'name'),
            $connection->select(
                "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name",
            ),
        );
    }

    public function hasTable(Connection $connection, string $table): bool
    {
        return ($connection->select('SELECT to_regclass(?) IS NOT NULL AS found', [$this->quote($table)])[0]['found'] ?? false) === true;
    }

    public function columns(Connection $connection, string $table): array
    {
        $columns = [];
        foreach ($connection->select(
            'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS notnull FROM pg_attribute a '
            . 'WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
            [$this->quote($table)],
        ) as $row) {
            $columns[self::text($row, 'name')] = ['type' => self::text($row, 'type'), 'nullable' => ($row['notnull'] ?? false) !== true];
        }

        return $columns;
    }

    public function indexes(Connection $connection, string $table): array
    {
        $indexes = [];
        foreach ($connection->select(
            'SELECT c.relname AS name, ix.indisunique AS is_unique, ('
            . 'SELECT string_agg(a.attname, \',\' ORDER BY k.ord) FROM unnest(ix.indkey) WITH ORDINALITY k(attnum, ord) '
            . 'JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = k.attnum) AS columns '
            . 'FROM pg_index ix JOIN pg_class c ON c.oid = ix.indexrelid WHERE ix.indrelid = to_regclass(?) ORDER BY c.relname',
            [$this->quote($table)],
        ) as $row) {
            $columns = self::text($row, 'columns');
            $indexes[] = [
                'name' => self::text($row, 'name'),
                'columns' => $columns === '' ? [] : explode(',', $columns),
                'unique' => ($row['is_unique'] ?? false) === true,
            ];
        }

        return $indexes;
    }

    /**
     * A session-level advisory lock, which Postgres lets go when the
     * session ends, however it ends. Tried rather than waited on, so the
     * wait has a timeout and the deploy that gives up says why.
     */
    public function lock(Connection $connection, float $timeout): void
    {
        $taken = self::waitFor(
            static fn (): bool => ($connection->select(
                'SELECT pg_try_advisory_lock(?, hashtext(coalesce(current_schema(), \'\'))) AS taken',
                [self::LOCK_KEY],
            )[0]['taken'] ?? false) === true,
            $timeout,
        );

        if (!$taken) {
            throw MigrationRefused::locked($timeout, 'the Postgres advisory lock');
        }
    }

    public function unlock(Connection $connection): void
    {
        $connection->select('SELECT pg_advisory_unlock(?, hashtext(coalesce(current_schema(), \'\')))', [self::LOCK_KEY]);
    }

    /**
     * Every table in the current schema, at once and with CASCADE, so the
     * foreign keys and views between them go too. Other schemas -- another
     * application's, or the extensions' -- are not touched.
     */
    public function dropAllTables(Connection $connection): array
    {
        $tables = $this->tables($connection);
        if ($tables !== []) {
            $connection->execute('DROP TABLE IF EXISTS ' . implode(', ', array_map($this->quoteOne(...), $tables)) . ' CASCADE');
        }

        return $tables;
    }
}
