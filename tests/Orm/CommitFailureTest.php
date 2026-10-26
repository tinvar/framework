<?php

declare(strict_types=1);

/**
 * A commit the database refuses. SQLite leaves the transaction open when a
 * COMMIT fails -- a deferred constraint, a writer it could not wait out --
 * and transaction() used to let it: the connection stayed inside a
 * transaction, and on a worker the next request's writes joined it.
 */

namespace Tinvar\Tests\Orm\CommitFailure;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\RequestScope;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

/** A PDO whose commit is refused, and whose next few rollbacks may be too. */
final class RefusedCommit extends \PDO
{
    public int $refuseRollBacks = 0;

    public function commit(): bool
    {
        throw new \PDOException('commit refused');
    }

    public function rollBack(): bool
    {
        if ($this->refuseRollBacks > 0) {
            $this->refuseRollBacks--;

            throw new \PDOException('rollback refused');
        }

        return parent::rollBack();
    }
}

/** @return array{PdoConnection, RefusedCommit} */
function refusing(): array
{
    $pdo = new RefusedCommit('sqlite::memory:');
    $connection = new PdoConnection($pdo);
    $connection->execute('CREATE TABLE hits (n INTEGER)');

    return [$connection, $pdo];
}

it('rolls back when the commit is refused, and says why', function () {
    [$connection] = refusing();

    expect(fn () => $connection->transaction(static fn (): int => $connection->execute('INSERT INTO hits (n) VALUES (1)')))
        ->toThrow(\PDOException::class, 'commit refused');

    expect($connection->inTransaction())->toBeFalse()
        ->and($connection->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(0);
});

it('reports the refused commit, not a rollback that failed after it', function () {
    [$connection, $pdo] = refusing();
    $pdo->refuseRollBacks = 1;

    expect(fn () => $connection->transaction(static fn (): int => $connection->execute('INSERT INTO hits (n) VALUES (1)')))
        ->toThrow(\PDOException::class, 'commit refused');
});

// What transaction() could not close, the end of the request does: the next
// request on the worker must not start inside this one's transaction.
it('rolls back a transaction still open when the request ends', function () {
    [$connection, $pdo] = refusing();
    $pdo->refuseRollBacks = 1;
    $orm = new Orm($connection, new IdentityMap('test'));

    try {
        $connection->transaction(static fn (): int => $connection->execute('INSERT INTO hits (n) VALUES (1)'));
    } catch (\PDOException) {
        // the refused commit, with the rollback after it refused as well
    }
    $open = $connection->inTransaction();

    RequestScope::standard($orm, new AuthContext(), new PolicyRegistry())->reset();

    expect($open)->toBeTrue('the double left it open')
        ->and($connection->inTransaction())->toBeFalse()
        ->and($connection->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(0);
});

/**
 * Runs $test against a connection to a Postgres schema of its own, with a
 * table of keys to write twice.
 *
 * @param \Closure(PdoConnection): void $test
 */
function onPostgres(\Closure $test): void
{
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo) use ($test): void {
        $pdo->exec('CREATE TABLE keys (n INTEGER PRIMARY KEY)');

        $test(new PdoConnection($pdo, 'pgsql'));
    });
}

function keys(PdoConnection $connection): int
{
    return (int) $connection->select('SELECT COUNT(*) AS n FROM keys')[0]['n'];
}

// A statement that fails aborts a Postgres transaction, and a COMMIT of an
// aborted transaction rolls it back -- which PDO reports as a commit. Caught
// inside, the failure left transaction() returning, its afterCommit()
// callbacks running, and nothing written. SQLite undoes the statement alone
// and commits the rest.
describe('on Postgres', function () {
    it('rolls back, and says so, a transaction a failed statement aborted', function () {
        onPostgres(function (PdoConnection $connection): void {
            $orm = new Orm($connection, new IdentityMap('test'));
            $announced = false;

            expect(fn () => $orm->transaction(static function () use ($orm, $connection, &$announced): void {
                $connection->execute('INSERT INTO keys (n) VALUES (1)');
                $orm->afterCommit(static function () use (&$announced): void {
                    $announced = true;
                });
                try {
                    $connection->execute('INSERT INTO keys (n) VALUES (1)');
                } catch (\PDOException) {
                    // the duplicate, caught and carried on from
                }
            }))->toThrow(\PDOException::class, 'rolled back, not committed');

            expect($announced)->toBeFalse()
                ->and($connection->inTransaction())->toBeFalse()
                ->and(keys($connection))->toBe(0);
        });
    });

    // What the message advises: the savepoint takes the failure back alone,
    // and the transaction around it commits.
    it('commits around a failure a nested transaction took back', function () {
        onPostgres(function (PdoConnection $connection): void {
            $connection->transaction(static function () use ($connection): void {
                $connection->execute('INSERT INTO keys (n) VALUES (1)');
                try {
                    $connection->transaction(static fn (): int => $connection->execute('INSERT INTO keys (n) VALUES (1)'));
                } catch (\PDOException) {
                }
                $connection->execute('INSERT INTO keys (n) VALUES (2)');
            });

            expect(keys($connection))->toBe(2);
        });
    });

    // A read-only scope recovers from its own failures (ADR 0022), so the
    // transaction it was opened in is not aborted by them.
    it('commits around a failure a read-only scope recovered', function () {
        onPostgres(function (PdoConnection $connection): void {
            $connection->transaction(static function () use ($connection): void {
                $connection->execute('INSERT INTO keys (n) VALUES (1)');
                $connection->readOnly(static function () use ($connection): void {
                    try {
                        $connection->select("SELECT 'not-a-uuid'::uuid");
                    } catch (\PDOException) {
                    }
                });
            });

            expect(keys($connection))->toBe(1);
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
