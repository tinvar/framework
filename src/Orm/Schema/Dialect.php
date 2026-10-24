<?php

declare(strict_types=1);

namespace Tinvar\Orm\Schema;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Migrations\MigrationRefused;
use Tinvar\Orm\PdoConnection;

/**
 * What one database calls the builder's types and statements, what it can
 * be asked about the schema it holds, and how migrations take turns on it.
 *
 * "Postgres first; MySQL as a dialect layer" (tinvar-spec.md, "Migrations").
 * The shop runs on SQLite, so SQLite is a dialect too. The statements are
 * built here and run by Schema, so each dialect's output can be read --
 * and tested -- without a server.
 */
abstract class Dialect
{
    /** The driver name PDO reports for this database: sqlite, pgsql or mysql. */
    abstract public function name(): string;

    /**
     * Whether a schema change made in a transaction rolls back with it.
     * Where it does, each migration runs in one and fails whole; MySQL
     * commits every schema change as it runs.
     */
    abstract public function transactionalDdl(): bool;

    abstract protected function quoteOne(string $identifier): string;

    /** The SQL type of a column of the builder's, or of the database's own type, as written. */
    abstract protected function type(Column $column): string;

    /** @return list<string> the table names in the database (the current schema, on Postgres) */
    abstract public function tables(Connection $connection): array;

    abstract public function hasTable(Connection $connection, string $table): bool;

    /** @return array<string, array{type: string, nullable: bool}> by column name, in table order */
    abstract public function columns(Connection $connection, string $table): array;

    /** @return list<array{name: string, columns: list<string>, unique: bool}> the primary key among them */
    abstract public function indexes(Connection $connection, string $table): array;

    /**
     * Waits up to $timeout seconds for the migration lock, so two deploys
     * starting together migrate one after the other: the second, once it
     * has the lock, finds nothing left to run.
     *
     * @throws MigrationRefused when another holds it past the timeout
     */
    abstract public function lock(Connection $connection, float $timeout): void;

    abstract public function unlock(Connection $connection): void;

    /** @return list<string> the tables dropped */
    abstract public function dropAllTables(Connection $connection): array;

    /** The dialect for a connection's driver. */
    public static function for(Connection $connection): self
    {
        $driver = $connection instanceof PdoConnection ? $connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) : null;

