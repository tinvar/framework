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
