<?php

declare(strict_types=1);

/**
 * A resumable upload's claim in the database upload store on SQLite,
 * Postgres and MySQL: taken, renewed as its keep goes, fenced against a
 * request that took it over -- whichever rows each database counts.
 *
 * The Postgres and MySQL halves run in a scratch schema of their own, and
 * are skipped, saying why, with no server to run against.
 */

namespace Tinvar\Tests\Uploads\ClaimsDatabases;

use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tests\Support\Fixtures\Databases;
use Tinvar\Uploads\DatabaseUploadStore;
use Tinvar\Uploads\PendingUpload;
use Tinvar\Uploads\UploadStore;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

/**
 * A store over a fresh uploads table on $driver, with one resumable
 * session waiting in it.
 *
 * @param \Closure(DatabaseUploadStore, PdoConnection, PendingUpload, string): void $test given the store, its connection, the session and the scratch schema's name
 */
function withSession(string $driver, \Closure $test): void
{
    $run = static function (PdoConnection $connection, string $schema) use ($test): void {
        DatabaseUploadStore::define(new Schema($connection, Dialect::for($connection)));
        $store = new DatabaseUploadStore($connection);
        $session = new PendingUpload(str_repeat('s', 32), 'tinvar-partial/s', 'a', 'f', 'C', 't', 'avatars', '', '', 's', 'u@e1', 'n.pdf', '', 3, 1000, 1000 + 86_400, ownerHash: 'u');
        $store->add($session);
        $test($store, $connection, $session, $schema);
    };
    if ($driver === 'sqlite') {
        $run(PdoConnection::sqlite(':memory:', 'uploads'), 'main');

        return;
    }
    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver, $run): void {
        $run(new PdoConnection($pdo, $driver), $schema);
    });
}

// ADR 0172: MySQL counts the rows an UPDATE changed, not the rows it
// matched. The last part of a small resumable upload is claimed and fetched
// whole in one second, and its keep renews the claim to the second it was
// made: on MySQL that changed nothing, counted no row, and read as the
// claim taken over -- the upload answered "still completing", its claim
// left held, and every try refused until the lease ran out.
it('renews a claim in the second it was made', function (string $driver) {
    withSession($driver, static function (DatabaseUploadStore $store, PdoConnection $connection, PendingUpload $session): void {
        expect($store->claim($session->id, 1000))->toBeTrue()
            ->and($store->renew($session->id, 1000, 1000))->toBeTrue('renewed in the second it was made')
            ->and($store->renew($session->id, 1000, 1060))->toBeTrue()
            ->and($store->find($session->id)?->claimedAt)->toBe(1060);
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('renews no claim another request has taken over, nor one given up', function (string $driver) {
    withSession($driver, static function (DatabaseUploadStore $store, PdoConnection $connection, PendingUpload $session): void {
        $lease = UploadStore::SESSION_CLAIM_LEASE;
        expect($store->claim($session->id, 1000))->toBeTrue()
            // The keep runs past its lease, and another request takes over.
            ->and($store->claim($session->id, 1000 + $lease))->toBeTrue()
            ->and($store->renew($session->id, 1000, 1000))->toBeFalse('taken over')
            ->and($store->renew($session->id, 1000, 1000 + $lease))->toBeFalse('taken over in the second this renewal is made');

        $store->release($session->id, 1000 + $lease);
        expect($store->renew($session->id, 1000 + $lease, 1000 + $lease))->toBeFalse('given up')
            ->and($store->find($session->id)?->claimedAt)->toBeNull();
    });
})->with(['sqlite', 'pgsql', 'mysql']);

// And asked after as the row stands, not as an open transaction saw it.
it('renews no claim given up since a transaction of its own began, on MySQL', function () {
    withSession('mysql', static function (DatabaseUploadStore $store, PdoConnection $connection, PendingUpload $session, string $schema): void {
        $other = Databases::another('mysql');
        $other->exec("USE {$schema}");
        expect($store->claim($session->id, 1000))->toBeTrue();

        $connection->transaction(static function () use ($store, $other, $session): void {
            // The transaction's first read, which every plain read after it sees as of.
            expect($store->find($session->id)?->claimedAt)->toBe(1000);
            // Given up on another worker, committed.
            $other->prepare('UPDATE uploads SET claimed_at = NULL WHERE id = ?')->execute([$session->id]);

            expect($store->renew($session->id, 1000, 1000))->toBeFalse();
        });
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
