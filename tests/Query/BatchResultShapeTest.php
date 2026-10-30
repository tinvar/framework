<?php

declare(strict_types=1);

/**
 * BatchQuery::loadMany() answers a list: one result per call, in call order.
 *
 * Anything else cannot be matched back to the calls safely. An array keyed by
 * call index but filled in the order rows came back from the database has the
 * right count and the wrong order, and realigning it by position handed one
 * call another call's data.
 */

namespace Tinvar\Tests\Query\BatchResultShape;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\BatchQuery;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

/** Keyed by call index, filled in descending order of n -- as rows came back. */
#[Query(name: 'shape.byIndex', args: ['n' => 'int']), NoAuthorization(reason: 'result shape is what is under test')]
final class ByIndex implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        $order = array_keys($calls);
        usort($order, static fn (int $a, int $b): int => $calls[$b]['n'] <=> $calls[$a]['n']);

        $out = [];
        foreach ($order as $index) {
            $out[$index] = 'for n=' . $calls[$index]['n'];
        }

        return $out;
    }
}

/** Keyed by something other than position. */
#[Query(name: 'shape.byName', args: ['n' => 'int']), NoAuthorization(reason: 'result shape is what is under test')]
final class ByName implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        $out = [];
        foreach ($calls as $call) {
            $out['n' . $call['n']] = 'for n=' . $call['n'];
        }

        return $out;
    }
}

/** A list, in call order: the contract. */
#[Query(name: 'shape.list', args: ['n' => 'int']), NoAuthorization(reason: 'result shape is what is under test')]
final class AsList implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return array_map(static fn (array $call): string => 'for n=' . $call['n'], $calls);
    }
}

/** @return list<array{id: int, value?: mixed, error?: array{code: string, digest: string, message?: string}}> */
function runThree(string $name): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->registerAll([ByIndex::class, ByName::class, AsList::class]);
    $runner = new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate());

    return $runner->run([
        ['id' => 10, 'name' => $name, 'args' => ['n' => 1]],
        ['id' => 11, 'name' => $name, 'args' => ['n' => 2]],
        ['id' => 12, 'name' => $name, 'args' => ['n' => 3]],
    ], [$name]);
}

it('refuses a result that is not a list, for every call in the batch', function (string $name) {
    $results = runThree($name);

    expect(array_map(static fn (array $r): ?string => $r['error']['code'] ?? null, $results))
        ->toBe([QueryError::BAD_RESULT, QueryError::BAD_RESULT, QueryError::BAD_RESULT])
        ->and(json_encode($results))->not->toContain('for n=');
})->with(['keyed by call index, out of order' => ['shape.byIndex'], 'keyed by name' => ['shape.byName']]);

it('answers each call from its own position in a list', function () {
    expect(array_map(static fn (array $r): mixed => $r['value'] ?? null, runThree('shape.list')))
        ->toBe(['for n=1', 'for n=2', 'for n=3']);
});
