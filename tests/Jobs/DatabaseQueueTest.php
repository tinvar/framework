<?php

declare(strict_types=1);

/**
 * The database queue on the two databases the ORM supports: what a worker
 * takes, when, and that two workers never take the same job.
 *
 * The Postgres half runs in a scratch schema of its own, and is skipped,
 * saying why, with no server to run against.
 */

namespace Tinvar\Tests\Jobs\DatabaseQueue;

use Tinvar\Orm\PdoConnection;
use Tinvar\Queue\DatabaseQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Tests\Jobs\Fixtures\AlwaysFails;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Support\Fixtures\Databases;

use function Tinvar\Tests\Jobs\Fixtures\behavesAsAQueue;
use function Tinvar\Tests\Jobs\Fixtures\coordinates;
use function Tinvar\Tests\Jobs\Fixtures\databaseQueue;
use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/Fixtures/jobs.php';
require_once __DIR__ . '/Fixtures/contract.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

// ADR 0107: an idle worker looks for a job by a read, and takes SQLite's
// write lock only to claim one it found. It waited behind every writer to
// find nothing, once per queue, three times a second.
it('looks for a job without the write lock, and takes it only to claim one', function () {
    $path = sys_get_temp_dir() . '/tinvar-peek-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $connection = PdoConnection::sqlite($path, 'worker', create: true, busyTimeoutMs: 50);
        $queue = databaseQueue($connection);
        $queue->push(new PendingJob('later', 'default', 'payload', 500));

        // Another connection holds the write lock, as an order being placed does.
        $writer = PdoConnection::sqlite($path, 'writer');
        $writer->pdo()->exec('BEGIN IMMEDIATE');
        $writer->pdo()->exec("INSERT INTO jobs (id, queue, payload, attempts, available_at, queued_at) VALUES ('w', 'mail', 'p', 0, 999999, 1)");

        // Nothing due: found so by a read, which no writer's lock refuses.
        expect($queue->reserve(['mail', 'default'], 100, 60))->toBeNull();

        // Work to claim: it waits for the lock, as a claim must.
        expect(static fn () => $queue->reserve(['default'], 600, 60))->toThrow(\PDOException::class);

        $writer->pdo()->exec('COMMIT');
        expect($queue->reserve(['mail', 'default'], 600, 60)?->id)->toBe('later')
            ->and($queue->reserve(['mail', 'default'], 600, 60))->toBeNull('held, and the writer\'s is not due');
    } finally {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }
});

it('claims, inside a transaction of the caller\'s, what that transaction pushed', function () {
    $connection = PdoConnection::sqlite(':memory:', 'own');
    $queue = databaseQueue($connection);

    $reserved = $connection->transaction(static function () use ($queue) {
        $queue->push(new PendingJob('mine', 'default', 'payload', 100));

        return $queue->reserve(['default'], 100, 60);
    });

    expect($reserved?->id)->toBe('mine');
});

it('queues, reserves, releases and fails on SQLite', function () {
    $path = sys_get_temp_dir() . '/tinvar-queue-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        behavesAsAQueue(databaseQueue(PdoConnection::sqlite($path, 'queue', create: true)), new DatabaseQueue(PdoConnection::sqlite($path, 'other')));
    } finally {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }
});

/**
 * The queue on $connection, its tables made, with a second on $connect's
 * connection as another worker would hold.
 *
 * @param \Closure(): PdoConnection $connect
 * @return array{DatabaseQueue, DatabaseQueue}
 */
function pair(PdoConnection $connection, \Closure $connect): array
{
    $queue = databaseQueue($connection);
    DatabaseQueue::defineCoordination(schemaOf($connection));
    DatabaseQueue::defineSlidingRates(schemaOf($connection));

    return [$queue, new DatabaseQueue($connect())];
}

/**
 * Locks, windows and batches on one driver. Held inside a transaction, a
 * lock is refused without failing it: the statement after still runs
 * (Postgres aborts on a violation).
 *
 * @param \Closure(): PdoConnection $connect
 */
function coordinatesOn(PdoConnection $connection, \Closure $connect): void
{
    [$queue, $other] = pair($connection, $connect);
    coordinates($queue, $other, static fn (\Closure $work): mixed => $connection->transaction(static function () use ($work, $connection): mixed {
        $held = $work();
        expect($connection->select('SELECT 1 AS one')[0]['one'] ?? null)->toBe(1);

        return $held;
    }));
}

it('keeps locks, windows and batches on SQLite', function () {
    $path = sys_get_temp_dir() . '/tinvar-coordination-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        coordinatesOn(PdoConnection::sqlite($path, 'first', create: true), static fn (): PdoConnection => PdoConnection::sqlite($path, 'second'));
    } finally {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }
});

it('keeps locks, windows and batches on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
        coordinatesOn(new PdoConnection($pdo, 'pgsql'), static function () use ($schema): PdoConnection {
            $other = Databases::another('pgsql');
            $other->exec("SET search_path TO {$schema}");

            return new PdoConnection($other, 'pgsql-other');
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('queues, reserves, releases and fails on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
        $other = Databases::another('pgsql');
        $other->exec("SET search_path TO {$schema}");
        behavesAsAQueue(databaseQueue(new PdoConnection($pdo, 'pgsql')), new DatabaseQueue(new PdoConnection($other, 'pgsql-other')));
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('queues, keeps locks, windows and batches on MySQL', function () {
    foreach ([false, true] as $coordinating) {
        Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $schema) use ($coordinating): void {
            $pdo->exec("USE {$schema}");
            $connect = static function () use ($schema): PdoConnection {
                $other = Databases::another('mysql');
                $other->exec("USE {$schema}");

                return new PdoConnection($other, 'mysql-other');
            };
            $connection = new PdoConnection($pdo, 'mysql');
            $coordinating ? coordinatesOn($connection, $connect) : behavesAsAQueue(databaseQueue($connection), new DatabaseQueue($connect()));
        });
    }
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('runs jobs through a worker on a SQLite queue, retrying and failing them', function () {
    $fixture = new TenantFixture();
    $queue = databaseQueue($fixture->connection);
    $h = new Harness($queue, $fixture);

    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new RecordContext(note: 'db')));
    $h->dispatcher->dispatch(new AlwaysFails('db'));
    $h->work();
    $h->now += 5;
    $h->work();
    $h->now += 30;
    $h->work();

    expect($h->journal()->entries[0])->toMatchArray(['note' => 'db', 'tenant' => 'acme'])
        ->and(\count($h->journal()->entries))->toBe(4)
        ->and($queue->size('default'))->toBe(0)
        ->and($queue->failed())->toHaveCount(1)
        ->and($queue->failed()[0]->error)->toContain('failing on purpose: db');
});
