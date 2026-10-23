<?php

declare(strict_types=1);

use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';

/**
 * Resolving a model argument may use the identity map, but only for rows a
 * tenant-scoped select actually returned.
 *
 * A #[BelongsTo] registers a lazy ghost in the map from a foreign key, before
 * anything confirms the row exists. Accepting one here would resolve an id the
 * database never produced.
 */
it('does not resolve a model argument from an uninitialized ghost', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    // A rack pointing at a site that does not exist.
    $danglingSiteId = Uuid::v7();
    $fixture->connection->execute(
        'INSERT INTO racks (id, name, height_u, site_id) VALUES (?, ?, ?, ?)',
        [Uuid::v7(), 'R-DANGLING', 42, $danglingSiteId],
    );

    // Loading the racks installs a Site ghost under the dangling id.
    $orm->all(Rack::class);
    expect($orm->identityMap()->has(Site::class, $danglingSiteId))
        ->toBeTrue('the ghost is in the map');

    $registry = new QueryRegistry(static fn (string $c): object => new $c());
    $registry->register(Tinvar\Tests\Query\Fixtures\SiteCode::class);
    $runner = new QueryRunner($registry, $orm, new AllowAllGate());

    $results = $runner->run(
        [['id' => 0, 'name' => 'racks.siteCode', 'args' => ['site' => $danglingSiteId]]],
        allowed: ['racks.siteCode'],
    );

    expect($results[0]['error']['code'])->toBe(QueryError::NOT_FOUND)
        ->and($results[0])->not->toHaveKey('value');
});

it('still resolves a model the database really returned', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $site = $orm->find(Site::class, $fixture->ids['sites'][0]);

    $registry = new QueryRegistry(static fn (string $c): object => new $c());
    $registry->register(Tinvar\Tests\Query\Fixtures\SiteCode::class);
    $runner = new QueryRunner($registry, $orm, new AllowAllGate());

    $fixture->connection->resetStatementCount();
    $results = $runner->run(
        [['id' => 0, 'name' => 'racks.siteCode', 'args' => ['site' => $site->primaryKey()]]],
        allowed: ['racks.siteCode'],
    );

    expect($results[0]['value']['name'])->toBe($site->code)
        ->and($fixture->connection->statementCount())->toBe(0, 'served from the map, which had really read it');
});

/**
 * A ghost whose row did not exist is left initialized but unhydrated, so
 * "is it initialized?" is not the question. "Did a row produce it?" is.
 */
it('does not resolve from a ghost that was touched and found nothing', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $danglingSiteId = Uuid::v7();
    $fixture->connection->execute(
        'INSERT INTO racks (id, name, height_u, site_id) VALUES (?, ?, ?, ?)',
        [Uuid::v7(), 'R-DANGLING', 42, $danglingSiteId],
    );

    foreach ($orm->all(Rack::class) as $rack) {
        if ($rack->name !== 'R-DANGLING') {
            continue;
        }
        // Touching it runs the initializer, which finds no row and returns.
        try {
            $rack->site->code;
        } catch (\Throwable) {
            // an unhydrated ghost has no code; that is the point
        }
    }

    $registry = new QueryRegistry(static fn (string $c): object => new $c());
    $registry->register(Tinvar\Tests\Query\Fixtures\SiteCode::class);
    $runner = new QueryRunner($registry, $orm, new AllowAllGate());

    $results = $runner->run(
        [['id' => 0, 'name' => 'racks.siteCode', 'args' => ['site' => $danglingSiteId]]],
        allowed: ['racks.siteCode'],
    );

    expect($results[0]['error']['code'])->toBe(QueryError::NOT_FOUND);
});

/**
 * The same guard, at the level every other resolution path uses.
 *
 * QueryRunner::resolveModels() was hardened for this; Orm::find() was not --
 * and Binder (route model binding), ActionRunner and Validator all resolve
 * through find(). So a dangling, soft-deleted or out-of-tenant foreign key
 * resolved to a hollow model: "a missing or foreign id is a 404" did not hold,
 * and a policy was handed a subject with no row behind it.
 */
it('does not resolve find() from an uninitialized ghost', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $danglingSiteId = Uuid::v7();
    $fixture->connection->execute(
        'INSERT INTO racks (id, name, height_u, site_id) VALUES (?, ?, ?, ?)',
        [Uuid::v7(), 'R-DANGLING', 42, $danglingSiteId],
    );

    $orm->all(Rack::class);
    expect($orm->identityMap()->has(Site::class, $danglingSiteId))->toBeTrue();

    expect($orm->find(Site::class, $danglingSiteId))
        ->toBeNull('a ghost is a promise about a foreign key, never evidence of a row');
});

/** A real row still comes back from the map, so the map is still a cache. */
it('still resolves find() from the map for a row that was read', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $siteId = $fixture->ids['sites'][0];
    $first = $orm->find(Site::class, $siteId);
    $before = $fixture->connection->statementCount();
    $second = $orm->find(Site::class, $siteId);

    expect($second)->toBe($first)
        ->and($fixture->connection->statementCount())->toBe($before, 'served from the identity map');
});

/**
 * The other half of the ghost story: when the row DOES arrive, take it.
 *
 * hydrateAll() reused whatever the identity map held and discarded the row it
 * had just read, so a ghost installed by a #[BelongsTo] stayed a promise even
 * while the fact was in hand -- one extra statement on first touch, and a
 * window in which find() saw a model that was not persisted.
 */
it('fills a ghost from a row it has just read, without replacing the object', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    // Reading racks installs a Site ghost from site_id.
    $rack = $orm->all(Rack::class)[0];
    $siteId = $fixture->ids['sites'][0];

    $ghost = $orm->identityMap()->get(Site::class, $siteId);
    expect($ghost)->not->toBeNull()
        ->and((new ReflectionClass(Site::class))->isUninitializedLazyObject($ghost))->toBeTrue();

    // Now read the sites for real.
    $orm->all(Site::class);
    $before = $fixture->connection->statementCount();

    expect($orm->identityMap()->get(Site::class, $siteId))
        ->toBe($ghost, 'the same instance: other holders already reference it')
        ->and((new ReflectionClass(Site::class))->isUninitializedLazyObject($ghost))->toBeFalse()
        ->and($ghost->isPersisted())->toBeTrue()
        ->and($ghost->code)->not->toBeEmpty()
        ->and($fixture->connection->statementCount())
        ->toBe($before, 'reading it costs nothing: the row was already in hand');
});
