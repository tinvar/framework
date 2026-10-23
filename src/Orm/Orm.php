<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Tinvar\Support\Uuid;

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

    /**
     * Depth of acrossTenants() calls outside any fiber.
     *
     * Kept per fiber as well, below: "Context is fiber-local, so a suspended
     * fiber can't leak a switch to another." Inside a render the scheduler
     * interleaves fibers, so a single shared flag would lift scoping for every
     * other query running in the same tick.
     */
    private int $unscopedMain = 0;

    /** @var \WeakMap<\Fiber, int> */
    private \WeakMap $unscopedByFiber;

    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identityMap = new IdentityMap(),
    ) {
        $this->unscopedByFiber = new \WeakMap();
    }

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

    public function tenant(): string
    {
        return $this->identityMap->tenant();
    }

    /**
     * Runs $work as $tenant, restoring the previous context afterwards.
     *
     * "Scoped switching only ... take a closure and restore the previous
     * context afterwards." A bare setter would leak a switch into whatever ran
     * next on the same worker.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function actAs(string $tenant, \Closure $work): mixed
    {
        $previous = $this->identityMap->tenant();
        $previousUnscoped = $this->unscopedDepth();

        // An explicit switch wins over an enclosing acrossTenants(). Without
        // this, actAs() inside acrossTenants() would name a tenant and still
        // read every tenant's rows, which is the opposite of what it says.
        $this->setUnscopedDepth(0);
        $this->switchTenant($tenant);

        // Entering a scoped context from an unscoped one: drop anything the
        // unscoped read may have left reachable, in both directions.
        if ($previousUnscoped > 0) {
            $this->identityMap->flush();
            $this->relationRows = [];
        }

        try {
            return $work();
        } finally {
            $this->switchTenant($previous);
            $this->setUnscopedDepth($previousUnscoped);
            if ($previousUnscoped > 0) {
                $this->identityMap->flush();
                $this->relationRows = [];
            }
        }
    }

    /**
     * Lifts tenant scoping for the duration of $work.
     *
     * The escape hatch the spec requires to be explicit and flagged by the dev
     * toolbar. Nesting is counted so an inner call cannot re-enable scoping
     * for an outer one.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function acrossTenants(\Closure $work): mixed
    {
        $this->setUnscopedDepth($this->unscopedDepth() + 1);

        try {
            return $work();
        } finally {
            $this->setUnscopedDepth(max(0, $this->unscopedDepth() - 1));
            // The map is cleared on exit: rows read unscoped must not be
            // served to a later scoped read.
            $this->identityMap->flush();
            $this->relationRows = [];
        }
    }

    public function isUnscoped(): bool
    {
        return $this->unscopedDepth() > 0;
    }

    /** Scoping state for the fiber that is asking, or for the main context. */
    private function unscopedDepth(): int
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return $this->unscopedMain;
        }

        return $this->unscopedByFiber[$fiber] ?? 0;
    }

    private function setUnscopedDepth(int $depth): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $this->unscopedMain = $depth;

            return;
        }

        $this->unscopedByFiber[$fiber] = $depth;
    }

    /**
     * The tenant predicate for a model, and its binding.
     *
     * @return array{string, list<string>}
     */
    private function tenantScope(ModelMeta $meta): array
    {
        if ($meta->tenantColumn === null || $this->unscopedDepth() > 0) {
            return ['', []];
        }

        $tenant = $this->identityMap->tenant();
        if ($tenant === '') {
            throw new NoTenantContext($meta->class);
        }

        return ['"' . $meta->tenantColumn . '" = ?', [$tenant]];
    }

    // ---------------------------------------------------------------- reading

    /** @param class-string<Model> $class */
    public function find(string $class, string $id): ?Model
    {
        // The identity map is keyed by tenant but knows nothing of scoping, so
        // a row read inside acrossTenants() would otherwise answer a later
        // scoped read for the same id. Unscoped reads bypass the caches
        // entirely: an explicit escape hatch can afford to be uncached.
        if (!$this->isUnscoped() && ($existing = $this->identityMap->get($class, $id))) {
            /** @var Model $existing */
            return $existing;
        }
        $meta = Metadata::for($class);

        return $this->whereIn($class, $meta->idColumn, [$id])[0] ?? null;
    }


    /**
     * @param class-string<Model> $class
     * @return list<Model>
     */
    public function all(string $class, ?string $orderBy = null): array
    {
        $meta = Metadata::for($class);
        $orderBy = $orderBy === null ? null : self::identifier($meta, $orderBy);
        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $columns = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $meta->selectColumns()));
        $sql = "SELECT {$columns} FROM \"{$meta->table}\"";
        if ($scope !== '') {
            $sql .= " WHERE {$scope}";
        }
        if ($orderBy !== null) {
            $sql .= " ORDER BY \"{$orderBy}\"";
        }

        return $this->hydrateAll($class, $this->connection->select($sql, $scopeBindings));
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
        $column = self::identifier($meta, $column);
        $orderBy = $orderBy === null ? null : self::identifier($meta, $orderBy);
        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $rows = $this->connection->select(
            $this->selectSql($meta, $column, \count($values), $orderBy, scope: $scope),
            [...array_values($values), ...$scopeBindings],
        );

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

        $unscoped = $this->isUnscoped();

        foreach ($rows as $row) {
            $id = (string) ($row[$meta->idColumn] ?? '');
            $model = $unscoped ? null : $this->identityMap->get($class, $id);

            if ($model === null) {
                /** @var Model $model */
                $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                $meta->hydrate($model, $row);
                if (!$unscoped) {
                    $model = $this->identityMap->remember($class, $id, $model);
                }
                $model->attach($this, $resultSet);
                $this->installRelations($model, $meta, $row);
                $model->markClean($this->writeValues($model, $meta));
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
        // shares one instance and one initializer run. Not while unscoped: a
        // ghost put in the map there would be served to a later scoped read.
        return $this->isUnscoped()
            ? $ghost
            : $this->identityMap->remember($target, $foreignKey, $ghost);
    }

    // ------------------------------------------------------------- writing

    /**
     * Writes a model, inserting or updating as appropriate.
     *
     * "save() writes immediately, as in Laravel." Only the diff is written:
     * original column values are snapshotted on hydrate, so an update touches
     * the columns that changed and nothing else.
     *
     * @template T of Model
     * @param T $model
     * @return T
     */
    public function save(Model $model): Model
    {
        $meta = Metadata::for($model::class);

        return $model->isPersisted()
            ? $this->update($model, $meta)
            : $this->insert($model, $meta);
    }

    /**
     * @template T of Model
     * @param T $model
     * @return T
     */
    private function insert(Model $model, ModelMeta $meta): Model
    {
        // UUIDv7 is minted here rather than by the database, so the model has
        // its key before the write and the identity map can hold it.
        if (!$this->isSet($model, $meta, $meta->idProperty)) {
            $meta->set($model, $meta->idProperty, Uuid::v7());
        }

        $values = $this->writeValues($model, $meta);

        if ($meta->tenantColumn !== null) {
            $tenant = $this->identityMap->tenant();
            if ($tenant === '') {
                throw new NoTenantContext($meta->class);
            }
            // Stamped, not taken from the model: a caller must not be able to
            // write a row into another tenant.
            $values[$meta->tenantColumn] = $tenant;
        }

        $columns = array_keys($values);
        $this->connection->execute(
            sprintf(
                'INSERT INTO "%s" (%s) VALUES (%s)',
                $meta->table,
                implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $columns)),
                implode(', ', array_fill(0, \count($columns), '?')),
            ),
            array_values($values),
        );

        $model->markClean($values);
        $model->attach($this, new ResultSet());
        $this->identityMap->remember($meta->class, $meta->keyOf($model), $model);

        return $model;
    }

    /**
     * @template T of Model
     * @param T $model
     * @return T
     */
    private function update(Model $model, ModelMeta $meta): Model
    {
        $changes = $model->changes();
        unset($changes[$meta->idColumn]);

        // The tenant is stamped on insert and never updated. Leaving it in the
        // diff would let a model that maps the column move its own row into
        // another tenant, with the WHERE predicate still satisfied by the
        // tenant it is leaving.
        if ($meta->tenantColumn !== null) {
            unset($changes[$meta->tenantColumn]);
        }

        if ($changes === []) {
            return $model;   // nothing to write
        }

        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $sql = sprintf(
            'UPDATE "%s" SET %s WHERE "%s" = ?',
            $meta->table,
            implode(', ', array_map(
                static fn (string $c): string => '"' . $c . '" = ?',
                array_keys($changes),
            )),
            $meta->idColumn,
        );

        // The tenant predicate is on the write too: an id from another tenant
        // must not be updatable even if one were somehow held.
        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        $this->connection->execute(
            $sql,
            [...array_values($changes), $meta->keyOf($model), ...$scopeBindings],
        );

        $model->markClean($this->writeValues($model, $meta));

        return $model;
    }

    /**
     * Column values plus the foreign keys single relations own.
     *
     * A #[BelongsTo] property is not a declared column, but the key it stands
     * for is a real column and has to be written -- otherwise an insert leaves
     * it null and the row is unlinked.
     *
     * @return array<string, mixed>
     */
    private function writeValues(Model $model, ModelMeta $meta): array
    {
        $values = $meta->columnValues($model);

        foreach ($meta->relations as $property => $relation) {
            if ($relation->kind !== RelationKind::BelongsTo) {
                continue;
            }

            $values[$relation->foreignKey] = $this->foreignKeyOf($model, $meta, $property, $relation);
        }

        return $values;
    }

    /**
     * The key a single relation stands for, without forcing it to load.
     *
     * The property holds a lazy ghost until something reads it. Reading it
     * here to ask for its id would make every hydrate issue a query for every
     * belongsTo -- the exact 1+N the lazy ghost exists to avoid -- so an
     * uninitialized ghost yields the key captured from the row instead.
     */
    private function foreignKeyOf(Model $model, ModelMeta $meta, string $property, RelationMeta $relation): mixed
    {
        if (!(new \ReflectionProperty($meta->class, $property))->isInitialized($model)) {
            return $model->foreignKey($property);
        }

        $related = $meta->get($model, $property);

        if ($related instanceof Model
            && !(new \ReflectionClass($relation->target))->isUninitializedLazyObject($related)) {
            return $related->primaryKey();
        }

        // Still a ghost: the row it stands for is the one the key names.
        return $model->foreignKey($property) ?? ($related instanceof Model ? $related->primaryKey() : null);
    }

    /** Deletes a row, scoped to the tenant like every other write. */
    public function delete(Model $model): void
    {
        $meta = Metadata::for($model::class);
        [$scope, $scopeBindings] = $this->tenantScope($meta);

        $sql = sprintf('DELETE FROM "%s" WHERE "%s" = ?', $meta->table, $meta->idColumn);
        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        $this->connection->execute($sql, [$meta->keyOf($model), ...$scopeBindings]);
        $this->identityMap->forget($meta->class, $meta->keyOf($model));
    }

    /**
     * Wraps several writes. "Db::transaction(fn () => ...) wraps multiple
     * writes."
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        try {
            return $this->connection->transaction($work);
        } catch (\Throwable $e) {
            // The rows written inside the transaction are gone, but the models
            // are still in the identity map with snapshots describing writes
            // that no longer exist. A later read would be served one of those
            // rather than the database's actual state.
            $this->identityMap->flush();
            $this->relationRows = [];

            throw $e;
        }
    }

    private function isSet(Model $model, ModelMeta $meta, string $property): bool
    {
        return (new \ReflectionProperty($meta->class, $property))->isInitialized($model);
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
        // A HasMany child's foreign key is declared on the owner's relation,
        // not in the child's own metadata, so it is allowed explicitly. It is
        // still compiled metadata; it just lives on the other side.
        $childColumn = self::identifier($targetMeta, $relation->childColumn(), [$relation->foreignKey]);

        [$scope, $scopeBindings] = $this->tenantScope($targetMeta);

        $rows = $this->connection->select(
            // The join column has to come back even when the child does not
            // declare it: it is what the rows are grouped by.
            $this->selectSql($targetMeta, $childColumn, \count($ids), $relation->orderBy, [$childColumn], $scope),
            [...$ids, ...$scopeBindings],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) ($row[$childColumn] ?? '')][] = $row;
        }

        // Not cached while unscoped, for the same reason the identity map is
        // bypassed: these rows were read without a tenant predicate.
        if ($this->isUnscoped()) {
            return;
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
    /**
     * Vets a column name before it is interpolated into SQL.
     *
     * tinvar-spec.md, "Identifiers from users": "The query builder rejects
     * identifiers that don't come from compiled metadata, so no one can sort
     * by a hidden column."
     *
     * Two checks, deliberately. Membership is the real one. The shape check
     * behind it means that even a caller passing something from compiled
     * metadata that somehow is not an identifier cannot reach the query --
     * an injected identifier binds looser than the appended tenant predicate
     * and would defeat tenant scoping as well as read hidden columns.
     */
    /** @param list<string> $alsoAllowed identifiers compiled elsewhere in the metadata */
    private static function identifier(ModelMeta $meta, string $column, array $alsoAllowed = []): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw MappingException::malformedIdentifier($column);
        }

        $known = [...$meta->knownIdentifiers(), ...$alsoAllowed];
        if (!\in_array($column, $known, true)) {
            throw MappingException::unknownIdentifier($meta->class, $column, $known);
        }

        return $column;
    }

    private function selectSql(
        ModelMeta $meta,
        string $column,
        int $count,
        ?string $orderBy,
        array $extraColumns = [],
        string $scope = '',
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

        if ($scope !== '') {
            $sql .= " AND {$scope}";
        }

        if ($orderBy !== null) {
            $sql .= " ORDER BY \"{$orderBy}\"";
        }

        return $sql;
    }
}
