<?php

declare(strict_types=1);

/**
 * QueryRunner resolves a tick's model arguments with one whereIn, which
 * bypassed Orm::find()'s UUID-shape guard. Postgres refuses a malformed value
 * for a uuid column, so one bad id failed every call in the tick and quoted
 * the caller's input in the exception.
 */

namespace Tinvar\Tests\Query\MalformedId;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Query\Fixtures\RackSummary;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';

/** SQLite with Postgres's refusal of a malformed uuid. */
final class UuidColumns implements Connection
{
    public function __construct(private readonly Connection $inner) {}

    public function select(string $sql, array $bindings = []): array
    {
        foreach ($bindings as $binding) {
            if (\is_string($binding) && !Uuid::isValid($binding)) {
                throw new \PDOException("SQLSTATE[22P02]: invalid input syntax for type uuid: \"{$binding}\"");
            }
        }

        return $this->inner->select($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->inner->execute($sql, $bindings);
    }

    public function statementCount(): int
    {
        return $this->inner->statementCount();
    }

    public function resetStatementCount(): void
    {
        $this->inner->resetStatementCount();
    }

    public function statementLog(): array
    {
        return $this->inner->statementLog();
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function transaction(\Closure $work): mixed
    {
        return $this->inner->transaction($work);
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function readOnly(\Closure $work): mixed
    {
        return $this->inner->readOnly($work);
    }

    public function isReadOnly(): bool
    {
        return $this->inner->isReadOnly();
    }

    public function rollBackOpenTransaction(): bool
    {
        return $this->inner->rollBackOpenTransaction();
    }
}

it('answers a malformed id as not found, without failing the rest of the tick', function (string $bad) {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm(new UuidColumns($fixture->connection), new IdentityMap('test'));
    $registry = new QueryRegistry();
    $registry->register(RackSummary::class);

    $results = (new QueryRunner($registry, $orm, new AllowAllGate()))->run([
        ['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][0]]],
        ['id' => 1, 'name' => 'racks.summary', 'args' => ['rack' => $bad]],
        ['id' => 2, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][1]]],
    ], ['racks.summary']);

    expect($results[0])->toHaveKey('value')
        ->and($results[2])->toHaveKey('value')
        ->and($results[1]['error']['code'] ?? null)->toBe(QueryError::NOT_FOUND)
        ->and(json_encode($results))->not->toContain('22P02');
})->with(['not-a-uuid', "' OR 1=1 --", '01a166b1-0816-74c0-b4d5-2e9eb49b0838 x']);
