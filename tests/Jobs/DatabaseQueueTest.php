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

use function Tinvar\Tests\Jobs\Fixtures\databaseQueue;

require_once __DIR__ . '/Fixtures/jobs.php';
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

/**
 * What every driver must do, run against one.
 *
 * @param \Closure(): PdoConnection $connect a second connection to the same database
 */
function behavesAsAQueue(PdoConnection $connection, \Closure $connect): void
{
    $queue = databaseQueue($connection);

    $queue->push(new PendingJob('a', 'default', 'payload-a', 100));
    $queue->push(new PendingJob('b', 'default', 'payload-b', 200));
    $queue->push(new PendingJob('c', 'mail', 'payload-c', 100));

    expect($queue->size('default'))->toBe(2)
        // Nothing before its time.
        ->and($queue->reserve(['default'], 99, 60))->toBeNull();

    // The first queue named that has one, oldest first.
    $first = $queue->reserve(['mail', 'default'], 150, 60);
    expect($first?->id)->toBe('c')->and($first?->attempts)->toBe(1);

    // A second worker, on its own connection, cannot take a held job.
    $other = new DatabaseQueue($connect());
    $second = $other->reserve(['default'], 150, 60);
    expect($second?->id)->toBe('a')
        ->and($queue->reserve(['default'], 150, 60))->toBeNull('b is not available yet, and a is held');

    // Released with a delay, then taken again on a later attempt.
    \assert($second !== null);
    $other->release($second, 300);
    $b = $queue->reserve(['default'], 250, 60);
    expect($b?->id)->toBe('b');
    \assert($b !== null);
    $queue->delete($b);
    $again = $queue->reserve(['default'], 300, 60);
    expect($again?->id)->toBe('a')->and($again?->attempts)->toBe(2);

    // A lapsed reservation is taken by another, and the first holder can
    // then neither delete nor fail it.
    \assert($again !== null);
    $taken = $other->reserve(['default'], 361, 60);
    expect($taken?->id)->toBe('a');
    $queue->delete($again);
    $queue->fail($again, 'late', 400);
    expect($queue->size('default'))->toBe(1)->and($queue->failed())->toBe([]);

    \assert($taken !== null);
    $other->fail($taken, 'boom', 400);
    expect($queue->failed())->toHaveCount(1)
        ->and($queue->failed()[0]->error)->toBe('boom')
        ->and($queue->retry('a', 500))->toBeTrue()
        ->and($queue->retry('a', 500))->toBeFalse()
        ->and($queue->reserve(['default'], 500, 60)?->attempts)->toBe(1);
}

it('queues, reserves, releases and fails on SQLite', function () {
    $path = sys_get_temp_dir() . '/tinvar-queue-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        behavesAsAQueue(
            PdoConnection::sqlite($path, 'queue', create: true),
            static fn (): PdoConnection => PdoConnection::sqlite($path, 'other'),
        );
    } finally {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }
});

/**
 * Locks, windows and batches (ADR 0100) on one driver, with a second
 * connection as another worker would hold.
 *
 * @param \Closure(): PdoConnection $connect
 */
