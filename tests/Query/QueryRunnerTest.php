<?php

declare(strict_types=1);

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\DenyGate;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Query\Fixtures\BoomQuery;
use Tinvar\Tests\Query\Fixtures\LeakyQuery;
use Tinvar\Tests\Query\Fixtures\MiscountedBatch;
use Tinvar\Tests\Query\Fixtures\RackSummary;
use Tinvar\Tests\Query\Fixtures\RackWindow;
use Tinvar\Tests\Query\Fixtures\UnitLoad;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';

/** @return array{0: QueryRunner, 1: Fixture, 2: Orm, 3: AllowAllGate} */
function runnerFor(Fixture $fixture, ?object $gate = null, bool $debug = false): array
{
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new QueryRegistry(fn (string $class): object => match ($class) {
        BoomQuery::class, MiscountedBatch::class => new $class(),
        default => new $class($orm),
    });
    $registry->registerAll([
        RackSummary::class, RackWindow::class, UnitLoad::class,
        LeakyQuery::class, BoomQuery::class, MiscountedBatch::class,
    ]);

    $gate ??= new AllowAllGate();

    return [new QueryRunner($registry, $orm, $gate, debug: $debug), $fixture, $orm, $gate];
}

/** @param list<array{0: string, 1: array<string, mixed>}> $specs */
function callsFor(array $specs): array
{
    $calls = [];
    foreach ($specs as $i => [$name, $args]) {
        $calls[] = ['id' => $i, 'name' => $name, 'args' => $args];
    }

    return $calls;
}

describe('the per-route allowlist', function () {
    it('refuses a query name the route never uses', function () {
        [$runner, $fixture] = runnerFor(new Fixture(racks: 1, unitsPerRack: 1));

        $results = $runner->run(
            callsFor([['racks.leak', ['rack' => $fixture->ids['racks'][0]]]]),
            allowed: ['racks.summary'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::NOT_ALLOWED)
            ->and($results[0])->not->toHaveKey('value');
    });

    it('refuses a name that is allowed but not registered', function () {
        [$runner] = runnerFor(new Fixture(racks: 1, unitsPerRack: 1));

        $results = $runner->run(callsFor([['racks.ghost', []]]), allowed: ['racks.ghost']);

        expect($results[0]['error']['code'])->toBe(QueryError::UNKNOWN);
    });
});

describe('authorization', function () {
    it('runs the declared checks before the body', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 2);
        [$runner, , , $gate] = runnerFor($fixture);

        $runner->run(
            callsFor([['racks.summary', ['rack' => $fixture->ids['racks'][0]]]]),
            allowed: ['racks.summary'],
        );

        expect($gate->checks)->toBe([['ability' => 'view', 'argument' => 'rack']]);
    });

    it('fails a denied call individually, without running any statement', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 2);
        [$runner] = runnerFor($fixture, new DenyGate());
        $fixture->connection->resetStatementCount();

        $results = $runner->run(
            callsFor([['racks.summary', ['rack' => $fixture->ids['racks'][0]]]]),
            allowed: ['racks.summary'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::FORBIDDEN)
            ->and($fixture->connection->statementCount())->toBe(0, 'denied before the body ran');
    });
});

describe('batching and dedupe', function () {
    it('turns one call per unit into a single statement', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 42);
        [$runner] = runnerFor($fixture);
        $fixture->connection->resetStatementCount();

        $results = $runner->run(
            callsFor(array_map(
                static fn (string $id): array => ['racks.unitLoad', ['unit' => $id]],
                $fixture->ids['units'],
            )),
            allowed: ['racks.unitLoad'],
        );

        expect($results)->toHaveCount(42)
            ->and($fixture->connection->statementCount())->toBe(1, '42 calls, one statement')
            ->and($results[0]['value'])->toHaveKeys(['unit', 'watts'])
            ->and($results[0]['value']['watts'])->toBe(107);
    });

    it('collapses identical calls and answers each id', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        [$runner] = runnerFor($fixture);
        $unit = $fixture->ids['units'][0];
        $fixture->connection->resetStatementCount();

        $results = $runner->run(
            callsFor([
                ['racks.unitLoad', ['unit' => $unit]],
                ['racks.unitLoad', ['unit' => $unit]],
                ['racks.unitLoad', ['unit' => $unit]],
            ]),
            allowed: ['racks.unitLoad'],
        );

        expect($results)->toHaveCount(3)
            ->and($results[0]['value'])->toBe($results[2]['value'])
            ->and($fixture->connection->statementCount())->toBe(1);
    });

    it('treats argument order as irrelevant when deduping', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 4);
        [$runner] = runnerFor($fixture);
        $rack = $fixture->ids['racks'][0];
        $fixture->connection->resetStatementCount();

        $results = $runner->run([
            ['id' => 0, 'name' => 'racks.window', 'args' => ['rack' => $rack, 'limit' => 2]],
            ['id' => 1, 'name' => 'racks.window', 'args' => ['limit' => 2, 'rack' => $rack]],
        ], allowed: ['racks.window']);

        expect($results[0]['value'])->toBe($results[1]['value'])
            ->and($results[0]['value']['units'])->toBe(2)
            ->and($fixture->connection->statementCount())->toBe(2, 'one find, one units load, run once');
    });

    it('batches relation loads reached from separate queries', function () {
        $fixture = new Fixture(racks: 12, unitsPerRack: 3);
        [$runner] = runnerFor($fixture);
        $fixture->connection->resetStatementCount();

        $results = $runner->run(
            callsFor(array_map(
                static fn (string $id): array => ['racks.summary', ['rack' => $id]],
                $fixture->ids['racks'],
            )),
            allowed: ['racks.summary'],
        );

        expect($results)->toHaveCount(12)
            ->and($results[0]['value']['units'])->toBe(3)
            // 12 finds, then one batched units statement for all of them.
            ->and($fixture->connection->statementCount())->toBeLessThanOrEqual(13);
    });
});

