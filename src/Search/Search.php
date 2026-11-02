<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Gate;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * An application's full-text search (ADR 0154): its #[Searchable] models,
 * the backend their index is in, and the ORM it follows.
 *
 *     $search = new Search($orm, $backend, $classes->models, $dispatcher, $gate);
 *     $search->observe();
 *
 *     $page = $search->for(Product::class)->query('blue mug')->paginate();
 *
 * Built from the models discovery found (ADR 0146), each #[Searchable] one
 * checked as it is registered, so a field the model does not have stops
 * the boot. observe() has the ORM tell it of every row written; once the
 * write's transaction commits, the rows are read again -- in the tenant
 * the write was made in, or across tenants where it was made there -- and
 * indexed as they now are, or taken out where they are gone. Into the
 * application's own database at once; to a remote backend through a job
 * queued in each row's tenant (SyncSearchDocuments). A write rolled back
 * reaches no index.
 *
 * An index write that fails never fails the write it follows, which is
 * committed: it is reported, and `tinvar search:reindex` builds the index
 * again from the rows.
 */
final class Search
{
    /** Rows read and indexed at a time by reindex(). */
    public const int CHUNK = 500;

    /** @var array<class-string, SearchableModel> */
    private array $models = [];

    /**
     * @param list<class-string> $models the application's models: the #[Searchable] ones are registered, the rest ignored
     * @param Dispatcher|null $dispatcher what queues a remote backend's writes; required with one
     * @param Gate|null $gate what SearchQuery::authorize() asks
     * @param (\Closure(\Throwable): void)|null $report a failed index write, for the application's log
     */
    public function __construct(
        private readonly Orm $orm,
        private readonly SearchBackend $backend,
        array $models = [],
        private readonly ?Dispatcher $dispatcher = null,
        private readonly ?Gate $gate = null,
        private readonly ?\Closure $report = null,
    ) {
        if ($backend->remote() && $dispatcher === null) {
            throw new \InvalidArgumentException('A remote search backend is written to from the queue: give Search the application\'s Dispatcher.');
        }
        foreach ($models as $class) {
            if (SearchableModel::declaredOn($class)) {
                $this->models[$class] = SearchableModel::of($class);
            }
        }
    }

    /** Has the ORM tell this search of every row written, from now on. */
    public function observe(): self
    {
        $this->orm->observeWrites($this->written(...));

        return $this;
    }

    public function backend(): SearchBackend
    {
        return $this->backend;
    }

    /** @return array<class-string, SearchableModel> every #[Searchable] model registered */
    public function models(): array
    {
        return $this->models;
    }

    /**
     * A search of $class's rows.
     *
     * @param class-string $class
     */
    public function for(string $class): SearchQuery
    {
        return new SearchQuery($this, $this->orm, $this->model($class), SearchTerms::parse(''));
    }

    /**
     * Brings the index up to date with $ids of $class, under the scope in
     * force: each row found is indexed as it is, in its tenant, and each
     * missing is taken out of the tenants the scope reaches.
     *
     * @param class-string $class
     * @param list<string> $ids
     * @throws SearchFailed
     */
    public function sync(string $class, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $model = $this->model($class);
        $meta = $model->meta();
        $found = $this->orm->query($model->class)->whereIn($meta->idProperty, $ids)->fetch();
        $missing = array_values(array_diff($ids, array_map(static fn (Model $m): string => $meta->keyOf($m), $found)));

        if (!$model->tenantScoped()) {
            $this->backend->upsert($model, '', array_map($model->document(...), $found));
            $this->backend->remove($model, [''], $missing);

            return;
        }

        $tenants = $this->orm->tenantsInScope($meta);
        $byTenant = [];
        $tenantOf = $this->tenantsOf($model, $tenants, array_map(static fn (Model $m): string => $meta->keyOf($m), $found));
        foreach ($found as $row) {
            $byTenant[$tenantOf[$meta->keyOf($row)]][] = $model->document($row);
        }
        foreach ($byTenant as $tenant => $documents) {
            $this->orm->actAs((string) $tenant, fn () => $this->backend->upsert($model, (string) $tenant, $documents));
        }
        if ($missing !== []) {
            if ($tenants === null) {
                $this->backend->remove($model, null, $missing);
            } else {
                foreach ($tenants as $tenant) {
                    $this->orm->actAs($tenant, fn () => $this->backend->remove($model, [$tenant], $missing));
                }
            }
        }
    }

