<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Compiled metadata for one model: table, columns, relations and a hydrator.
 *
 * Reflection happens once, here. Production never reflects
 * (tinvar-spec.md, "Engine layers").
 */
final class ModelMeta
{
    /**
     * @param class-string<Model>          $class
     * @param array<string, string>        $columns  property => column
     * @param array<string, RelationMeta>  $relations property => relation
     * @param \Closure(Model, array<string, mixed>): void $hydrator
     * @param \Closure(Model): string      $readKey
     * @param \Closure(Model, string, mixed): void $write
     * @param \Closure(Model, string): mixed      $read
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly string $idProperty,
        public readonly string $idColumn,
        public readonly array $columns,
        public readonly array $relations,
        public readonly ?string $tenantColumn,
        private readonly \Closure $hydrator,
        private readonly \Closure $readKey,
        private readonly \Closure $write,
        private readonly \Closure $read,
    ) {}

    /** @param array<string, mixed> $row */
    public function hydrate(Model $model, array $row): void
    {
        ($this->hydrator)($model, $row);
    }

    public function keyOf(Model $model): string
    {
        return ($this->readKey)($model);
    }

    /**
     * Declared columns plus the foreign keys single relations need.
     *
     * A #[BelongsTo] property is not itself a column, but the key it reads has
     * to come back in the row or the relation has nothing to resolve from.
     *
     * @return list<string>
     */
    public function selectColumns(): array
    {
        $columns = array_values($this->columns);

        foreach ($this->relations as $relation) {
            if ($relation->kind === RelationKind::BelongsTo && !\in_array($relation->foreignKey, $columns, true)) {
                $columns[] = $relation->foreignKey;
            }
        }

        return $columns;
    }

    /**
     * Writes a property from the model's own scope, so relation properties and
     * ORM-owned fields stay closed to everyone else.
     */
    public function set(Model $model, string $property, mixed $value): void
    {
        ($this->write)($model, $property, $value);
    }

    public function get(Model $model, string $property): mixed
    {
        return ($this->read)($model, $property);
    }

    public function relation(string $property): ?RelationMeta
    {
        return $this->relations[$property] ?? null;
    }
}
