<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * Level 2 lazy loading: render-wide relation batching over fibers.
 *
 * Each call in a query batch runs in its own fiber. When one reaches a relation
 * it has not got, it registers the load and suspends. Once every live fiber is
 * suspended, the scheduler runs one statement per relation and resumes them all.
 *
 * Registration happens in a relation accessor rather than a lazy-ghost
 * initializer; ADR 0002 records why the spec's wording is unsafe on PHP 8.5.
 */
final class BatchScheduler
{
    /** @var array<string, array{relation: RelationMeta, parents: array<string, Model>}> */
    private array $pending = [];

    private int $rounds = 0;

    public function __construct(private readonly Orm $orm) {}

    /**
     * Registers a relation load for this tick and suspends until it has run.
     *
     * Two fibers awaiting the same relation on the same parent join one group,
     * so a shared row costs one statement, not two.
     */
    public function await(string $key, RelationMeta $relation, Model $parent, string $groupKey): void
    {
        // The key comes from the caller because it carries the tenant: two
        // fibers awaiting the same relation in DIFFERENT tenants must not join
        // one group, or the batch would run under whichever scope was live
        // when it flushed.
        $this->pending[$key]['relation'] = $relation;
        $this->pending[$key]['parents'][$groupKey] = $parent;

        \Fiber::suspend();
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /** Rounds of batched loading run so far; one round may cover many relations. */
    public function rounds(): int
    {
        return $this->rounds;
    }

    /** Runs one statement per pending relation. */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $groups = $this->pending;
        $this->pending = [];
        $this->rounds++;

        foreach ($groups as $group) {
            $this->orm->loadRelationRows($group['relation'], array_values($group['parents']));
        }
    }

    /**
     * Runs every task as a fiber, flushing between rounds until all have
     * finished. A task that throws yields its Throwable in place, so one failed
     * call is reported against its own id instead of failing the batch.
     *
     * @param array<array-key, \Closure(): mixed> $tasks
     * @return array<array-key, mixed>
     */
    public function run(array $tasks): array
    {
        $results = [];
        $live = [];

        foreach ($tasks as $id => $task) {
            $live[$id] = new \Fiber($task);
        }

        foreach ($live as $id => $fiber) {
            $this->settle($id, $fiber, $results, $live, start: true);
        }

        while ($live !== []) {
            if (!$this->hasPending()) {
                throw new \LogicException(
                    'Render stalled: ' . \count($live) . ' fiber(s) suspended with no pending relation load. '
                    . 'A suspend outside the scheduler cannot be resumed.'
                );
            }

            $this->flush();

            foreach ($live as $id => $fiber) {
                $this->settle($id, $fiber, $results, $live, start: false);
            }
        }

        return $results;
    }

    /**
     * @param array<array-key, mixed>  $results
     * @param array<array-key, \Fiber> $live
     * @param-out array<array-key, mixed>  $results
     * @param-out array<array-key, \Fiber> $live
     */
    private function settle(int|string $id, \Fiber $fiber, array &$results, array &$live, bool $start): void
    {
        try {
            $start ? $fiber->start() : ($fiber->isSuspended() ? $fiber->resume() : null);
        } catch (\Throwable $e) {
            $results[$id] = $e;
            unset($live[$id]);

            return;
        }

        if ($fiber->isTerminated()) {
            $results[$id] = $fiber->getReturn();
            unset($live[$id]);
        }
    }
}
