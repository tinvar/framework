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

final readonly class UnitLoadData extends Data
{
    public function __construct(
        public string $unit,
        public int $watts,
    ) {}
}

final readonly class RackSummaryData extends Data
{
    public function __construct(
        public string $name,
        public int $units,
    ) {}
}

/**
 * A plain query. Touches a relation, so it exercises Level 2 batching.
 *
 * The parameter is model-typed, so the runner resolves and scopes it before
 * the check runs. Declaring Authorize over a bare string would hand the policy
 * an identifier, which fails closed.
 */
#[Query(name: 'racks.summary'), Authorize('view', 'rack')]
final class RackSummary
{
    public function __invoke(Rack $rack): RackSummaryData
    {
        return new RackSummaryData($rack->name, $rack->units->count());
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

    /**
     * The units arrive already resolved and tenant-scoped: #[Query(args:)]
     * declares them, and the runner batches the lookup into one statement
     * before authorizing each call.
     *
     * @param list<array{unit: Unit}> $calls
     * @return list<UnitLoadData>
     */
    public function loadMany(array $calls): array
    {
        return array_map(
            static fn (array $call): UnitLoadData => new UnitLoadData(
                $call['unit']->primaryKey(),
                100 + ($call['unit']->position * 7),
            ),
            $calls,
        );
    }
}

/** Returns a model, which the data boundary must refuse. */
#[Query(name: 'racks.leak')]
final class LeakyQuery
{
    public function __invoke(Rack $rack): Rack
    {
        return $rack;
    }
}

/** Two declared parameters, so argument-order dedupe is observable. */
#[Query(name: 'racks.window')]
final class RackWindow
{
    public function __invoke(Rack $rack, int $limit): RackSummaryData
    {
        return new RackSummaryData($rack->name, min($limit, $rack->units->count()));
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

/** A cached query, with a tag derived from its resolved model. */
#[Query(
    name: 'racks.cached',
    cache: '5m',
    tags: static function (Rack $rack): array { return ['rack:' . $rack->primaryKey()]; },
)]
final class CachedSummary
{
    /** Counts body runs, since a cache hit is not visible in statement counts. */
    public static int $runs = 0;

    public function __invoke(Rack $rack): RackSummaryData
    {
        self::$runs++;

        return new RackSummaryData($rack->name, $rack->units->count());
    }
}

/** Takes a Site, so a Site ghost in the identity map could short-circuit it. */
#[Query(name: 'racks.siteCode')]
final class SiteCode
{
    public function __invoke(\Tinvar\Tests\Orm\Fixtures\Site $site): RackSummaryData
    {
        return new RackSummaryData($site->code, 0);
    }
}
