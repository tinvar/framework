<?php

declare(strict_types=1);

/**
 * A stand-in for a query's body -- the testing layer's fake (StandIns) -- at
 * the runner: asked only once a call has passed every check before the
 * body, answering read-only in the body's place, held to the encoder, and
 * kept out of the cache in both directions.
 */

namespace Tinvar\Tests\Query\StandIn;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\Gate;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Query\Fixtures\CachedSummary;
use Tinvar\Tests\Query\Fixtures\RackSummary;
use Tinvar\Tests\Query\Fixtures\RackSummaryData;
use Tinvar\Tests\Query\Fixtures\UnitLoad;
use Tinvar\Tests\Query\Fixtures\UnitLoadData;
use Tinvar\Tests\Orm\Fixtures\Unit;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';

/**
 * A runner whose stand-in answers $fakes by query name.
 *
 * @param array<string, \Closure> $fakes
 */
function runner(Fixture $fixture, array $fakes, ?Gate $gate = null, ?QueryCache $cache = null, ?Orm $orm = null): QueryRunner
{
    $orm ??= new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new QueryRegistry(fn (string $class): object => $class === CachedSummary::class ? new $class() : new $class($orm));
    $registry->registerAll([RackSummary::class, UnitLoad::class, CachedSummary::class]);

    return new QueryRunner(
        $registry,
        $orm,
        $gate ?? new AllowAllGate(),
        debug: true,
        cache: $cache,
        standIn: static fn (string $name): ?\Closure => $fakes[$name] ?? null,
    );
}

/** @param array<string, mixed> $args */
function call(string $name, array $args, int $id = 0): array
{
    return ['id' => $id, 'name' => $name, 'args' => $args];
}

it('answers in the body\'s place, handed the resolved model, its value encoded', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 3);
    $runner = runner($fixture, ['racks.summary' => static fn (Rack $rack): RackSummaryData => new RackSummaryData("faked {$rack->name}", 99)]);

    $results = $runner->run([call('racks.summary', ['rack' => $fixture->ids['racks'][0]])], ['racks.summary']);

    expect($results[0]['value'] ?? null)->toBe(['name' => 'faked R-01', 'units' => 99]);
});

it('is never asked for a call the gate denies, a model out of scope, or a name off the allowlist', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);
    $asked = 0;
    $fake = static function () use (&$asked): RackSummaryData {
        $asked++;

        return new RackSummaryData('faked', 0);
    };

    $denied = runner($fixture, ['racks.summary' => $fake], new DenyGate())
        ->run([call('racks.summary', ['rack' => $fixture->ids['racks'][0]])], ['racks.summary']);
    $missing = runner($fixture, ['racks.summary' => $fake])
        ->run([call('racks.summary', ['rack' => Uuid::v7()])], ['racks.summary']);
    $unlisted = runner($fixture, ['racks.summary' => $fake])
        ->run([call('racks.summary', ['rack' => $fixture->ids['racks'][0]])], []);

    expect($denied[0]['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN)
        ->and($missing[0]['error']['code'] ?? null)->toBe(QueryError::NOT_FOUND)
        ->and($unlisted[0]['error']['code'] ?? null)->toBe(QueryError::NOT_ALLOWED)
        ->and($asked)->toBe(0);
});

it('holds a model the fake returns to the data boundary, as the body\'s would be', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);
    $runner = runner($fixture, ['racks.summary' => static fn (Rack $rack): Rack => $rack]);

    $results = $runner->run([call('racks.summary', ['rack' => $fixture->ids['racks'][0]])], ['racks.summary']);

    expect($results[0])->not->toHaveKey('value')
        ->and($results[0]['error']['code'] ?? null)->toBe(QueryError::BAD_RESULT);
});

it('runs read-only, where the body would', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $runner = runner($fixture, ['racks.summary' => static function (Rack $rack) use ($orm): RackSummaryData {
        $rack->name = 'renamed';
        $orm->save($rack);

        return new RackSummaryData($rack->name, 0);
    }], orm: $orm);

    $results = $runner->run([call('racks.summary', ['rack' => $fixture->ids['racks'][0]])], ['racks.summary']);

    expect($results[0]['error']['code'] ?? null)->toBe(QueryError::FAILED)
        ->and($results[0]['error']['message'] ?? '')->toContain('read-only');
});

it('answers a batch query\'s calls one by one', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 2);
    [$first, $second] = $fixture->ids['units'];
    $runner = runner($fixture, ['racks.unitLoad' => static fn (Unit $unit): UnitLoadData => new UnitLoadData($unit->id, 1)]);

    $results = $runner->run([call('racks.unitLoad', ['unit' => $first], 1), call('racks.unitLoad', ['unit' => $second], 2)], ['racks.unitLoad']);

    expect(array_column($results, 'value'))->toBe([['unit' => $first, 'watts' => 1], ['unit' => $second, 'watts' => 1]]);
});

// A cached answer would hide the fake, and a fake's answer cached would be
// served to every later call as the real one.
it('neither reads the cache nor fills it, and still records what the call read', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 2);
    $cache = new QueryCache(new ArrayStore(), new AuthContext());
    $rack = $fixture->ids['racks'][0];
    $fake = ['racks.cached' => static fn (Rack $rack): RackSummaryData => new RackSummaryData('faked', 0)];
    CachedSummary::$runs = 0;

    $real = runner($fixture, [], cache: $cache)->run([call('racks.cached', ['rack' => $rack])], ['racks.cached']);
    $faking = runner($fixture, $fake, cache: $cache);
    $faked = $faking->run([call('racks.cached', ['rack' => $rack])], ['racks.cached']);
    $after = runner($fixture, [], cache: $cache)->run([call('racks.cached', ['rack' => $rack])], ['racks.cached']);

    expect($real[0]['value'] ?? null)->toBe(['name' => 'R-01', 'units' => 2])
        ->and($faked[0]['value'] ?? null)->toBe(['name' => 'faked', 'units' => 0], 'the cached answer did not hide the fake')
        ->and($faking->tagsRead())->toHaveCount(1)
        ->and($after[0]['value'] ?? null)->toBe(['name' => 'R-01', 'units' => 2], 'the fake stored nothing over it')
        ->and(CachedSummary::$runs)->toBe(1);
});
