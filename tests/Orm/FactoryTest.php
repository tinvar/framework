<?php

declare(strict_types=1);

use Racks\Database\Factories\RackFactory;
use Racks\Database\Factories\UnitFactory;
use Racks\Models\Rack;
use Racks\Models\Site;
use Racks\Models\Unit;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;

/** The Racks schema in SQLite, so factories are exercised without a server. */
function racksSqlite(): PdoConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, height_u INTEGER NOT NULL, site_id TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE units (id TEXT PRIMARY KEY, position INTEGER NOT NULL, name TEXT NOT NULL, rated_watts INTEGER NOT NULL, rack_id TEXT NOT NULL)');

    return new PdoConnection($pdo, 'racks-test');
}

function racksOrm(PdoConnection $connection): Orm
{
    return new Orm($connection, new IdentityMap('racks-test'));
}

function seedSite(Orm $orm): Site
{
    return $orm->save(new Site(code: 'LAX1', name: 'Los Angeles 1'));
}

it('builds a model through its own constructor, so the fields are typed', function () {
    $rack = RackFactory::new()->make();

    expect($rack)->toBeInstanceOf(Rack::class)
        ->and($rack->name)->toBe('R-01')
        ->and($rack->heightU)->toBe(42)
        ->and($rack->isPersisted())->toBeFalse('make() does not write');
});

it('derives the model it builds from definition()', function () {
    expect(RackFactory::model())->toBe(Rack::class)
        ->and(UnitFactory::model())->toBe(Unit::class);
});

it('builds a list when asked for several', function () {
    $racks = RackFactory::new()->count(3)->make();

    expect($racks)->toHaveCount(3)
        ->and(array_map(static fn (Rack $r): string => $r->name, $racks))
        ->toBe(['R-01', 'R-02', 'R-03']);
});

it('attaches a parent by type, with no property name needed', function () {
    $connection = racksSqlite();
    $orm = racksOrm($connection);
    $site = seedSite($orm);

    $rack = RackFactory::new()->for($site)->create($orm);

    expect($rack->isPersisted())->toBeTrue()
        ->and(Uuid::isValid($rack->primaryKey()))->toBeTrue();

    $reread = racksOrm($connection)->find(Rack::class, $rack->primaryKey());
    expect($reread->site->primaryKey())->toBe($site->primaryKey());
});

it('applies a state after the definition', function () {
    $rack = RackFactory::new()
        ->state(static function (Rack $rack): void { $rack->heightU = 24; })
        ->make();

    expect($rack->heightU)->toBe(24)->and($rack->name)->toBe('R-01');
});

it('writes a batch in one transaction', function () {
    $connection = racksSqlite();
    $orm = racksOrm($connection);
    $site = seedSite($orm);

    $racks = RackFactory::new()->count(20)->for($site)->create($orm);

    expect($racks)->toHaveCount(20)
        ->and(racksOrm($connection)->all(Rack::class))->toHaveCount(20);
});

it('leaves nothing behind when a write in the batch fails', function () {
    $connection = racksSqlite();
    $orm = racksOrm($connection);
    $site = seedSite($orm);

    // Every rack in the batch takes the same name, so the second insert
    // collides and the whole batch must roll back.
    $connection->pdo()->exec('CREATE UNIQUE INDEX racks_name ON racks (name)');

    $colliding = RackFactory::new()
        ->count(5)
        ->for($site)
        ->state(static function (Rack $rack): void { $rack->name = 'R-SAME'; });

    expect(fn () => $colliding->create($orm))->toThrow(PDOException::class);
    expect(racksOrm($connection)->all(Rack::class))->toHaveCount(0);
});

it('refuses an ambiguous or absent parent relation', function () {
    $connection = racksSqlite();
    $orm = racksOrm($connection);
    $site = seedSite($orm);

    // A Unit has no relation to a Site.
    expect(fn () => UnitFactory::new()->for($site))
        ->toThrow(LogicException::class, 'no single relation');
});

it('is immutable, so a configured factory can be reused', function () {
    $base = RackFactory::new();
    $three = $base->count(3);

    expect($base->make())->toBeInstanceOf(Rack::class)
        ->and($three->make())->toHaveCount(3);
});
