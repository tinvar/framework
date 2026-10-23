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

    /** @var \WeakMap<object, int> */
    private \WeakMap $unscopedByFiber;

    /**
     * Depth of readOnly() calls, per fiber for the same reason as above: the
     * render scheduler interleaves loaders and query bodies, so a single
     * shared flag would make one fiber's read-only context everyone's.
     */
    private int $readOnlyMain = 0;

    /** @var \WeakMap<object, int> */
    private \WeakMap $readOnlyByFiber;

    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identityMap = new IdentityMap(),
    ) {
        $this->unscopedByFiber = new \WeakMap();
        $this->readOnlyByFiber = new \WeakMap();
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

    /**
     * Reset at every request, job and tenant boundary.
     *
     * This is the ORM's half of the reset contract (Http\RequestScope), and
     * the spec's clause starts with the tenant: "Tenant, auth, panel, identity
     * map and #[Scoped] services reset at the end of each request and job."
     * It used to clear the caches and leave the CONTEXT -- tenant, unscoped
     * depth, read-only depth -- exactly as the last request left it. actAs()
     * restores in a finally so the usual path was safe, but switchTenant() is
     * public, and a tenant carried into the next request on a worker is the
     * same class of bug as a user carried into it.
     */
    public function flush(): void
    {
        $this->identityMap->flush();
        $this->relationRows = [];
        $this->scheduler = null;

        $this->identityMap->resetTenant();
        $this->unscopedMain = 0;
        $this->unscopedByFiber = new \WeakMap();
        $this->readOnlyMain = 0;
        $this->readOnlyByFiber = new \WeakMap();
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

        // The relation-row cache is namespaced by tenant (relationCacheKey),
        // so a switch cannot read across one -- and the scheduler is left
        // alone. Nulling it here meant any actAs() inside a render silently
        // dropped every later relation load in that render from Level 2
        // batching to Level 1, with no diagnostic.
        //
        // Namespacing is not the same as freshness, though. The entered
        // tenant's relation rows are dropped alongside its identity-map
        // buckets, for the reason the map does it: a switch BACK must not
        // serve rows cached before an intervening write.
        $this->forgetRelationRows($tenant);
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

    /**
     * Runs $work with writes refused.
     *
     * "Loaders and queries run in a read-only transaction. save(), job
     * dispatch and event dispatch throw inside them, because hovering a link
     * runs them." Prefetch-on-hover is the point: a loader runs for a page
     * nobody has chosen to visit, so a write there happens without anyone
     * deciding to.
     *
     * This refuses the write in PHP rather than asking the database for a
     * READ ONLY transaction. A real one is the stronger guarantee and belongs
     * here later; this one holds on SQLite too, names the offending call, and
     * does not need the connection to be transactional.
     *
     * Being a PHP check, it covers the ORM facade -- save(), delete() and
     * transaction() -- and nothing below it. `$orm->connection()->execute()`
     * from inside a loader still writes, as do job and event dispatch, which
     * the spec names in the same breath and which do not exist yet. A database
     * READ ONLY transaction is what closes those, and the README says this is
     * still open rather than leaving the gap to be discovered.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function readOnly(\Closure $work): mixed
    {
        $this->setReadOnlyDepth($this->readOnlyDepth() + 1);

        try {
            return $work();
        } finally {
            $this->setReadOnlyDepth(max(0, $this->readOnlyDepth() - 1));
        }
    }

    public function isReadOnly(): bool
    {
        return $this->readOnlyDepth() > 0;
    }

    private function readOnlyDepth(): int
    {
        $fiber = \Fiber::getCurrent();

        return $fiber === null ? $this->readOnlyMain : ($this->readOnlyByFiber[$fiber] ?? $this->readOnlyMain);
    }

    private function setReadOnlyDepth(int $depth): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $this->readOnlyMain = $depth;

            return;
        }

        $this->readOnlyByFiber[$fiber] = $depth;
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

    /**
     * Whether a model in the identity map stands for a row that was read.
     *
     * Two things have to be true, and the ORDER matters. Laziness is checked
     * first, and WITHOUT touching the object: isPersisted() reads a property,
     * and reading a property of an uninitialized lazy ghost runs its
     * initializer. So asking "is this a real row?" the obvious way issued the
     * very query the question exists to avoid trusting -- and once a dangling
     * foreign key raises DanglingRelation, it did so from inside what should
     * have been a plain lookup.
     *
     * A ghost is a promise about a foreign key. Only markClean() -- hydrate
     * from a real row, or an insert -- makes a model evidence of one.
     */
    public static function holdsRow(object $model): bool
    {
        if ((new \ReflectionClass($model::class))->isUninitializedLazyObject($model)) {
            return false;
        }

        return $model instanceof Model && $model->isPersisted();
    }

    /** @param class-string<Model> $class */
    public function find(string $class, string $id): ?Model
    {
        // Every key is a UUIDv7 this Orm minted, so anything else names no
        // row -- and must be answered as such without asking the database.
        // Postgres refuses a malformed value for a uuid column outright, so
        // a route segment like /racks/nope was a 500 rather than a 404, and
        // inside a transaction the refusal would abort the transaction too.
        //
        // After the tenant check, which must fail closed whatever the key.
        if (!Uuid::isValid($id)) {
            $this->tenantScope(Metadata::for($class));

            return null;
        }

        // The identity map is keyed by tenant but knows nothing of scoping, so
        // a row read inside acrossTenants() would otherwise answer a later
        // scoped read for the same id. Unscoped reads bypass the caches
        // entirely: an explicit escape hatch can afford to be uncached.
        // A #[BelongsTo] registers a lazy ghost under its foreign key before
        // anything confirms the row exists, so a map hit is not evidence of a
        // row: a dangling, soft-deleted or out-of-tenant FK would otherwise
        // make find() return a hollow model, which breaks "a missing or
        // foreign id is a 404" and hands a policy a phantom subject.
        // isPersisted() is true only after markClean() -- hydrate from a real
        // row, or an insert -- so it separates both ghost cases from a read.
        // QueryRunner::resolveModels() guards the same way; every other
        // resolution path (Binder, ActionRunner, Validator) comes through
        // here, so the guard belongs here too.
        if (!$this->isUnscoped() && ($existing = $this->identityMap->get($class, $id))) {
            /** @var Model $existing */
            if (self::holdsRow($existing)) {
                return $existing;
            }
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
            [...$values, ...$scopeBindings],
        );

        return $this->hydrateAll($class, $rows);
    }

    /**
     * The common tail of hydrating a row: attach it, wire its relations and
     * snapshot it for change tracking.
     *
     * @param array<string, mixed> $row
     */
    private function settle(Model $model, ModelMeta $meta, array $row, ResultSet $resultSet): void
    {
        $model->attach($this, $resultSet);
        $this->installRelations($model, $meta, $row);
        $model->markClean($this->writeValues($model, $meta));
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

        $reflection = new \ReflectionClass($class);

        foreach ($rows as $row) {
            $id = (string) ($row[$meta->idColumn] ?? '');
            $model = $unscoped ? null : $this->identityMap->get($class, $id);

            if ($model === null) {
                /** @var Model $model */
                $model = $reflection->newInstanceWithoutConstructor();
                $meta->hydrate($model, $row);
                if (!$unscoped) {
                    $model = $this->identityMap->remember($class, $id, $model);
                }
                $this->settle($model, $meta, $row, $resultSet);
            } elseif ($reflection->isUninitializedLazyObject($model) || !$model->isPersisted()) {
                // A #[BelongsTo] put a ghost here from a foreign key: a
                // promise that a row exists, not the row. We are now holding
                // that row. Discarding it left the ghost to fetch the same
                // row again on first touch -- one extra statement for a row
                // already in hand, and a window where find() saw a model that
                // was not persisted.
                //
                // Also repairs a model that IS initialized but was never
                // marked clean -- which a ghost that resolved its own row used
                // to be -- so isPersisted() tells the truth either way.
                //
                // The object itself is kept, because other holders already
                // reference it and identity is the point of this map. A live
                // ghost is marked initialized FIRST: writing to one would run
                // its initializer, which is the fetch being avoided.
                if ($reflection->isUninitializedLazyObject($model)) {
                    $reflection->markLazyObjectAsInitialized($model);
                }

                $meta->hydrate($model, $row);
                $this->settle($model, $meta, $row, $resultSet);
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
                    // Throwing leaves the object UNINITIALIZED, so a later
                    // touch tries again -- which matters when the first touch
                    // happened under a scope that hid the row. Returning
                    // instead marked it initialized and empty, permanently.
                    throw DanglingRelation::for(
                        $parent::class,
                        $relation->property,
                        $relation->target,
                        $foreignKey,
                    );
                }
                $targetMeta->hydrate($object, $row);

                // Through settle(), so a ghost that found its row ends up in
                // exactly the state a hydrated row does -- attached, relations
                // installed, and MARKED CLEAN. It used to skip markClean(), so
                // a model reached by touching a #[BelongsTo] stayed
                // isPersisted() === false for the rest of the request: find()'s
                // guard re-queried it on every lookup and still handed back the
                // same instance, and save() on it issued an INSERT for a row
                // that already existed.
                // The object joins the result set it is settled into, so a
                // relation read on it afterwards has a batch to widen to
                // rather than an empty set.
                $set = new ResultSet();
                $this->settle($object, $targetMeta, $row, $set);
                $set->add($object);
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

    /** Refuses a write inside a loader or query body. */
    private function refuseWrite(string $what): void
    {
        if ($this->isReadOnly()) {
            throw ReadOnlyViolation::write($what, 'a loader or query body');
        }
    }

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
        $this->refuseWrite('save()');

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
        } elseif (!Uuid::isValid((string) $meta->get($model, $meta->idProperty))) {
            // A key find() would never look up. find() answers a non-UUID
            // key without asking the database, so a row stored under one
            // could be written and never read back.
            throw new MappingException("{$meta->class}: a preset key must be a UUID.");
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
        $this->refuseWrite('delete()');

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
        // A transaction opened inside a loader or query body is a write by
        // intent even if nothing in it writes through save().
        $this->refuseWrite('transaction()');

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

    /**
     * The parents a relation load should cover: the whole result set this
     * model came back in, or just this model.
     *
     * The choice used to be `?? [$parent]`, which never fired -- an EMPTY
     * result set is not null, so zero parents were handed to the loader, it
     * returned early, cached nothing, and the read fell through to an empty
     * collection. Silently, with no fibers and no tenancy involved: a model
     * reached through a #[BelongsTo] always has an empty result set, so
     * touching a relation and then reading a #[HasMany] on what came back
     * answered [].
     *
     * @return list<Model>
     */
    private function batchWith(Model $parent): array
    {
        $set = $parent->resultSet()?->all() ?? [];

        return $set === [] ? [$parent] : $set;
    }

    /** @return list<Model> */
    public function resolveHasMany(RelationMeta $relation, Model $parent): array
    {
        $key = $this->relationCacheKey($relation);
        $groupKey = $parent->primaryKey();

        // An unscoped read never touches the cache, in either direction, so it
        // takes its answer straight from the load.
        if ($this->isUnscoped()) {
            $grouped = $this->loadRelationRows($relation, $this->batchWith($parent), $key);

            return $this->hydrateAll($relation->target, $grouped[$groupKey] ?? []);
        }

        if (!$this->hasRows($key, $groupKey)) {
            // Inside a render, hand the load to the scheduler so it joins every
            // other relation wanted in this tick. Outside one, fall back to
            // Level 1 across the result set.
            if ($this->scheduler !== null && \Fiber::getCurrent() !== null) {
                $this->scheduler->await($key, $relation, $parent, $groupKey);
            } else {
                $this->loadRelationRows($relation, $this->batchWith($parent));
            }
        }

        // A batch that did not produce this group leaves the cache without the
        // key, and reading straight through would answer an EMPTY relation --
        // a silent wrong answer rather than a missing one. Load it directly
        // instead; the batch has already had its chance to be cheap.
        //
        // This parent alone, not its result set: if the set is what came back
        // empty, widening to it again would repeat the miss.
        if (!$this->hasRows($key, $groupKey)) {
            $this->loadRelationRows($relation, [$parent], $key);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->relationRows[$key][$groupKey] ?? [];

        return $this->hydrateAll($relation->target, $rows);
    }

    /** @return array<string, mixed>|null */
    public function resolveBelongsToRow(RelationMeta $relation, Model $parent, string $foreignKey): ?array
    {
        $key = $this->relationCacheKey($relation);

        if ($this->isUnscoped()) {
            $grouped = $this->loadRelationRows($relation, $this->batchWith($parent), $key);

            /** @var array<string, mixed>|null */
            return $grouped[$foreignKey][0] ?? null;
        }

        if (!$this->hasRows($key, $foreignKey)) {
            $this->loadRelationRows($relation, $this->batchWith($parent));
        }

        /** @var array<string, mixed>|null */
        return $this->relationRows[$key][$foreignKey] ?? null;
    }

    /**
     * One statement for every parent handed in. This is the whole point of the
     * batching: the group may span result sets and query calls.
     *
     * Returns the rows it grouped, keyed by group key, so a caller that
     * cannot use the cache still gets its answer. An unscoped read is exactly
     * that caller: these rows were read with no tenant predicate, so caching
     * them would serve them to a later scoped read -- but refusing to cache
     * them used to mean refusing to ANSWER, because both resolvers read their
     * result back out of the cache. acrossTenants() therefore returned an
     * empty collection for every relation.
     *
     * @param list<Model> $parents
     * @return array<string, list<array<string, mixed>>>
     */
    public function loadRelationRows(RelationMeta $relation, array $parents, ?string $key = null): array
    {
        // The scheduler passes the key the awaiting fiber computed, because
        // the tenant it was namespaced under is the one being re-entered, not
        // whatever is live in the context that happens to call this.
        $key ??= $this->relationCacheKey($relation);
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
            return [];
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
        // bypassed: these rows were read without a tenant predicate, and a
        // later scoped read must not be served one of them. The rows are
        // still RETURNED, so the caller that asked for them can use them.
        if ($this->isUnscoped()) {
            return $grouped;
        }

        foreach ($ids as $id) {
            $this->relationRows[$key][$id] = $relation->kind === RelationKind::HasMany
                ? ($grouped[$id] ?? [])
                : ($grouped[$id][0] ?? null);
        }

        return $grouped;
    }

    public function hasRows(string $relationKey, string $groupKey): bool
    {
        return \array_key_exists($groupKey, $this->relationRows[$relationKey] ?? []);
    }

    /**
     * The relation-row cache key, namespaced by tenant.
     *
     * RelationMeta::key() identifies the relation, not whose rows they are.
     * Unnamespaced, a relation loaded under one tenant answered the same
     * relation read under another -- and the batch scheduler, which groups by
     * the same key, would have merged two fibers in different tenants into one
     * statement under whichever scope happened to be live at flush time.
     */
    /** Drops every relation-row group belonging to one tenant. */
    private function forgetRelationRows(string $tenant): void
    {
        $prefix = $tenant . "\0";

        foreach (array_keys($this->relationRows) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->relationRows[$key]);
            }
        }
    }

    public function relationCacheKey(RelationMeta $relation): string
    {
        return $this->tenant() . "\0" . $relation->key();
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
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $column) !== 1) {
            throw MappingException::malformedIdentifier($column);
        }

        $known = [...$meta->knownIdentifiers(), ...$alsoAllowed];
        if (!\in_array($column, $known, true)) {
            throw MappingException::unknownIdentifier($meta->class, $column, $known);
        }

        return $column;
    }

    /** @param list<string> $extraColumns */
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
