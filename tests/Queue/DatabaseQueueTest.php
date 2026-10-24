<?php

declare(strict_types=1);

/**
 * The database queue on the two databases the ORM supports: what a worker
 * takes, when, and that two workers never take the same job.
 *
 * The Postgres half runs in a scratch schema of its own, and is skipped,
 * saying why, with no server to run against.
 */

namespace Tinvar\Tests\Queue\DatabaseQueue;

use Tinvar\Orm\PdoConnection;
use Tinvar\Queue\DatabaseQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Tests\Jobs\Fixtures\AlwaysFails;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

/**
 * What every driver must do, run against one.
 *
 * @param \Closure(): PdoConnection $connect a second connection to the same database
 */
function behavesAsAQueue(PdoConnection $connection, \Closure $connect): void
{
    $queue = DatabaseQueue::install($connection);
    // Installing again changes nothing: it is what every boot does.
    DatabaseQueue::install($connection);

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
    $queue = DatabaseQueue::install($fixture->connection);
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