        return match ($driver) {
            'sqlite' => new SqliteDialect(),
            'pgsql' => new PostgresDialect(),
            'mysql' => new MySqlDialect(),
            default => throw new \InvalidArgumentException(\sprintf(
                'Migrations have no dialect for %s; pass one.',
                \is_string($driver) ? "the {$driver} driver" : $connection::class,
            )),
        };
    }

    /** A plain or schema-qualified name, checked and quoted. */
    public function quote(string $name): string
    {
        Identifier::assert($name, 'table or column');

        return implode('.', array_map($this->quoteOne(...), explode('.', $name)));
    }

    /**
     * @return list<string>
     */
    public function createTable(Table $table, bool $ifNotExists = false): array
    {
        $lines = [];
        foreach ($table->columns as $column) {
            $lines[] = $this->columnDefinition($column, inlineReference: false);
        }
        if ($table->primaryKey !== null) {
            $lines[] = 'PRIMARY KEY (' . $this->columnList($table->primaryKey) . ')';
        }

        // Table constraints rather than a REFERENCES on the column: MySQL
        // parses a column's REFERENCES and ignores it, and a schema whose
        // foreign keys hold on two dialects and not the third is worse than
        // one spelling all three keep.
        foreach ($table->columns as $column) {
            if ($column->references !== null) {
                $lines[] = $this->foreignKey($table->name, $column);
            }
        }
        foreach ($this->indexesInTable($table) as $index) {
            $lines[] = $index;
        }

        $statements = $this->beforeColumns($table);
        $statements[] = \sprintf(
            "CREATE TABLE %s%s (\n    %s\n)%s",
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quote($table->name),
            implode(",\n    ", $lines),
            $this->tableOptions(),
        );
        foreach ($this->indexesAfterTable($table) as $index) {
            $statements[] = $this->createIndex($index, $ifNotExists);
        }

        return [...$statements, ...$this->afterColumns($table)];
    }

    /**
     * The changes to an existing table, in an order that lets each one run:
     * renames first, so later changes can use the new names; indexes
     * dropped before their columns, which SQLite will not drop while an
     * index covers them; columns added before the indexes over them.
     *
     * @return list<string>
     */
    public function alterTable(Table $table): array
    {
        $statements = [];
        $name = $this->quote($table->name);

        foreach ($table->renamedColumns as $from => $to) {
            $statements[] = "ALTER TABLE {$name} RENAME COLUMN {$this->quote($from)} TO {$this->quote($to)}";
        }
        foreach ($table->droppedIndexes as $index) {
            $statements[] = $this->dropIndex($table->name, $index);
        }
        foreach ($table->droppedColumns as $column) {
            $statements[] = "ALTER TABLE {$name} DROP COLUMN {$this->quote($column)}";
        }

        $statements = [...$statements, ...$this->beforeColumns($table)];
        $indexes = $table->indexes;
        foreach ($table->columns as $column) {
            if ($column->primary) {
                throw new \LogicException("Column '{$column->name}': a primary key is declared when the table is created.");
            }

            // SQLite will not add a UNIQUE column, so every dialect gets the
            // same unique index instead, under the name create() gives one.
            if ($column->unique) {
                $indexes[] = Index::of($table->name, [$column->name], true, null);
            }
            foreach ($this->addColumn($table->name, $column) as $statement) {
                $statements[] = $statement;
            }
        }
        foreach ($indexes as $index) {
            $statements[] = $this->createIndex($index, false);
        }

        return [...$statements, ...$this->afterColumns($table)];
    }

    /**
     * An INSERT of one row that does nothing where the row's key is taken,
     * reporting one row affected only when it wrote: a claim -- of a nonce,
     * a scheduled minute -- that one of several workers wins.
     *
     * @param list<string> $columns
     */
    public function insertUnlessTaken(string $table, array $columns): string
    {
        return \sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT DO NOTHING',
            $this->quote($table),
            $this->columnList($columns),
            implode(', ', array_fill(0, \count($columns), '?')),
        );
    }

    /** @return list<string> */
    public function dropTable(string $table, bool $ifExists = false): array
    {
        return [\sprintf('DROP TABLE %s%s', $ifExists ? 'IF EXISTS ' : '', $this->quote($table))];
    }

    /** @return list<string> */
    public function renameTable(string $from, string $to): array
    {
        return ["ALTER TABLE {$this->quote($from)} RENAME TO {$this->quote($to)}"];
    }

    /**
     * Turns on the tenant policy for a table: nothing outside Postgres,
     * which alone has row-level security.
     *
     * @return list<string>
     */
    public function tenantPolicy(Connection $connection, string $table, string $column): array
    {
        return [];
    }

    /** @return list<string> */
    public function dropTenantPolicy(string $table): array
    {
        return [];
    }

    /**
     * Whether a table is held to the tenant policy; true where the
     * database has no row-level security to hold it to.
     */
    public function hasTenantPolicy(Connection $connection, string $table): bool
    {
        return true;
    }

    /**
     * Runs $work with the tenant policy lifted for this connection, as
     * acrossTenants() lifts it; a database without row-level security
     * has nothing to lift.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function acrossTenants(Connection $connection, \Closure $work): mixed
    {
        return $work();
    }

    public function createIndex(Index $index, bool $ifNotExists): string
    {
        $columns = [];
        foreach ($index->columns as [$column, $direction]) {
            $columns[] = $this->quote($column) . ($direction === '' ? '' : " {$direction}");
        }

        return \sprintf(
            'CREATE %sINDEX %s%s ON %s (%s)',
            $index->unique ? 'UNIQUE ' : '',
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quote($index->name),
            $this->quote($index->table),
            implode(', ', $columns),
        );
    }

    public function dropIndex(string $table, string $index): string
    {
        return 'DROP INDEX ' . $this->quote($index);
    }

    /**
     * Statements a table's columns need before it is made or changed:
     * Postgres's citext, for a case-insensitive column.
     *
     * @return list<string>
     */
    protected function beforeColumns(Table $table): array
    {
        return [];
    }

    /**
     * Statements after a table is made or changed: Postgres's tenant policy.
     *
     * @return list<string>
     */
    protected function afterColumns(Table $table): array
    {
        return [];
    }

    /** What follows the closing parenthesis of CREATE TABLE. */
    protected function tableOptions(): string
    {
        return '';
    }

    /**
     * Indexes declared inside CREATE TABLE. MySQL's, so that CREATE TABLE
     * IF NOT EXISTS covers them: it has no CREATE INDEX IF NOT EXISTS.
     *
     * @return list<string>
     */
    protected function indexesInTable(Table $table): array
    {
        return [];
    }

    /** @return list<Index> */
    protected function indexesAfterTable(Table $table): array
    {
        return $table->indexes;
    }

    /** @return list<string> */
    protected function addColumn(string $table, Column $column): array
    {
        return ["ALTER TABLE {$this->quote($table)} ADD COLUMN {$this->columnDefinition($column, inlineReference: true, inlineUnique: false)}"];
    }

    /**
     * @param bool $inlineUnique false where the column is added to a table that exists, whose
     *        uniqueness alterTable() makes an index of
     */
    protected function columnDefinition(Column $column, bool $inlineReference, bool $inlineUnique = true): string
    {
        $sql = $this->quote($column->name) . ' ' . $this->type($column);
        if ($column->caseInsensitive) {
            $sql .= $this->caseInsensitiveCollation();
        }
        if (!$column->nullable) {
            $sql .= ' NOT NULL';
        }
        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->literal($column->default);
        }
        if ($column->primary) {
            $sql .= ' PRIMARY KEY';
        }
        if ($column->unique && $inlineUnique) {
            $sql .= ' UNIQUE';
        }
        if ($column->check !== null) {
            $sql .= " CHECK ({$column->check})";
        }
        if ($inlineReference && $column->references !== null) {
            $sql .= ' ' . $this->referenceClause($column);
        }

        return $sql;
    }

    /** The collation clause after a case-insensitive column's type; Postgres changes the type instead. */
    protected function caseInsensitiveCollation(): string
    {
        return '';
    }

    protected function foreignKey(string $table, Column $column): string
    {
        return \sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) %s',
            $this->quote(self::bare($table) . "_{$column->name}_foreign"),
            $this->quote($column->name),
            $this->referenceClause($column),
        );
    }

    protected function referenceClause(Column $column): string
    {
        \assert($column->references !== null);
        [$table, $key] = $column->references;

        return \sprintf(
            'REFERENCES %s (%s)%s',
            $this->quote($table),
            $this->quote($key),
            $column->onDelete === null ? '' : " ON DELETE {$column->onDelete}",
        );
    }

    protected function literal(int|float|string|bool|Expression|null $value): string
    {
        return match (true) {
            $value instanceof Expression => $value->sql,
            $value === null => 'NULL',
            \is_bool($value) => $this->booleanLiteral($value),
            \is_int($value) => (string) $value,
            \is_float($value) => is_finite($value)
                ? var_export($value, true)
                : throw new \InvalidArgumentException('A default cannot be INF or NAN.'),
            default => $this->stringLiteral($value),
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function stringLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** @param list<string> $columns */
    protected function columnList(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    /** A table name without its schema. */
    protected static function bare(string $table): string
    {
        $dot = strrpos($table, '.');

        return $dot === false ? $table : substr($table, $dot + 1);
    }

    /**
     * Tries $attempt until it answers true or $timeout seconds pass.
     *
     * @param \Closure(): bool $attempt
     */
    protected static function waitFor(\Closure $attempt, float $timeout): bool
    {
        $deadline = microtime(true) + max(0.0, $timeout);
        while (!$attempt()) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(50_000);
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    protected static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
