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
