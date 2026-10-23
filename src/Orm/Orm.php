<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The ORM runtime for one request: a connection, an identity map, a relation-row
 * cache and, during a render, a batch scheduler.
 *
 * Relation loads are cached as raw rows rather than models so every consumer
 * hydrates through the identity map and ends up holding the same instances.
 */
final class Orm
{
    /** @var array<string, array<string, mixed>> relation key => group key => rows */
    private array $relationRows = [];

    private ?BatchScheduler $scheduler = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identityMap = new IdentityMap(),
    ) {}

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function identityMap(): IdentityMap
    {
        return $this->identityMap;
    }

    public function scheduler(): ?BatchScheduler
    {
        return $this->scheduler;
    }

    /** Installs a scheduler for the duration of one render, enabling Level 2. */
    public function beginRender(): BatchScheduler
    {
        return $this->scheduler = new BatchScheduler($this);
    }

    public function endRender(): void
    {
        $this->scheduler = null;
    }

    /** Reset at every request, job and tenant boundary. */
    public function flush(): void
    {
        $this->identityMap->flush();
        $this->relationRows = [];
        $this->scheduler = null;
    }

    /**
     * The only supported way to change tenant.
     *
     * The relation-row cache is keyed by relation and parent id, not by tenant,
     * so it has to be dropped here or rows loaded for one tenant would answer a
     * relation read for the next. Tenancy fails closed in this framework; a
     * cache that silently crosses the boundary would undo that.
     */
    public function switchTenant(string $tenant): void
    {
        if ($tenant === $this->identityMap->tenant()) {
            return;
        }

        $this->relationRows = [];
        $this->scheduler = null;
        $this->identityMap->switchTenant($tenant);
    }

    // ---------------------------------------------------------------- reading

    /** @param class-string<Model> $class */
    public function find(string $class, string $id): ?Model
    {
        if ($existing = $this->identityMap->get($class, $id)) {
            /** @var Model $existing */
            return $existing;
        }
        $meta = Metadata::for($class);

        return $this->whereIn($class, $meta->idColumn, [$id])[0] ?? null;
    }

    /**
     * @param class-string<Model> $class
     * @param list<mixed> $values
     * @return list<Model>
     */
    public function whereIn(string $class, string $column, array $values, ?string $orderBy = null): array
    {
        if ($values === []) {
            return [];
        }
        $meta = Metadata::for($class);
        $rows = $this->connection->select($this->selectSql($meta, $column, \count($values), $orderBy), array_values($values));

        return $this->hydrateAll($class, $rows);
    }

    /**
     * @param class-string<Model> $class
     * @param list<array<string, mixed>> $rows
     * @return list<Model>
     */
    public function hydrateAll(string $class, array $rows): array
    {
        $meta = Metadata::for($class);
        $resultSet = new ResultSet();
        $models = [];

        foreach ($rows as $row) {
            $id = (string) ($row[$meta->idColumn] ?? '');
            $model = $this->identityMap->get($class, $id);

            if ($model === null) {
                /** @var Model $model */
                $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                $meta->hydrate($model, $row);
                $model = $this->identityMap->remember($class, $id, $model);
                $model->attach($this, $resultSet);
                $this->installRelations($model, $meta, $row);
            }

            $resultSet->add($model);
            $models[] = $model;
        }

        return $models;
    }

    /** @param array<string, mixed> $row */
    private function installRelations(Model $model, ModelMeta $meta, array $row): void
    {
        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind === RelationKind::HasMany) {
                $meta->set($model, $property, Collection::lazy(
                    fn (): array => $this->resolveHasMany($relation, $model)
                ));
                continue;
            }

            $foreignKey = $row[$relation->foreignKey] ?? null;
            $model->rememberForeignKey($property, $foreignKey);
            if ($foreignKey === null) {
                continue;   // left uninitialized; a nullable relation reads as unset
            }

            $meta->set($model, $property, $this->singleRelationGhost($relation, $model, (string) $foreignKey));
        }
    }

    /**
     * A lazy ghost for a single relation, as the spec specifies. Its initializer
     * deliberately never suspends -- see ADR 0002 -- so it resolves through
     * Level 1: one whereIn across the parent's whole result set.
     */
    private function singleRelationGhost(RelationMeta $relation, Model $parent, string $foreignKey): Model
    {
        $target = $relation->target;
        $targetMeta = Metadata::for($target);

        /** @var Model $ghost */
        $ghost = (new \ReflectionClass($target))->newLazyGhost(
            function (Model $object) use ($relation, $parent, $foreignKey, $targetMeta): void {
                $row = $this->resolveBelongsToRow($relation, $parent, $foreignKey);
                if ($row === null) {
                    return;
                }
                $targetMeta->hydrate($object, $row);
                $object->attach($this, new ResultSet());
                $this->installRelations($object, $targetMeta, $row);
            }
        );

        // Registered before it initializes, so every parent pointing at this row
        // shares one instance and one initializer run.
        return $this->identityMap->remember($target, $foreignKey, $ghost);
    }

    // ------------------------------------------------------------- relations

    /** @return list<Model> */
    public function resolveHasMany(RelationMeta $relation, Model $parent): array
    {
        $key = $relation->key();
        $groupKey = $parent->primaryKey();

        if (!$this->hasRows($key, $groupKey)) {
            // Inside a render, hand the load to the scheduler so it joins every
            // other relation wanted in this tick. Outside one, fall back to
            // Level 1 across the result set.
            if ($this->scheduler !== null && \Fiber::getCurrent() !== null) {
                $this->scheduler->await($relation, $parent, $groupKey);
            } else {
                $this->loadRelationRows($relation, $parent->resultSet()?->all() ?? [$parent]);
            }
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->relationRows[$key][$groupKey] ?? [];

        return $this->hydrateAll($relation->target, $rows);
    }

    /** @return array<string, mixed>|null */
    public function resolveBelongsToRow(RelationMeta $relation, Model $parent, string $foreignKey): ?array
    {
        $key = $relation->key();

        if (!$this->hasRows($key, $foreignKey)) {
            $this->loadRelationRows($relation, $parent->resultSet()?->all() ?? [$parent]);
        }

        /** @var array<string, mixed>|null */
        return $this->relationRows[$key][$foreignKey] ?? null;
    }

    /**
     * One statement for every parent handed in. This is the whole point of the
     * batching: the group may span result sets and query calls.
     *
     * @param list<Model> $parents
     */
    public function loadRelationRows(RelationMeta $relation, array $parents): void
    {
        $key = $relation->key();
        $groupKeys = [];

        foreach ($parents as $parent) {
            $groupKey = $relation->kind === RelationKind::HasMany
                ? $parent->primaryKey()
                : (string) ($parent->foreignKey($relation->property) ?? '');

            if ($groupKey !== '' && !$this->hasRows($key, $groupKey)) {
                $groupKeys[$groupKey] = true;
            }
        }

        if ($groupKeys === []) {
            return;
        }

        $ids = array_keys($groupKeys);
        $targetMeta = Metadata::for($relation->target);
        $childColumn = $relation->childColumn();

        $rows = $this->connection->select(
            // The join column has to come back even when the child does not
            // declare it: it is what the rows are grouped by.
            $this->selectSql($targetMeta, $childColumn, \count($ids), $relation->orderBy, [$childColumn]),
            $ids,
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) ($row[$childColumn] ?? '')][] = $row;
        }

        foreach ($ids as $id) {
            $this->relationRows[$key][$id] = $relation->kind === RelationKind::HasMany
                ? ($grouped[$id] ?? [])
                : ($grouped[$id][0] ?? null);
        }
    }

    public function hasRows(string $relationKey, string $groupKey): bool
    {
        return \array_key_exists($groupKey, $this->relationRows[$relationKey] ?? []);
    }

    // ------------------------------------------------------------------- sql

    /** @param list<string> $extraColumns */
    private function selectSql(
        ModelMeta $meta,
        string $column,
        int $count,
        ?string $orderBy,
        array $extraColumns = [],
    ): string {
        $select = $meta->selectColumns();
        foreach ($extraColumns as $extra) {
            if (!\in_array($extra, $select, true)) {
                $select[] = $extra;
            }
        }
        $columns = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $select));
        $placeholders = implode(', ', array_fill(0, $count, '?'));
        $sql = "SELECT {$columns} FROM \"{$meta->table}\" WHERE \"{$column}\" IN ({$placeholders})";

        if ($orderBy !== null) {
            $sql .= " ORDER BY \"{$orderBy}\"";
        }

        return $sql;
    }
}
