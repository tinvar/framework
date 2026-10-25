<?php

declare(strict_types=1);

/**
 * "Loaders and queries run in a read-only transaction" -- in the database,
 * not only in PHP.
 *
 * The ORM refused save(), delete() and transaction() inside a loader, and
 * nothing below it: `$orm->connection()->execute()` from a loader wrote, and
 * so did raw PDO. These run on SQLite, where the scope is query_only on the
 * request's own connection; ReadOnlyTransactionPostgresTest runs the same
 * guarantees against a READ ONLY transaction.
 */

namespace Tinvar\Tests\Orm\ReadOnlyTransaction;

use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Http\Request;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Query\Fixtures\RackSummary;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';

/**
 * The fixture's connection, noting for every statement whether the
 * connection was read-only when it ran, and counting the read-only
 * transactions begun.
 */
final class Watched implements Connection
{
    /** @var list<array{string, bool}> */
    public array $seen = [];

    public int $transactions = 0;

    public function __construct(private readonly Connection $inner) {}

    public function select(string $sql, array $bindings = []): array
    {
        $this->seen[] = [$sql, $this->inner->isReadOnly()];

        return $this->inner->select($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->seen[] = [$sql, $this->inner->isReadOnly()];

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
        if (!$this->inner->isReadOnly()) {
            $this->transactions++;
        }

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

final readonly class RenamedData extends Data
{
    public function __construct(public int $renamed) {}
}

/** A query body that writes around the ORM, as raw SQL through its connection. */
#[Query(name: 'racks.rename'), NoAuthorization(reason: 'fixture: exercises the read-only transaction, not access control')]
final class RenameRacks
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(): RenamedData
    {
        return new RenamedData($this->orm->connection()->execute("UPDATE racks SET name = 'renamed'"));
    }
}

/** A loader that writes around the ORM, through the PDO handle beneath it. */
#[Page('/racks', view: 'racks/index', name: 'racks.index')]
final class WritingPage
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(): RenamedData
    {
        $connection = $this->orm->connection();
        if (!$connection instanceof PdoConnection) {
            throw new \LogicException('The fixture connection is a PdoConnection.');
        }

        return new RenamedData((int) $connection->pdo()->exec("UPDATE racks SET name = 'renamed'"));
    }
}

final readonly class RackCountData extends Data
{
    public function __construct(public int $racks) {}
}

final readonly class UnitCountData extends Data
{
    public function __construct(public int $units) {}
}

#[Layout('/counts', view: 'counts/layout')]
final class CountingLayout
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(): RackCountData
    {
        return new RackCountData(\count($this->orm->all(Rack::class)));
    }
}

#[Page('/counts', view: 'counts/index', name: 'counts.index')]
final class CountingPage
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(): UnitCountData
    {
        return new UnitCountData((int) $this->orm->connection()->select('SELECT COUNT(*) AS c FROM units')[0]['c']);
    }
}

/** @return list<string> */
function rackNames(Fixture $fixture): array
{
    return array_column(
        $fixture->connection->pdo()->query('SELECT name FROM racks ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC),
        'name',
    );
}

function writable(Fixture $fixture): bool
{
    try {
        $fixture->connection->pdo()->exec('CREATE TABLE IF NOT EXISTS probe (id INTEGER)');
        $fixture->connection->pdo()->exec('DROP TABLE probe');

        return true;
    } catch (\PDOException) {
        return false;
    }
}

it('refuses a raw write through the connection, in the database', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    try {
        $orm->readOnly(static fn (): int => $orm->connection()->execute("UPDATE racks SET name = 'renamed'"));
        $this->fail('The write went through.');
    } catch (ReadOnlyViolation $e) {
        expect($e->getMessage())->toContain('database refused a write')
            ->and($e->getPrevious())->toBeInstanceOf(\PDOException::class)
            ->and($e->getPrevious()?->getMessage())->toContain('readonly database');
    }

    expect(rackNames($fixture))->toBe(['R-01', 'R-02']);
});

it('refuses a write through raw PDO, which never passes the ORM', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 3);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    expect(fn () => $orm->readOnly(static fn (): int|false => $fixture->connection->pdo()->exec('DELETE FROM units')))
        ->toThrow(\PDOException::class, 'readonly database')
        ->and((int) $fixture->connection->pdo()->query('SELECT COUNT(*) FROM units')->fetchColumn())->toBe(6);
});

// The database is told when the scope opens, not at its first statement, so
// a handle taken before the scope -- a service built at boot with the PDO in
// it -- is refused too.
it('refuses a write through a PDO handle taken before the scope opened', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $pdo = $fixture->connection->pdo();

    expect(fn () => $orm->readOnly(static fn (): int|false => $pdo->exec("UPDATE racks SET name = 'renamed'")))
        ->toThrow(\PDOException::class, 'readonly database')
        ->and(rackNames($fixture))->toBe(['R-01']);
});