describe('arguments are resolved against the declaration', function () {
    // The renderer is semi-trusted -- it runs third-party npm code -- so
    // argument maps are checked before they reach a gate or a call.

    it('refuses a positional argument', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        [$runner] = runnerFor($fixture, debug: true);

        $results = $runner->run(
            [['id' => 0, 'name' => 'racks.window', 'args' => [0 => $fixture->ids['racks'][0], 1 => 2]]],
            allowed: ['racks.window'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_ARGUMENTS)
            ->and($results[0]['error']['message'])->toContain('positional');
    });

    it('refuses an argument the query does not declare', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        [$runner] = runnerFor($fixture, debug: true);

        $results = $runner->run(
            callsFor([['racks.unitLoad', ['unit' => $fixture->ids['units'][0], 'sneaky' => 1]]]),
            allowed: ['racks.unitLoad'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_ARGUMENTS)
            ->and($results[0]['error']['message'])->toContain('sneaky');
    });

    it('refuses a call missing a required argument', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        [$runner] = runnerFor($fixture, debug: true);

        $results = $runner->run(
            callsFor([['racks.window', ['rack' => $fixture->ids['racks'][0]]]]),
            allowed: ['racks.window'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_ARGUMENTS)
            ->and($results[0]['error']['message'])->toContain('limit');
    });

    it('denies a call that omits the argument an #[Authorize] names', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        [$runner] = runnerFor($fixture, debug: true);

        // racks.unitLoad declares #[Authorize('view', 'unit')]; 'unit' is also
        // its only declared parameter, so an empty call must not slip past.
        $results = $runner->run(callsFor([['racks.unitLoad', []]]), allowed: ['racks.unitLoad']);

        expect($results[0]['error']['code'])->toBeIn([QueryError::BAD_ARGUMENTS, QueryError::FORBIDDEN])
            ->and($results[0])->not->toHaveKey('value');
    });

    it('issues no statement for a malformed call', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        [$runner] = runnerFor($fixture);
        $fixture->connection->resetStatementCount();

        $runner->run(
            callsFor([['racks.unitLoad', ['nope' => 1]]]),
            allowed: ['racks.unitLoad'],
        );

        expect($fixture->connection->statementCount())->toBe(0);
    });
});

describe('failures are classified, never leaked', function () {
    it('reports a thrown query as a code and digest', function () {
        [$runner] = runnerFor(new Fixture(racks: 1, unitsPerRack: 1));

        $results = $runner->run(callsFor([['racks.boom', []]]), allowed: ['racks.boom']);

        expect($results[0]['error']['code'])->toBe(QueryError::FAILED)
            ->and($results[0]['error'])->not->toHaveKey('message')
            ->and($results[0]['error']['digest'])->toHaveLength(16)
            ->and(json_encode($results[0]))->not->toContain('connection reset by peer');
    });

    it('includes the message only when debug is on', function () {
        [$runner] = runnerFor(new Fixture(racks: 1, unitsPerRack: 1), debug: true);

        $results = $runner->run(callsFor([['racks.boom', []]]), allowed: ['racks.boom']);

        expect($results[0]['error']['message'])->toContain('connection reset by peer');
    });

    it('refuses a query that returns a model', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        [$runner] = runnerFor($fixture);

        $results = $runner->run(
            callsFor([['racks.leak', ['rack' => $fixture->ids['racks'][0]]]]),
            allowed: ['racks.leak'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_RESULT)
            ->and($results[0])->not->toHaveKey('value');
    });

    it('refuses a batch query that returns the wrong number of results', function () {
        [$runner] = runnerFor(new Fixture(racks: 1, unitsPerRack: 1));

        $results = $runner->run(
            callsFor([['racks.miscounted', ['a' => 1]], ['racks.miscounted', ['a' => 2]]]),
            allowed: ['racks.miscounted'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_RESULT)
            ->and($results[1]['error']['code'])->toBe(QueryError::BAD_RESULT);
    });

    it('lets good calls through alongside a failing one', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 2);
        [$runner] = runnerFor($fixture);

        $results = $runner->run(
            callsFor([
                ['racks.unitLoad', ['unit' => $fixture->ids['units'][0]]],
                ['racks.boom', []],
                ['racks.unitLoad', ['unit' => $fixture->ids['units'][1]]],
            ]),
            allowed: ['racks.unitLoad', 'racks.boom'],
        );

        expect($results[0])->toHaveKey('value')
            ->and($results[1]['error']['code'])->toBe(QueryError::FAILED)
            ->and($results[2])->toHaveKey('value');
    });
});

it('returns one result per call, in the order the calls arrived', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 3);
    [$runner] = runnerFor($fixture);

    $results = $runner->run(
        callsFor([
            ['racks.unitLoad', ['unit' => $fixture->ids['units'][2]]],
            ['racks.unitLoad', ['unit' => $fixture->ids['units'][0]]],
            ['racks.unitLoad', ['unit' => $fixture->ids['units'][1]]],
        ]),
        allowed: ['racks.unitLoad'],
    );

    expect(array_column($results, 'id'))->toBe([0, 1, 2])
        ->and($results[0]['value']['unit'])->toBe($fixture->ids['units'][2])
        ->and($results[1]['value']['unit'])->toBe($fixture->ids['units'][0]);
});