function coordinates(PdoConnection $connection, \Closure $connect): void
{
    $queue = databaseQueue($connection);
    DatabaseQueue::defineCoordination(new \Tinvar\Orm\Schema\Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection)));
    $other = new DatabaseQueue($connect());

    // A lock: one holder at a time, across connections, until it lapses.
    expect($queue->lock('k', 'job-1', 200, 100))->toBeNull()
        ->and($other->lock('k', 'job-2', 300, 150))->toBe('job-1')
        ->and($other->unlock('k', 'job-2'))->toBeFalse()
        // Held inside a transaction, refused without failing it: the
        // statement after still runs (Postgres aborts on a violation).
        ->and($connection->transaction(static function () use ($queue, $connection): array {
            $held = $queue->lock('k', 'job-3', 300, 150);

            return [$held, $connection->select('SELECT 1 AS one')[0]['one']];
        }))->toBe(['job-1', 1])
        ->and($other->lock('k', 'job-2', 400, 200))->toBeNull()
        ->and($queue->unlock('k', 'job-1'))->toBeFalse()
        ->and($other->unlock('k', 'job-2'))->toBeTrue()
        ->and($queue->lock('k', 'job-4', 500, 210))->toBeNull();

    // A window: its runs across both, then the next.
    expect($queue->slot('mail', 2, 60, 120))->toBeTrue()
        ->and($other->slot('mail', 2, 60, 130))->toBeTrue()
        ->and($queue->slot('mail', 2, 60, 170))->toBeFalse();
    $other->unslot('mail', 60, 170);
    expect($queue->slot('mail', 2, 60, 175))->toBeTrue()
        ->and($queue->slot('mail', 2, 60, 180))->toBeTrue()
        ->and($other->slot('mail', 2, 60, 181))->toBeTrue();

    // A batch: counted to its end once, cancelled at its first failure.
    $callbacks = [['on' => \Tinvar\Queue\BatchRecord::FINALLY, 'job' => new PendingJob('cb', 'default', 'sealed', 0)]];
    $queue->addBatch(new \Tinvar\Queue\BatchRecord('b-1', 'acme', '', '', 3, 3, 0, false, 100, callbacks: $callbacks));
    $first = $other->batchJobFailed('b-1', 'j-1', 110);
    // Counted once: run again, it moves nothing.
    $again = $queue->batchJobDone('b-1', 'j-1', 115);
    $second = $queue->batchJobDone('b-1', 'j-2', 120);
    $third = $other->batchJobDone('b-1', 'j-3', 130);
    expect($again?->batch->pending)->toBe(2)
        ->and($again?->finished)->toBeFalse();
    expect($first?->firstFailure)->toBeTrue()
        ->and($first?->batch->cancelledAt)->toBe(110)
        ->and($second?->finished)->toBeFalse()
        ->and($second?->firstFailure)->toBeFalse()
        ->and($third?->finished)->toBeTrue()
        ->and($third?->batch->pending)->toBe(0)
        ->and($third?->batch->failed)->toBe(1)
        ->and($third?->batch->callbacksFor(\Tinvar\Queue\BatchRecord::FINALLY, 130)[0]->payload ?? null)->toBe('sealed')
        // Past its end, nothing moves and nothing finishes twice.
        ->and($queue->batchJobDone('b-1', 'j-4', 140)?->finished)->toBeFalse();

    $queue->addBatch(new \Tinvar\Queue\BatchRecord('b-2', 'acme', '', '', 2, 2, 0, true, 100));
    expect($queue->batchJobFailed('b-2', 'j-5', 110)?->batch->cancelledAt)->toBeNull();

    // Pruned: the finished batch and the lapsed lock.
    expect($queue->prune(600, 1_000))->toBe(2)
        ->and($queue->batch('b-1'))->toBeNull()
        ->and($queue->batch('b-2'))->not->toBeNull();
}

it('keeps locks, windows and batches on SQLite', function () {
    $path = sys_get_temp_dir() . '/tinvar-coordination-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        coordinates(PdoConnection::sqlite($path, 'first', create: true), static fn (): PdoConnection => PdoConnection::sqlite($path, 'second'));
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
        coordinates(new PdoConnection($pdo, 'pgsql'), static function () use ($schema): PdoConnection {
            $other = Databases::another('pgsql');
            $other->exec("SET search_path TO {$schema}");

            return new PdoConnection($other, 'pgsql-other');
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('queues, reserves, releases and fails on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
        behavesAsAQueue(new PdoConnection($pdo, 'pgsql'), static function () use ($schema): PdoConnection {
            $other = Databases::another('pgsql');
            $other->exec("SET search_path TO {$schema}");

            return new PdoConnection($other, 'pgsql-other');
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

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