it('reads as before inside the scope, and writes again once it ends', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $names = $orm->readOnly(static fn (): array => array_map(
        static fn (Rack $rack): string => $rack->name,
        $orm->all(Rack::class, 'name'),
    ));

    expect($names)->toBe(['R-01', 'R-02'])
        ->and($fixture->connection->isReadOnly())->toBeFalse()
        ->and($fixture->connection->execute("UPDATE racks SET name = 'renamed'"))->toBe(2);
});

// What opens and closes the scope is not the application's work: query
// budgets and the batching assertions count from the statements it issues.
it('counts no statement of its own', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $orm->readOnly(static fn (): array => $orm->all(Rack::class));

    expect($fixture->connection->statementCount())->toBe(1)
        ->and($fixture->connection->statementLog())->toHaveCount(1);
});

// A connection holds one transaction, and a render's fibers share the
// connection: the scope has to last from the first fiber in to the last
// one out, in whatever order they finish, and not end when the first does.
it('stays read-only until the last of the fibers inside it leaves', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $first = new \Fiber(static fn (): mixed => $orm->readOnly(static fn (): mixed => \Fiber::suspend()));
    $second = new \Fiber(static fn (): mixed => $orm->readOnly(static fn (): mixed => \Fiber::suspend()));
    $first->start();
    $second->start();

    $first->resume();
    expect($first->isTerminated())->toBeTrue()
        ->and($fixture->connection->isReadOnly())->toBeTrue('the second fiber is still inside')
        ->and(writable($fixture))->toBeFalse();

    $second->resume();
    expect($fixture->connection->isReadOnly())->toBeFalse()
        ->and(writable($fixture))->toBeTrue();
});

// The PHP check is per fiber, the database's per connection. The main
// context is not read-only in PHP while a fiber is suspended inside a scope,
// but its statements run on the same connection, and the database refuses.
it('refuses a write from outside every scope while a fiber is inside one', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $reader = new \Fiber(static fn (): mixed => $orm->readOnly(static fn (): mixed => \Fiber::suspend()));
    $reader->start();

    expect($orm->isReadOnly())->toBeFalse('PHP lets the main context write')
        ->and(fn () => $orm->connection()->execute("UPDATE racks SET name = 'renamed'"))
        ->toThrow(ReadOnlyViolation::class, 'database refused');

    $reader->resume();
    expect(rackNames($fixture))->toBe(['R-01']);
});

// Level 2 batching issues its loads from the main context, between fibers,
// while every fiber that asked is suspended inside its scope.
it('covers the batched relation loads a render\'s fibers wait on', function () {
    $fixture = new Fixture(racks: 3, unitsPerRack: 2);
    $watched = new Watched($fixture->connection);
    $orm = new Orm($watched, new IdentityMap('test'));
    $racks = $orm->all(Rack::class, 'name');
    $watched->seen = [];

    $scheduler = $orm->beginRender();
    $counts = $scheduler->run(array_map(
        static fn (Rack $rack): \Closure => static fn (): int => $orm->readOnly(static fn (): int => $rack->units->count()),
        $racks,
    ));
    $orm->endRender();

    expect($counts)->toBe([2, 2, 2])
        ->and($scheduler->rounds())->toBe(1)
        ->and($watched->seen)->toHaveCount(1, 'one batched load for the three racks')
        ->and($watched->seen[0][1])->toBeTrue('and it ran read-only');
});

// One query writing must fail that call alone: the tick's other calls, and
// the transaction they share, carry on.
it('fails only the query that wrote; the rest of the tick reads on', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);
    $watched = new Watched($fixture->connection);
    $orm = new Orm($watched, new IdentityMap('test'));
    $registry = new QueryRegistry(static fn (string $class): object => $class === RenameRacks::class ? new RenameRacks($orm) : new $class());
    $registry->registerAll([RackSummary::class, RenameRacks::class]);

    $results = (new QueryRunner($registry, $orm, new AllowAllGate()))->run([
        ['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][0]]],
        ['id' => 1, 'name' => 'racks.rename', 'args' => []],
        ['id' => 2, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][1]]],
    ], ['racks.summary', 'racks.rename']);

    // The bodies' statements: the write, and the relation load the
    // summaries batch. Resolving the calls' models comes before the tick.
    $bodies = array_values(array_filter(
        $watched->seen,
        static fn (array $s): bool => str_starts_with($s[0], 'UPDATE') || str_contains($s[0], '"units"'),
    ));

    expect($results[0]['value'] ?? null)->toBe(['name' => 'R-01', 'units' => 1])
        ->and($results[2]['value'] ?? null)->toBe(['name' => 'R-02', 'units' => 1])
        ->and($results[1]['error']['code'] ?? null)->toBe(QueryError::FAILED)
        ->and(rackNames($fixture))->toBe(['R-01', 'R-02'])
        ->and($bodies)->toHaveCount(2)
        ->and(array_column($bodies, 1))->toBe([true, true], 'both ran read-only');
});

