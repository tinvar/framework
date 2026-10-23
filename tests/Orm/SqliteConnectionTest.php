<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\SqliteConnection;

use Tinvar\Orm\PdoConnection;

/**
 * PdoConnection::sqlite(): a database file opened the way several workers
 * sharing it need -- WAL, a busy timeout, enforced foreign keys -- and never
 * a fresh empty database made by a mistyped path.
 */
function scratchDatabase(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-sqlite-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);

    return "{$directory}/test.sqlite";
}

function removeDatabase(string $path): void
{
    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        if (is_file($path . $suffix)) {
            unlink($path . $suffix);
        }
    }
    if (is_dir(\dirname($path))) {
        rmdir(\dirname($path));
    }
}

it('opens a file in WAL mode, with a busy timeout and foreign keys on', function () {
    $path = scratchDatabase();

    try {
        $connection = PdoConnection::sqlite($path, 'shop', create: true);

        expect($connection->select('PRAGMA journal_mode')[0]['journal_mode'])->toBe('wal')
            ->and($connection->select('PRAGMA busy_timeout')[0]['timeout'])->toBe(PdoConnection::SQLITE_BUSY_TIMEOUT_MS)
            ->and($connection->select('PRAGMA foreign_keys')[0]['foreign_keys'])->toBe(1)
            ->and($connection->name())->toBe('shop');
    } finally {
        removeDatabase($path);
    }
});

// Off by default in SQLite: a REFERENCES clause was decoration, and an order
// line could name a product that never existed.
it('enforces foreign keys', function () {
    $connection = PdoConnection::sqlite(':memory:');
    $connection->execute('CREATE TABLE parents (id TEXT PRIMARY KEY)');
    $connection->execute('CREATE TABLE children (id TEXT PRIMARY KEY, parent_id TEXT NOT NULL REFERENCES parents (id))');

    expect(fn () => $connection->execute("INSERT INTO children (id, parent_id) VALUES ('c', 'missing')"))
        ->toThrow(\PDOException::class, 'FOREIGN KEY');
});

// A mistyped path used to create an empty database there, and the failure
// surfaced as "no such table" somewhere else entirely.
it('refuses a file that does not exist unless asked to create it', function () {
    $path = scratchDatabase();

    try {
        expect(fn () => PdoConnection::sqlite($path))->toThrow(\PDOException::class)
            ->and(is_file($path))->toBeFalse();

        PdoConnection::sqlite($path, create: true);

        expect(is_file($path))->toBeTrue();
    } finally {
        removeDatabase($path);
    }
});

it('refuses an empty path', function () {
    expect(fn () => PdoConnection::sqlite(''))->toThrow(\InvalidArgumentException::class, 'needs a database path');
});

// The timeout is what lets ten workers share one file: a second writer waits
// for the first instead of failing the request on the spot.
it('waits for a held write lock rather than failing at once', function () {
    $path = scratchDatabase();

    try {
        $holder = PdoConnection::sqlite($path, create: true);
        $holder->execute('CREATE TABLE hits (n INTEGER)');
        $holder->pdo()->exec('BEGIN IMMEDIATE');

        $waiter = PdoConnection::sqlite($path, busyTimeoutMs: 300);
        $started = microtime(true);
        $failed = false;

        try {
            $waiter->execute('INSERT INTO hits (n) VALUES (1)');
        } catch (\PDOException) {
            $failed = true;
        }
        $waited = microtime(true) - $started;
        $holder->pdo()->exec('ROLLBACK');

        expect($failed)->toBeTrue('the lock was never released, so the write had to give up')
            ->and($waited)->toBeGreaterThan(0.25);
    } finally {
        removeDatabase($path);
    }
});

it('reads while another connection holds an open write', function () {
    $path = scratchDatabase();

    try {
        $writer = PdoConnection::sqlite($path, create: true);
        $writer->execute('CREATE TABLE hits (n INTEGER)');
        $writer->execute('INSERT INTO hits (n) VALUES (1)');

        $writer->pdo()->exec('BEGIN IMMEDIATE');
        $writer->execute('INSERT INTO hits (n) VALUES (2)');

        // The reader sees the last commit, not the write in progress, and
        // does not wait for it.
        $reader = PdoConnection::sqlite($path, busyTimeoutMs: 0);

        expect($reader->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(1);

        $writer->pdo()->exec('COMMIT');

        expect($reader->select('SELECT COUNT(*) AS n FROM hits')[0]['n'])->toBe(2);
    } finally {
        removeDatabase($path);
    }
});
