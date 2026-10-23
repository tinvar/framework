<?php

declare(strict_types=1);

namespace Tinvar\Tests\Query\Fixtures;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Query;
use Tinvar\Data\Data;
use Tinvar\Orm\Orm;
use Tinvar\Query\BatchQuery;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Unit;

final class UnitLoadData extends Data
{
    public function __construct(
        public string $unit,
        public int $watts,
    ) {}
}

final class RackSummaryData extends Data
{
    public function __construct(
        public string $name,
        public int $units,
    ) {}
}

/** A plain query. Touches a relation, so it exercises Level 2 batching. */
#[Query(name: 'racks.summary'), Authorize('view', 'rack')]
final class RackSummary
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(string $rack): RackSummaryData
    {
        $model = $this->orm->find(Rack::class, $rack);

        return new RackSummaryData($model->name, $model->units->count());
    }
}

/**
 * The benchmark's shape: one call per unit, collapsed into one statement.
 * "A query class can implement it to turn twenty rackStats(id) calls into one
 * whereIn."
 */
#[Query(name: 'racks.unitLoad', args: ['unit' => Unit::class]), Authorize('view', 'unit')]
final class UnitLoad implements BatchQuery
{
    public function __construct(private readonly Orm $orm) {}

    public function loadMany(array $calls): array
    {
        $ids = array_map(static fn (array $c): string => (string) $c['unit'], $calls);

        $rows = $this->orm->connection()->select(
            'SELECT "id", "position" FROM "units" WHERE "id" IN ('
            . implode(', ', array_fill(0, \count($ids), '?')) . ')',
            $ids,
        );

        $watts = [];
        foreach ($rows as $row) {
            $watts[(string) $row['id']] = 100 + ((int) $row['position'] * 7);
        }

        return array_map(
            static fn (string $id): UnitLoadData => new UnitLoadData($id, $watts[$id] ?? 0),
            $ids,
        );
    }
}

/** Returns a model, which the data boundary must refuse. */
#[Query(name: 'racks.leak')]
final class LeakyQuery
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(string $rack): Rack
    {
        return $this->orm->find(Rack::class, $rack);
    }
}

#[Query(name: 'racks.boom')]
final class BoomQuery
{
    public function __invoke(): never
    {
        throw new \RuntimeException('connection reset by peer');
    }
}

/** A batch query that returns the wrong number of results. */
#[Query(name: 'racks.miscounted')]
final class MiscountedBatch implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return [new UnitLoadData('only-one', 1)];
    }
}