    /**
     * Builds $class's index again from its rows: in one tenant, or each in
     * turn where none is named -- every tenant there is, read across
     * tenants, then each tenant's rows read as that tenant. In the
     * application's database, emptied and filled in one transaction, so a
     * search meanwhile reads the old index whole.
     *
     * @param class-string $class
     * @param (\Closure(int): void)|null $progress told how many rows each chunk indexed
     * @return int the rows indexed
     * @throws SearchFailed
     */
    public function reindex(string $class, ?string $tenant = null, ?\Closure $progress = null): int
    {
        $model = $this->model($class);
        if (!$model->tenantScoped()) {
            return $this->reindexIn($model, '', $progress);
        }

        $indexed = 0;
        foreach ($tenant !== null ? [$tenant] : $this->tenantsWithRows($model) as $each) {
            $indexed += $this->orm->actAs($each, fn (): int => $this->reindexIn($model, $each, $progress));
        }

        return $indexed;
    }

    /**
     * How many rows reindex() would read: in one tenant, or in all.
     *
     * @param class-string $class
     */
    public function rowCount(string $class, ?string $tenant = null): int
    {
        $model = $this->model($class);
        if (!$model->tenantScoped()) {
            return $this->orm->query($model->class)->count();
        }

        return $tenant !== null
            ? $this->orm->actAs($tenant, fn (): int => $this->orm->query($model->class)->count())
            : $this->orm->acrossTenants(fn (): int => $this->orm->query($model->class)->count());
    }

    /**
     * Whether the Gate grants $ability on $model, for SearchQuery::authorize().
     *
     * @internal for SearchQuery
     */
    public function allows(string $ability, Model $model): bool
    {
        if ($this->gate === null) {
            throw new \LogicException('A search asked to authorize its results has no Gate to ask: give Search the application\'s Gate.');
        }

        return $this->gate->allows(new Authorize($ability, 'model'), ['model' => $model]);
    }

    /** @param class-string $class */
    private function model(string $class): SearchableModel
    {
        return $this->models[$class] ?? throw new \InvalidArgumentException(
            SearchableModel::declaredOn($class)
                ? "{$class} is #[Searchable] but not registered with this search: build Search from the models discovery found."
                : "{$class} is not #[Searchable]: add #[Searchable(fields: [...])] to it, naming the properties to search.",
        );
    }

    /**
     * Told by the ORM of a row written: once its transaction commits, the
     * row is indexed again, in the scope the write was made in.
     *
     * @param class-string $class
     */
    private function written(string $class, string $id, bool $deleted): void
    {
        $model = $this->models[$class] ?? null;
        if ($model === null) {
            return;
        }

        // The scope to read the row again in, taken now: the commit may come
        // after the code that set it up has returned.
        $run = match (true) {
            !$model->tenantScoped() => static fn (\Closure $work): mixed => $work(),
            $this->orm->isUnscoped() => $this->acrossTenants(...),
            default => $this->orm->captureScope(),
        };

        $this->orm->afterCommit(function () use ($run, $class, $id): void {
            try {
                $run(fn () => $this->backend->remote() ? $this->queue($class, [$id]) : $this->sync($class, [$id]));
            } catch (\Throwable $e) {
                // Never thrown on: the write it follows is committed, and a
                // caller that retried on the throw would write it twice.
                if ($this->report !== null) {
                    ($this->report)($e);
                } else {
                    error_log("Search could not index {$class} {$id}: " . $e::class . ': ' . $e->getMessage() . ' Run `tinvar search:reindex` once the cause is fixed.');
                }
            }
        });
    }

