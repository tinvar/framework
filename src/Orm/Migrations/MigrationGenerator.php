<?php

declare(strict_types=1);

namespace Tinvar\Orm\Migrations;

use Tinvar\Attributes\Column as ColumnAttribute;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\RelationKind;
use Tinvar\Orm\Schema\Column;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Index;

/**
 * What `make:migration` writes: "diffs compiled metadata against the live
 * database and writes a migration file to review and commit"
 * (tinvar-spec.md, "Migrations").
 *
 * The models say what should be there -- typed properties are the schema,
 * so a property's type and nullability are the column's -- and the
 * database what is. The migration adds what is missing: tables, columns,
 * the foreign keys relations read by and the indexes they load by, and on
 * Postgres the tenant policy of a #[TenantScoped] table. It never drops:
 * a column the database has and no model maps is named in a comment, for
 * whoever reviews the file to decide. Nor does it change a column's type or
 * nullability in place; what it cannot see it does not guess at.
 *
 * Builder calls, not SQL, so one migration runs on every dialect: written
 * against the SQLite of a laptop, it runs against production's Postgres.
 */
final class MigrationGenerator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
    ) {}

    /**
     * The migration that brings the database to the models, or null when
     * it is there already.
     *
     * @param list<class-string<Model>> $models
     */
    public function generate(array $models, string $date): ?string
    {
        $wanted = $this->wanted($models);
        $up = [];
        $down = [];
        $changed = false;

        foreach ($this->inDependencyOrder($wanted) as $name => $table) {
            if (!$this->dialect->hasTable($this->connection, $name)) {
                $up[] = $this->renderCreate($name, $table);
                array_unshift($down, "        \$schema->drop({$this->php($name)});");
                $changed = true;
                continue;
            }

            [$changes, $undo] = $this->changes($name, $table);
            $up = [...$up, ...$changes];
            $down = [...$undo, ...$down];
            $changed = $changed || $undo !== [];
        }

        // Comments alone -- a column no model maps -- are not a migration.
        if (!$changed) {
            return null;
        }

        return self::source(
            "Written by `tinvar make:migration` from the difference between the models and the {$this->dialect->name()} "
            . "database on {$date}. Review it before committing it: it adds what the models have and the database lacks, "
            . 'and drops nothing.',
            implode("\n\n", $up),
            implode("\n", $down),
        );
    }

    /** A migration with nothing in it yet: for a backfill, or what the models cannot say. */
    public static function blank(): string
    {
        return self::source(
            'Written by hand: a data backfill, or a change the models cannot express.',
            '        //',
            '        //',
        );
    }

    private static function source(string $about, string $up, string $down): string
    {
        $wrapped = wordwrap($about, 74, "\n * ", true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Tinvar\\Orm\\Migrations\\Migration;
            use Tinvar\\Orm\\Schema\\Schema;
            use Tinvar\\Orm\\Schema\\Table;

            /**
             * {$wrapped}
             */
            return new class extends Migration {
                public function up(Schema \$schema): void
                {
            {$up}
                }

                public function down(Schema \$schema): void
                {
            {$down}
                }
            };

            PHP;
    }

    /**
     * What the models describe, by table.
     *
     * @param list<class-string<Model>> $models
     * @return array<string, array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null}>
     */
    private function wanted(array $models): array
    {
        $tables = [];
        foreach ($models as $model) {
            $meta = Metadata::for($model);
            $table = $tables[$meta->table] ?? ['columns' => [], 'indexes' => [], 'tenant' => null];

            foreach ($meta->columns as $property => $column) {
                $table['columns'][$column] = $property === $meta->idProperty
                    ? ['type' => 'uuid', 'nullable' => false, 'primary' => true]
                    : self::columnOf(new \ReflectionProperty($model, $property));
            }

            foreach ($meta->relations as $property => $relation) {
                if ($relation->kind !== RelationKind::BelongsTo) {
                    continue;
                }
                $target = Metadata::for($relation->target);
                $table['columns'][$relation->foreignKey] ??= [
                    'type' => 'uuid',
                    'nullable' => new \ReflectionProperty($model, $property)->getType()?->allowsNull() ?? false,
                    'references' => [$target->table, $target->idColumn],
                ];
                $table['indexes'][] = [$relation->foreignKey];
            }

            if ($meta->tenantColumn !== null) {
                $table['tenant'] = $meta->tenantColumn;
                unset($table['columns'][$meta->tenantColumn]);
            }
            $tables[$meta->table] = $table;
        }

        // A has-many's key is on the other model's table, which may have no
        // relation back to say so; the key is made there, with the index its
        // loads use -- `WHERE key IN (...) ORDER BY orderBy`.
        foreach ($models as $model) {
            $meta = Metadata::for($model);
            foreach ($meta->relations as $relation) {
                $target = Metadata::for($relation->target);
                if ($relation->kind !== RelationKind::HasMany || !isset($tables[$target->table])) {
                    continue;
                }
                $tables[$target->table]['columns'][$relation->foreignKey] ??= [
                    'type' => 'uuid',
                    'nullable' => false,
                    'references' => [$meta->table, $meta->idColumn],
                ];
                $tables[$target->table]['indexes'][] = array_values(array_filter([$relation->foreignKey, $relation->orderBy]));
            }
        }

        foreach ($tables as $name => $table) {
            $tables[$name]['indexes'] = self::withoutCoveredIndexes($table['indexes']);
        }

        return $tables;
    }

    /**
     * A column from a property: its type and nullability are the column's,
     * and a default it declares the column's default. #[Column(type:)]
     * names a storage type where PHP's says too little -- jsonb for a
     * value object.
     *
     * @return array<string, mixed>
     */
    private static function columnOf(\ReflectionProperty $property): array
    {
        $type = $property->getType();
        $declared = $property->getAttributes(ColumnAttribute::class)[0] ?? null;
        $storage = $declared?->newInstance()->type;

        $builderType = $storage !== null ? self::storageType($storage) : match ($type instanceof \ReflectionNamedType ? $type->getName() : 'mixed') {
            'int' => 'integer',
            'float' => 'float',
            'bool' => 'boolean',
            'array' => 'json',
            \DateTimeImmutable::class => 'timestamp',
            'string' => 'string',
            default => 'text',
        };

        $column = ['type' => $builderType, 'nullable' => $type?->allowsNull() ?? true];
        if ($property->hasDefaultValue()) {
            $default = $property->getDefaultValue();
            if (\is_int($default) || \is_float($default) || \is_string($default) || \is_bool($default)) {
                $column['default'] = $default;
            }
        }

        return $column;
    }

    /** The builder's type for a #[Column(type:)], or the type itself, written as the database's own. */
    private static function storageType(string $type): string
    {
        return match (strtolower($type)) {
            'json', 'jsonb' => 'json',
            'uuid' => 'uuid',
            'text' => 'text',
            'string', 'varchar' => 'string',
            'int', 'integer' => 'integer',
            'bigint', 'biginteger' => 'bigInteger',
            'bool', 'boolean' => 'boolean',
            'float', 'double', 'real' => 'float',
            'decimal', 'numeric' => 'decimal',
            'timestamp', 'timestamptz', 'datetime' => 'timestamp',
            'date' => 'date',
            'binary', 'blob', 'bytea' => 'binary',
            default => $type,
        };
    }

    /**
     * The statements adding what an existing table lacks, and their undoing.
     *
     * @param array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null} $table
     * @return array{list<string>, list<string>}
     */
    private function changes(string $name, array $table): array
    {
        $live = $this->dialect->columns($this->connection, $name);
        $liveIndexes = array_column($this->dialect->indexes($this->connection, $name), 'columns');

        $lines = [];
        $undoColumns = [];
        $undoIndexes = [];
        $comments = [];

        foreach ($table['columns'] as $column => $spec) {
            if (isset($live[$column])) {
                continue;
            }
            if (($spec['nullable'] ?? false) !== true && !\array_key_exists('default', $spec)) {
                $comments[] = "{$name}.{$column} is NOT NULL with no default: the rows already there need one (SQLite insists even when there are none), so give it a default() or add it nullable and backfill.";
            }
            if (($spec['references'] ?? null) !== null && $this->dialect->name() === 'sqlite') {
                $comments[] = "SQLite will not drop {$name}.{$column} while a foreign key is declared on it, so on SQLite down() needs the table rebuilt.";
            }
            $lines[] = '            ' . $this->renderColumn($column, $spec) . ';';
            $undoColumns[] = $column;
        }

        $tenant = $table['tenant'];
        $policy = null;
        if ($tenant !== null && !isset($live[$tenant])) {
            $comments[] = "{$name}.{$tenant} is NOT NULL with no default: the rows already there need their tenant, so give it a default() or backfill it before this runs.";
            $lines[] = "            \$table->tenant({$this->php($tenant)});";
            $undoIndexes[] = Index::of($name, [$tenant], false, null)->name;
            $undoColumns[] = $tenant;
            $liveIndexes[] = [$tenant];
        } elseif ($tenant !== null && !$this->dialect->hasTenantPolicy($this->connection, $name)) {
            $policy = $tenant;
        }

        foreach ($table['indexes'] as $columns) {
            if (self::covered($columns, $liveIndexes)) {
                continue;
            }
            $lines[] = "            \$table->index({$this->phpList($columns)});";
            $undoIndexes[] = Index::of($name, $columns, false, null)->name;
        }

        foreach (array_keys($live) as $column) {
            if (!isset($table['columns'][$column]) && $column !== $tenant) {
                $comments[] = "{$name}.{$column} is in the database and on no model. Dropping it is yours to decide: \$table->dropColumn({$this->php($column)}).";
            }
        }

        // Each comment above the change it is about, or alone where there
        // is none.
        $up = array_map(self::comment(...), $comments);
        if ($lines !== []) {
            $up[] = "        \$schema->table({$this->php($name)}, function (Table \$table): void {\n" . implode("\n", $lines) . "\n        });";
        }
        if ($policy !== null) {
            $up[] = "        \$schema->tenantPolicy({$this->php($name)}, {$this->php($policy)});";
        }
        $up = $up === [] ? [] : [implode("\n", $up)];

        $down = [];
        if ($policy !== null) {
            $down[] = "        \$schema->dropTenantPolicy({$this->php($name)});";
        }
        if ($undoColumns !== [] || $undoIndexes !== []) {
            $undo = [];
            foreach ($undoIndexes as $index) {
                $undo[] = "            \$table->dropIndex({$this->php($index)});";
            }
            if ($undoColumns !== []) {
                $undo[] = '            $table->dropColumn(' . implode(', ', array_map($this->php(...), $undoColumns)) . ');';
            }
            $down[] = "        \$schema->table({$this->php($name)}, function (Table \$table): void {\n" . implode("\n", $undo) . "\n        });";
        }

        return [$up, $down];
    }

    /** @param array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null} $table */
    private function renderCreate(string $name, array $table): string
    {
        $lines = [];
        foreach ($table['columns'] as $column => $spec) {
            $lines[] = '            ' . $this->renderColumn($column, $spec) . ';';
        }
        if ($table['tenant'] !== null) {
            $lines[] = "            \$table->tenant({$this->php($table['tenant'])});";
        }
        foreach ($table['indexes'] as $columns) {
            $lines[] = "            \$table->index({$this->phpList($columns)});";
        }

        return "        \$schema->create({$this->php($name)}, function (Table \$table): void {\n" . implode("\n", $lines) . "\n        });";
    }

    /** @param array<string, mixed> $spec */
    private function renderColumn(string $column, array $spec): string
    {
        $type = \is_string($spec['type'] ?? null) ? $spec['type'] : 'text';
        $call = \in_array($type, Column::TYPES, true)
            ? "\$table->{$type}({$this->php($column)})"
            : "\$table->column({$this->php($column)}, {$this->php($type)})";

        if (($spec['primary'] ?? false) === true) {
            $call .= '->primary()';
        }
        if (($spec['nullable'] ?? false) === true) {
            $call .= '->nullable()';
        }
        if (\array_key_exists('default', $spec)) {
            $call .= '->default(' . var_export($spec['default'], true) . ')';
        }
        $references = $spec['references'] ?? null;
        if (\is_array($references) && \is_string($references[0] ?? null) && \is_string($references[1] ?? null)) {
            $call .= $references[1] === 'id'
                ? "->references({$this->php($references[0])})"
                : "->references({$this->php($references[0])}, {$this->php($references[1])})";
        }

        return $call;
    }

    /**
     * Tables made before the tables that reference them, since Postgres and
     * MySQL check a foreign key's table exists when the key is made. A cycle
     * keeps the models' order, and fails there for whoever reviews it.
     *
     * @template T of array{columns: array<string, array<string, mixed>>, indexes: list<list<string>>, tenant: string|null}
     * @param array<string, T> $tables
     * @return array<string, T>
     */
    private function inDependencyOrder(array $tables): array
    {
        $ordered = [];
        $visiting = [];
        $visit = static function (string $name) use (&$visit, &$ordered, &$visiting, $tables): void {
            if (isset($ordered[$name]) || isset($visiting[$name])) {
                return;
            }
            $visiting[$name] = true;
            foreach ($tables[$name]['columns'] as $spec) {
                $references = $spec['references'] ?? null;
                if (\is_array($references) && \is_string($references[0] ?? null) && isset($tables[$references[0]]) && $references[0] !== $name) {
                    $visit($references[0]);
                }
            }
            $ordered[$name] = $tables[$name];
        };
        foreach (array_keys($tables) as $name) {
            $visit($name);
        }

        return $ordered;
    }

    /**
     * An index over the leading columns of another does the other's work
     * too, so only the longer is kept: a relation's (order_id) beside its
     * loads' (order_id, position).
     *
     * @param list<list<string>> $indexes
     * @return list<list<string>>
     */
    private static function withoutCoveredIndexes(array $indexes): array
    {
        $kept = [];
        foreach ($indexes as $i => $columns) {
            $others = $indexes;
            unset($others[$i]);
            $longer = array_filter($others, static fn (array $other): bool => \count($other) > \count($columns));
            if (!self::covered($columns, array_values($longer)) && !\in_array($columns, $kept, true)) {
                $kept[] = $columns;
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $indexes
     */
    private static function covered(array $columns, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (\array_slice($index, 0, \count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    private static function comment(string $text): string
    {
        return '        // ' . wordwrap($text, 90, "\n        // ", true);
    }

    private function php(string $value): string
    {
        return var_export($value, true);
    }

    /** @param list<string> $values */
    private function phpList(array $values): string
    {
        return '[' . implode(', ', array_map($this->php(...), $values)) . ']';
    }
}
