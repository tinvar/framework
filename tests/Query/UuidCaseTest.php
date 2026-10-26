<?php

declare(strict_types=1);

/**
 * A model argument is a UUID, and a UUID names the same row in either case.
 *
 * Postgres compares uuid values case-insensitively, and Uuid::isValid()
 * accepts both, so an id that arrives upper-case -- copied from a log, typed
 * into a URL -- is a valid id for a row that exists.
 */

namespace Tinvar\Tests\Query\UuidCase;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Query\Fixtures\RackSummary;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';

/** @return array{0: QueryRunner, 1: Orm, 2: string} */
function summaryRunner(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 2, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new QueryRegistry();
    $registry->register(RackSummary::class);

    return [new QueryRunner($registry, $orm, new AllowAllGate()), $orm, $fixture->ids['racks'][0]];
}

it('resolves a model argument whose id arrives in upper case', function (\Closure $spell) {
    [$runner, , $id] = summaryRunner();

    $results = $runner->run([
        ['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => $spell($id)]],
    ], ['racks.summary']);

    expect($results[0]['value']['units'] ?? null)->toBe(2);
})->with([
    'upper case' => [static fn (string $id): string => strtoupper($id)],
    'mixed case' => [static fn (string $id): string => ucfirst(strtoupper(substr($id, 0, 9))) . substr($id, 9)],
]);

it('resolves an upper-case id from the identity map as well', function () {
    [$runner, $orm, $id] = summaryRunner();
    $orm->find(Rack::class, $id);

    $results = $runner->run([
        ['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => strtoupper($id)]],
    ], ['racks.summary']);

    expect($results[0]['value']['units'] ?? null)->toBe(2);
});
