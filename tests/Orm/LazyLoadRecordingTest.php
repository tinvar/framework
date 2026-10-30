<?php

declare(strict_types=1);

/**
 * What the ORM records of its lazy relation loads, for a test that turned
 * recording on, and assertNoLazyLoadsOutsideBatch() over it: a load over a
 * result set (Level 1) or through the render's scheduler (Level 2) is in a
 * batch; one for a parent alone -- the 1+N the batching exists to prevent --
 * is not.
 */

namespace Tinvar\Tests\Orm\LazyLoadRecording;

use PHPUnit\Framework\AssertionFailedError;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\LazyLoad;
use Tinvar\Orm\LazyLoads;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

use function Tinvar\Testing\assertNoLazyLoadsOutsideBatch;
use function Tinvar\Testing\expectQueries;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

/** @return array{Orm, LazyLoads} */
function recording(Fixture $fixture): array
{
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $loads = new LazyLoads();
    $orm->recordLazyLoads($loads);

    return [$orm, $loads];
}

/** @return list<string> each load, as relation and how it was batched */
function described(LazyLoads $loads): array
{
    return array_map(static fn (LazyLoad $load): string => "{$load->relation} {$load->batch} {$load->parents}", $loads->since());
}

it('records nothing, and refuses to assert, unless a test turned recording on', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    foreach ($orm->whereIn(Rack::class, 'id', $fixture->ids['racks']) as $rack) {
        $rack->units->count();
    }

    expect($orm->lazyLoads())->toBeNull()
        ->and(fn () => assertNoLazyLoadsOutsideBatch($orm))->toThrow(\LogicException::class, 'not recorded');
});

it('counts a relation read across a result set as one load in a batch: the spec\'s pair', function () {
    $fixture = new Fixture(racks: 5, unitsPerRack: 3);
    [$orm, $loads] = recording($fixture);
    $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);

    expectQueries(1, fn () => array_map(fn (Rack $r): int => $r->units->count(), $racks), $fixture->connection);
    assertNoLazyLoadsOutsideBatch($orm);

    expect(described($loads))->toBe([Rack::class . '::units result set 5']);
});

it('counts a relation read on the one row a statement returned as in a batch, as far as it can be', function () {
    $fixture = new Fixture(racks: 3, unitsPerRack: 2);
    [$orm, $loads] = recording($fixture);

    $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);
    \assert($rack instanceof Rack);
    expect($rack->units->count())->toBe(2)
        ->and($rack->site->code)->toBe('LAX0');

    assertNoLazyLoadsOutsideBatch($orm);
    expect(described($loads))->toBe([Rack::class . '::units result set 1', Rack::class . '::site result set 1']);
});

// The #[BelongsTo] itself is one load for every rack, but each site it
// reaches is filled by itself, so reading a relation on each site is a
// statement per site.
it('fails a relation read on each of the models a #[BelongsTo] reached, each loaded alone', function () {
    $fixture = new Fixture(racks: 6, unitsPerRack: 1, sites: 3);
    [$orm, $loads] = recording($fixture);

    foreach ($orm->whereIn(Rack::class, 'id', $fixture->ids['racks']) as $rack) {
        $rack->site->racks->count();
    }

    expect(described($loads))->toBe([
        Rack::class . '::site result set 6',
        Site::class . '::racks alone 1',
        Site::class . '::racks alone 1',
        Site::class . '::racks alone 1',
    ])
        ->and(fn () => assertNoLazyLoadsOutsideBatch($orm))
        ->toThrow(AssertionFailedError::class, Site::class . '::racks (3 times)');
});

// A batch that did not produce a parent's rows -- here, because it failed --
// leaves each parent that awaited it to load by itself.
it('fails the load a parent retries by itself after its batch', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    [$orm, $loads] = recording($fixture);
    $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
    $fixture->connection->execute('ALTER TABLE units RENAME TO units_gone');

    $results = $orm->beginRender()->run(['units' => static fn (): int => $racks[0]->units->count()]);

    expect($results['units'])->toBeInstanceOf(\PDOException::class)
        ->and(described($loads))->toBe([Rack::class . '::units render 2', Rack::class . '::units alone 1'])
        ->and(fn () => assertNoLazyLoadsOutsideBatch($orm))->toThrow(AssertionFailedError::class, Rack::class . '::units.');
});

// Level 2: every fiber's site, awaited in one tick, loads in one statement
// through the scheduler -- the batch a render makes of reads Level 1 cannot
// join.
it('counts a load the render\'s scheduler ran as in a batch', function () {
    $fixture = new Fixture(racks: 4, unitsPerRack: 1, sites: 2);
    [$orm, $loads] = recording($fixture);
    $racks = $orm->whereIn(Rack::class, 'id', $fixture->ids['racks']);
    $sites = array_map(static fn (Rack $rack): Site => $rack->site, $racks);

    $tasks = [];
    foreach ($sites as $i => $site) {
        $tasks[$i] = static fn (): int => $site->racks->count();
    }
    $orm->beginRender()->run($tasks);

    assertNoLazyLoadsOutsideBatch($orm);
    expect(described($loads))->toContain(Site::class . '::racks render 2');
});

it('keeps recording across a request\'s reset, and stops when told', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    [$orm, $loads] = recording($fixture);

    $orm->flush();
    $orm->whereIn(Rack::class, 'id', $fixture->ids['racks'])[0]->units->count();
    $orm->recordLazyLoads(null);
    $orm->flush();
    $orm->whereIn(Rack::class, 'id', $fixture->ids['racks'])[0]->units->count();

    expect($loads->count())->toBe(1)
        ->and($orm->lazyLoads())->toBeNull();
});
