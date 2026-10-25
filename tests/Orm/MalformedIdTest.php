<?php

declare(strict_types=1);

/**
 * A malformed id looked up by the id column.
 *
 * Every key is a UUIDv7 the Orm minted, and on Postgres the id column is a
 * uuid: a value that is not one is refused outright, with an error quoting it,
 * and inside a transaction it aborts the transaction too. find() answered such
 * an id without asking the database; Exists, Unique and the query runner's
 * batched resolution went straight to whereIn.
 */

namespace Tinvar\Tests\Orm\MalformedId;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Query\Fixtures\RackSummary;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Exists;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';

/**
 * The fixture's SQLite database, refusing a non-UUID id the way Postgres
 * refuses one for a uuid column.
 */
final class UuidColumns implements Connection
{
    public function __construct(private readonly Connection $inner) {}

    public function select(string $sql, array $bindings = []): array
    {
        if (str_contains($sql, '"id" IN (')) {
            foreach ($bindings as $binding) {
                if (!\is_string($binding) || !Uuid::isValid($binding)) {
                    throw new \PDOException(
                        'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "'
                        . get_debug_type($binding) . ':' . var_export($binding, true) . '"',
                    );
                }
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

    public function durableTransaction(\Closure $work): mixed
    {
        return $this->inner->durableTransaction($work);
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

final readonly class PickSiteRequest extends FormRequest
{
    public function __construct(#[Exists(Site::class)] public string $siteRef) {}
}

final readonly class ClaimSiteRequest extends FormRequest
{
    public function __construct(#[Unique(Site::class)] public string $siteRef) {}
}

/** @return array{0: Orm, 1: Fixture} */
function strictOrm(int $racks = 0): array
{
    $fixture = new Fixture(racks: $racks, unitsPerRack: 1, sites: 1);

    return [new Orm(new UuidColumns($fixture->connection), new IdentityMap('test')), $fixture];
}

it('fails Exists on a malformed id without asking the database', function (string|int $given) {
    [$orm] = strictOrm();

    expect(fn () => (new Validator($orm))->validate(PickSiteRequest::class, ['siteRef' => $given]))
        ->toThrow(ValidationException::class);
})->with(['nope', "' OR 1=1 --", '0192d1e0-0000-7000-c000-000000000000', 7]);

it('still passes Exists on a well-formed id that names a row', function () {
    [$orm, $fixture] = strictOrm();

    $request = (new Validator($orm))->validate(PickSiteRequest::class, ['siteRef' => $fixture->ids['sites'][0]]);

    expect($request->siteRef)->toBe($fixture->ids['sites'][0]);
});

it('passes Unique on a malformed id, which no row can hold', function () {
    [$orm] = strictOrm();

    expect((new Validator($orm))->validate(ClaimSiteRequest::class, ['siteRef' => 'nope'])->siteRef)->toBe('nope');
});

it('still fails Unique on an id a row holds', function () {
    [$orm, $fixture] = strictOrm();

    expect(fn () => (new Validator($orm))->validate(ClaimSiteRequest::class, ['siteRef' => $fixture->ids['sites'][0]]))
        ->toThrow(ValidationException::class);
});

it('fails only the query call whose id is malformed, not the tick', function () {
    [$orm, $fixture] = strictOrm(racks: 2);
    $registry = new QueryRegistry(static fn (string $class): object => new $class());
    $registry->register(RackSummary::class);
    $runner = new QueryRunner($registry, $orm, new AllowAllGate());

    $results = $runner->run([
        ['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][0]]],
        ['id' => 1, 'name' => 'racks.summary', 'args' => ['rack' => 'not-a-uuid']],
        ['id' => 2, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][1]]],
    ], ['racks.summary']);

    expect($results[0])->toHaveKey('value')
        ->and($results[1]['error']['code'])->toBe(QueryError::NOT_FOUND)
        ->and($results[2])->toHaveKey('value');
});

it('drops malformed ids from a whereIn on the id column and keeps the rest', function () {
    [$orm, $fixture] = strictOrm(racks: 2);

    $racks = $orm->whereIn(Rack::class, 'id', ['nope', $fixture->ids['racks'][1], 3]);

    expect(array_map(static fn (Rack $r): string => $r->id, $racks))->toBe([$fixture->ids['racks'][1]]);
});