it('refuses a loader that writes through raw PDO', function () {
    $fixture = new Fixture(racks: 2, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $routes = new RouteRegistry();
    $routes->registerAll([WritingPage::class]);
    $loaders = new LoaderRunner($orm, static fn (string $class): object => new $class($orm), new AllowAllGate());
    $chain = $routes->match('/racks') ?? throw new \LogicException('No route.');

    expect(fn () => $loaders->run($chain, Request::get('/racks')))
        ->toThrow(\PDOException::class, 'readonly database')
        ->and(rackNames($fixture))->toBe(['R-01', 'R-02'])
        ->and($fixture->connection->isReadOnly())->toBeFalse('the scope ended with the throw');
});

// Each loader, body and encode is a scope; begun and ended one by one, each
// was a transaction of its own, two statements apiece -- fifty a catalog.
it('begins one transaction for a loader chain, and one for a query tick', function () {
    $fixture = new Fixture(racks: 3, unitsPerRack: 1);
    $watched = new Watched($fixture->connection);
    $orm = new Orm($watched, new IdentityMap('test'));

    $routes = new RouteRegistry();
    $routes->registerAll([CountingLayout::class, CountingPage::class]);
    $loaders = new LoaderRunner($orm, static fn (string $class): object => new $class($orm), new AllowAllGate());
    $loaded = $loaders->run($routes->match('/counts') ?? throw new \LogicException('No route.'), Request::get('/counts'));

    expect($loaded->page)->toEqual(new UnitCountData(3))
        ->and($watched->transactions)->toBe(1, 'the layout and the page in one');

    $watched->transactions = 0;
    $registry = new QueryRegistry();
    $registry->register(RackSummary::class);
    $results = (new QueryRunner($registry, $orm, new AllowAllGate()))->run(array_map(
        static fn (int $i): array => ['id' => $i, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][$i]]],
        [0, 1, 2],
    ), ['racks.summary']);

    expect(array_column($results, 'value'))->toHaveCount(3)
        ->and($watched->transactions)->toBe(1, 'three bodies and three encodes in one');
});

// A scope inside a transaction the caller holds -- a render run inside an
// action's transaction, a test's -- refuses writes for its length and leaves
// the caller's transaction as it found it.
it('opens inside a transaction the caller holds, and leaves it writable', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $connection = $fixture->connection;

    $connection->transaction(static function () use ($orm, $connection): void {
        $connection->execute("UPDATE racks SET name = 'first'");

        $seen = $orm->readOnly(static function () use ($connection): string {
            expect(fn () => $connection->execute("UPDATE racks SET name = 'refused'"))
                ->toThrow(ReadOnlyViolation::class);

            return (string) $connection->select('SELECT name FROM racks')[0]['name'];
        });

        expect($seen)->toBe('first', 'the scope reads what the transaction wrote');
        $connection->execute("UPDATE racks SET name = 'second'");
    });

    expect(rackNames($fixture))->toBe(['second']);
});

// A render that stalls leaves fibers suspended, and they are destroyed once
// nothing holds them -- by the cycle collector, since a suspended fiber's
// own frames refer to it. Destroying one unwinds it, and its scope goes.
it('closes the scope of a fiber destroyed while suspended in it', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $fiber = new \Fiber(static fn (): mixed => $orm->readOnly(static fn (): mixed => \Fiber::suspend()));
    $fiber->start();
    expect($fixture->connection->isReadOnly())->toBeTrue();

    $fiber = null;
    gc_collect_cycles();

    expect($fixture->connection->isReadOnly())->toBeFalse()
        ->and(writable($fixture))->toBeTrue();
});

// The reset contract: a fiber suspended inside a scope and never resumed
// left the connection refusing writes, and on a worker the next request
// would have inherited it.
it('ends a scope the reset finds open, and one unwound later cannot end the next', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $abandoned = new \Fiber(static fn (): mixed => $orm->readOnly(static fn (): mixed => \Fiber::suspend()));
    $abandoned->start();

    $fixture->connection->rollBackOpenTransaction();
    $orm->flush();
    expect($fixture->connection->isReadOnly())->toBeFalse()
        ->and(writable($fixture))->toBeTrue();

    // The next request opens a scope; the abandoned fiber is unwound inside
    // it, running its finally blocks -- which must not close this one.
    $orm->readOnly(static function () use (&$abandoned, $fixture): void {
        $abandoned = null;
        gc_collect_cycles();

        expect($fixture->connection->isReadOnly())->toBeTrue()
            ->and(writable($fixture))->toBeFalse();
    });

    expect(writable($fixture))->toBeTrue();
});
