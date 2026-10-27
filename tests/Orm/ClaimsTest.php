<?php

declare(strict_types=1);

/**
 * The claims workers race for in the application's database -- a webhook's
 * or a handoff's nonce, a scheduled task's minute -- on each database: one
 * insert of a key counts, and the rest find it taken. SQLite and Postgres
 * say so with ON CONFLICT DO NOTHING; MySQL, which has none, with INSERT
 * IGNORE.
 *
 * The Postgres and MySQL halves run in a scratch schema or database of
 * their own, and are skipped, saying why, with no server to run against.
 */

namespace Tinvar\Tests\Orm\Claims;

use Tinvar\Console\DatabaseScheduleStore;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\MySqlDialect;
use Tinvar\Orm\Schema\PostgresDialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Tests\Support\Fixtures\Databases;
use Tinvar\Http\DatabaseNonceStore;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

function claimsHoldOn(PdoConnection $connection): void
{
    $schema = new Schema($connection, Dialect::for($connection));
    DatabaseNonceStore::define($schema);
    DatabaseScheduleStore::define($schema);

    $nonces = new DatabaseNonceStore($connection);
    $other = new DatabaseNonceStore($connection);
    $nonce = str_repeat('n', DatabaseNonceStore::MAX_NONCE_BYTES);

    expect($nonces->claim('Hook', $nonce, 200, 100))->toBeTrue()
        ->and($other->claim('Hook', $nonce, 200, 100))->toBeFalse()
        // Another scope's nonce is its own.
        ->and($other->claim('Other', $nonce, 200, 100))->toBeTrue()
        // A longer one, which the column would hold cut short and so take
        // for the one claimed, is refused rather than claimed.
        ->and($other->claim('Hook2', $nonce . 'x', 200, 100))->toBeFalse()
        ->and($other->claim('Hook2', $nonce, 200, 100))->toBeTrue();

    // Released, it can be claimed again; expired, it is swept and can too.
    $nonces->release('Hook', $nonce);
    expect($other->claim('Hook', $nonce, 200, 100))->toBeTrue()
        ->and($nonces->claim('Hook', $nonce, 400, 300))->toBeTrue();

    $runs = new DatabaseScheduleStore($connection);
    $otherRuns = new DatabaseScheduleStore($connection);
    $task = str_repeat('T', DatabaseScheduleStore::MAX_TASK_BYTES);

    expect($runs->claim($task, 600))->toBeTrue()
        ->and($otherRuns->claim($task, 600))->toBeFalse()
        ->and($otherRuns->claim($task, 660))->toBeTrue()
        ->and(fn () => $runs->claim($task . 'x', 600))->toThrow(\InvalidArgumentException::class, '255 bytes or fewer');
}

it('claims each key once on SQLite', function () {
    claimsHoldOn(PdoConnection::sqlite(':memory:'));
});

it('claims each key once on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
        claimsHoldOn(new PdoConnection($pdo, 'pgsql'));
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('claims each key once on MySQL', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        claimsHoldOn(new PdoConnection($pdo, 'mysql'));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('spells a claim as each database has it', function () {
    expect(new SqliteDialect()->insertUnlessTaken('schedule_runs', ['task', 'minute']))
        ->toBe('INSERT INTO "schedule_runs" ("task", "minute") VALUES (?, ?) ON CONFLICT DO NOTHING')
        ->and(new PostgresDialect()->insertUnlessTaken('schedule_runs', ['task', 'minute']))
        ->toBe('INSERT INTO "schedule_runs" ("task", "minute") VALUES (?, ?) ON CONFLICT DO NOTHING')
        ->and(new MySqlDialect()->insertUnlessTaken('schedule_runs', ['task', 'minute']))
        ->toBe('INSERT IGNORE INTO `schedule_runs` (`task`, `minute`) VALUES (?, ?)')
        ->and(fn () => new MySqlDialect()->insertUnlessTaken('runs; DROP TABLE x', ['task']))
        ->toThrow(\InvalidArgumentException::class);
});

// On SQLite syncing NORMAL (ADR 0050) a claim an OS crash takes back is a
// nonce that can be spent again while it is valid. A durable store's claim
// is committed synced in full, as a trigger on its table sees it written; a
// webhook's, the default, is left to the next checkpoint with the handler's
// writes after it.
it('commits a durable store\'s claim synced in full, and a default store\'s at the next checkpoint', function () {
    $connection = PdoConnection::sqlite(':memory:');
    DatabaseNonceStore::define(new Schema($connection, Dialect::for($connection)));
    $connection->execute('CREATE TABLE claimed_at (synchronous INTEGER)');
    $connection->execute(
        'CREATE TRIGGER claims_seen AFTER INSERT ON webhook_nonces '
        . 'BEGIN INSERT INTO claimed_at SELECT synchronous FROM pragma_synchronous; END',
    );
    $seen = static fn (): array => array_column($connection->select('SELECT synchronous FROM claimed_at'), 'synchronous');

    // 1 is NORMAL, 2 FULL.
    expect(new DatabaseNonceStore($connection)->claim('Hook', 'a', 200, 100))->toBeTrue()
        ->and(new DatabaseNonceStore($connection, durable: true)->claim('Hook', 'b', 200, 100))->toBeTrue()
        ->and(new DatabaseNonceStore($connection, durable: true)->claim('Hook', 'b', 200, 100))->toBeFalse()
        ->and($seen())->toBe([1, 2])
        ->and($connection->select('PRAGMA synchronous')[0]['synchronous'])->toBe(1);

    // Inside a transaction of the caller's, the claim is part of it, and as
    // durable as its commit: a durable transaction cannot nest.
    $connection->durableTransaction(static fn (): bool => new DatabaseNonceStore($connection, durable: true)->claim('Hook', 'c', 200, 100));
    expect($seen())->toBe([1, 2, 2]);
});
