<?php

declare(strict_types=1);

/**
 * Databases for tests: a template built once per fingerprint, under a lock,
 * and a copy of it per test that nothing another test does can reach.
 */

namespace Tinvar\Tests\Testing\TestDatabase;

use Tinvar\Orm\PdoConnection;
use Tinvar\Testing\TestDatabase;

// The templates this file builds, each under a fingerprint of its own, go
// with it rather than waiting a day to be pruned.
afterAll(function () {
    $user = \function_exists('posix_geteuid') ? posix_geteuid() : 0;
    foreach (glob(rtrim(sys_get_temp_dir(), '/') . "/tinvar-db-templates-{$user}/notes-*") ?: [] as $file) {
        @unlink($file);
    }
});

/** A fingerprint no other run has used, so the template is built here. */
function fresh(): string
{
    return 'test-database-test-' . bin2hex(random_bytes(8));
}

function build(int &$built): \Closure
{
    return static function (string $path) use (&$built): void {
        $built++;
        $connection = PdoConnection::sqlite($path, create: true);
        $connection->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $connection->execute("INSERT INTO notes (body) VALUES ('seeded')");
    };
}

it('builds a template once per fingerprint, and hands each caller a copy of its own', function () {
    $built = 0;
    $fingerprint = fresh();

    $first = TestDatabase::sqlite('notes', build($built), $fingerprint);
    $second = TestDatabase::sqlite('notes', build($built), $fingerprint);

    PdoConnection::sqlite($first)->execute("INSERT INTO notes (body) VALUES ('only in the first')");

    expect($built)->toBe(1)
        ->and($first)->not->toBe($second)
        ->and(PdoConnection::sqlite($first)->select('SELECT COUNT(*) AS n FROM notes')[0]['n'])->toBe(2)
        ->and(PdoConnection::sqlite($second)->select('SELECT COUNT(*) AS n FROM notes')[0]['n'])->toBe(1)
        // Readable by this user alone, like the directory it is in.
        ->and(fileperms($first) & 0o777)->toBe(0o600);
});

it('builds again for another fingerprint, and leaves no half-built template behind a failed build', function () {
    $built = 0;
    TestDatabase::sqlite('notes', build($built), fresh());
    TestDatabase::sqlite('notes', build($built), fresh());

    $fingerprint = fresh();
    expect(fn () => TestDatabase::template('notes', static function (string $path): void {
        PdoConnection::sqlite($path, create: true)->execute('CREATE TABLE half (id INTEGER)');

        throw new \RuntimeException('the seeder failed');
    }, $fingerprint))->toThrow(\RuntimeException::class, 'the seeder failed');

    $template = TestDatabase::template('notes', build($built), $fingerprint);

    expect($built)->toBe(3)
        ->and(PdoConnection::sqlite($template)->select("SELECT name FROM sqlite_master WHERE name = 'half'"))->toBe([])
        ->and(glob(\dirname($template) . '/*.building*'))->toBe([]);
});

it('leaves the template one file, out of WAL mode, so a copy is the whole database', function () {
    $built = 0;
    $template = TestDatabase::template('notes', build($built), fresh());

    expect(is_file("{$template}-wal"))->toBeFalse()
        ->and(new \PDO("sqlite:{$template}")->query('PRAGMA journal_mode')->fetchColumn())->toBe('delete');
});

it('fingerprints the files under a path by name and contents', function () {
    $directory = sys_get_temp_dir() . '/tinvar-fingerprint-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/a.php", 'one');
    $before = TestDatabase::fingerprint($directory);
    file_put_contents("{$directory}/a.php", 'two');
    $after = TestDatabase::fingerprint($directory);
    unlink("{$directory}/a.php");
    rmdir($directory);

    expect($before)->not->toBe($after)
        ->and(fn () => TestDatabase::fingerprint($directory))->toThrow(\InvalidArgumentException::class);
});

it('refuses a name that is not a plain word', function () {
    expect(fn () => TestDatabase::template('../escape', static function (): void {}))->toThrow(\InvalidArgumentException::class);
});

it('rolls back whatever an ORM test wrote, and hands back what it returned', function () {
    $built = 0;
    $connection = PdoConnection::sqlite(TestDatabase::sqlite('notes', build($built), fresh()));

    $returned = TestDatabase::rollback($connection, static function () use ($connection): int {
        $connection->execute("INSERT INTO notes (body) VALUES ('gone')");

        return (int) $connection->select('SELECT COUNT(*) AS n FROM notes')[0]['n'];
    });

    expect($returned)->toBe(2)
        ->and($connection->select('SELECT COUNT(*) AS n FROM notes')[0]['n'])->toBe(1)
        ->and($connection->inTransaction())->toBeFalse();
});