    /**
     * Runs $work across tenants: a write made there is read again there.
     *
     * @param \Closure(): mixed $work
     */
    private function acrossTenants(\Closure $work): mixed
    {
        return $this->orm->acrossTenants($work);
    }

    /**
     * Queues the sync of $ids, under the scope in force, in the tenant of
     * each row found -- and of each tenant the scope reaches for a row gone.
     * A row gone after a delete across tenants is left for the next
     * reindex: it names no tenant to queue in, and a search never shows it.
     *
     * @param class-string $class
     * @param list<string> $ids
     */
    private function queue(string $class, array $ids): void
    {
        \assert($this->dispatcher !== null);
        $model = $this->model($class);
        if (!$model->tenantScoped()) {
            $this->dispatcher->dispatch(new SyncSearchDocuments($class, $ids));

            return;
        }

        $meta = $model->meta();
        $tenants = $this->orm->tenantsInScope($meta);
        $found = $this->orm->query($model->class)->whereIn($meta->idProperty, $ids)->orderBy($meta->idProperty)->pluck($meta->idProperty);
        $found = array_map(strval(...), $found);
        $byTenant = [];
        foreach ($this->tenantsOf($model, $tenants, $found) as $id => $tenant) {
            $byTenant[$tenant][] = $id;
        }
        $missing = array_values(array_diff($ids, $found));
        if ($missing !== []) {
            foreach ($tenants ?? [] as $tenant) {
                $byTenant[$tenant] = [...($byTenant[$tenant] ?? []), ...$missing];
            }
        }
        foreach ($byTenant as $tenant => $keys) {
            $this->orm->actAs((string) $tenant, fn (): string => $this->dispatcher->dispatch(new SyncSearchDocuments($class, array_values(array_unique($keys)))));
        }
    }

    /**
     * The tenant each of $ids is in: the one tenant the scope holds reads
     * to, or, read widened, each row's own tenant column.
     *
     * @param list<string>|null $tenants
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function tenantsOf(SearchableModel $model, ?array $tenants, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if ($tenants !== null && \count($tenants) === 1) {
            return array_fill_keys($ids, $tenants[0]);
        }
        $meta = $model->meta();
        \assert($model->tenantColumn !== null);
        $query = $this->orm->query($model->class)->whereIn($meta->idProperty, $ids)->orderBy($meta->idProperty);
        $keys = array_map(strval(...), $query->pluck($meta->idProperty));
        $owners = array_map(strval(...), $query->pluck($model->tenantColumn));

        return \count($keys) === \count($owners) ? array_combine($keys, $owners) : [];
    }

    /**
     * Every tenant with a row of $model, read across tenants.
     *
     * @return list<string>
     */
    private function tenantsWithRows(SearchableModel $model): array
    {
        \assert($model->tenantColumn !== null);

        return array_map(strval(...), $this->orm->acrossTenants(
            fn (): array => $this->orm->query($model->class)->distinct()->orderBy($model->tenantColumn)->pluck($model->tenantColumn),
        ));
    }

    /** One tenant's index of $model emptied and filled again from its rows, read in the scope in force. */
    private function reindexIn(SearchableModel $model, string $tenant, ?\Closure $progress): int
    {
        $fill = function () use ($model, $tenant, $progress): int {
            $this->backend->flush($model, $tenant);
            $meta = $model->meta();
            $indexed = 0;
            $after = null;
            do {
                $query = $this->orm->query($model->class)->orderBy($meta->idProperty)->limit(self::CHUNK);
                if ($after !== null) {
                    $query = $query->where($meta->idProperty, '>', $after);
                }
                $rows = $query->fetch();
                if ($rows === []) {
                    break;
                }
                $this->backend->upsert($model, $tenant, array_map($model->document(...), $rows));
                $after = $meta->keyOf($rows[array_key_last($rows)]);
                $indexed += \count($rows);
                if ($progress !== null) {
                    $progress(\count($rows));
                }
            } while (\count($rows) === self::CHUNK);

            return $indexed;
        };

        return $this->backend->remote() ? $fill() : $this->orm->transaction($fill);
    }
}
