<?php

declare(strict_types=1);

/**
 * The skeleton's own SQL -- its migrations, App\Auth\Account\Users and
 * App\Auth\Flashes -- on each database `tinvar new --database` offers,
 * through the connection its Kernel opens from the environment, as an app
 * made with that flag opens its own (ADR 0169): SQLite always, Postgres
 * and MySQL where their servers are, each in a database made for the test.
 *
 * In a process of its own, as MakeResourceTest runs the skeleton: its App\
 * namespace is loaded there and nowhere else. bin/skeleton-proof makes an
 * app of the skeleton and drives every account flow in a browser, on
 * SQLite and on MySQL; this holds the skeleton's own statements to all
 * three, in the suite every change runs.
 */

namespace Tinvar\Tests\Packages\SkeletonDatabases;

use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const ROOT = __DIR__ . '/../../../..';

/**
 * The host, port, user and password of a test server's DSN, as the
 * skeleton's APP_DATABASE_ variables hold them apart.
 *
 * @return array{string, string, ?string, ?string}
 */
function serverOf(string $dsn): array
{
    $pairs = [];
    foreach (explode(';', substr($dsn, (int) strpos($dsn, ':') + 1)) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $pairs[trim($key)] = trim($value);
    }

    return [$pairs['host'] ?? 'localhost', $pairs['port'] ?? '', $pairs['user'] ?? null, $pairs['password'] ?? null];
}

/**
 * What the fixture saw on $driver: run with nothing of this process's own
 * APP_ settings, and the database the environment names made for it and
 * dropped after.
 *
 * @return array<string, mixed>
 */
function onDatabase(string $driver): array
{
    $environment = array_filter(getenv(), static fn (string $name): bool => !str_starts_with($name, 'APP_'), ARRAY_FILTER_USE_KEY);
    if ($driver === 'sqlite') {
        $directory = sys_get_temp_dir() . '/tinvar-skeleton-sql-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o700);
        try {
            return run([...$environment, 'APP_DATABASE_DRIVER' => 'sqlite', 'APP_DATABASE' => "{$directory}/app.sqlite"]);
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    }

    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    [$host, $port, $user, $password] = serverOf(Databases::dsn($driver));
    $name = 'tinvar_skeleton_' . bin2hex(random_bytes(5));
    $server = Databases::another($driver);
    $server->exec("CREATE DATABASE {$name}");
    try {
        return run([
            ...$environment,
            'APP_DATABASE_DRIVER' => $driver === 'pgsql' ? 'postgres' : 'mysql',
            'APP_DATABASE_HOST' => $host,
            'APP_DATABASE_NAME' => $name,
            'APP_DATABASE_USER' => (string) $user,
            ...($port === '' ? [] : ['APP_DATABASE_PORT' => $port]),
            ...($password === null ? [] : ['APP_DATABASE_PASSWORD' => $password]),
        ]);
    } finally {
        $server->exec($driver === 'pgsql' ? "DROP DATABASE IF EXISTS {$name} WITH (FORCE)" : "DROP DATABASE IF EXISTS {$name}");
    }
}

/**
 * @param array<string, string> $environment
 * @return array<string, mixed>
 */
function run(array $environment): array
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/skeleton-sql.php', (string) realpath(ROOT)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    \assert(\is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    expect($status)->toBe(0, $output . $errors);

    $seen = json_decode($output, true);
    expect($seen)->toBeArray($output . $errors);

    return $seen;
}

it('runs the skeleton\'s migrations, users and flashes on each database tinvar new offers', function (string $driver) {
    if (!is_dir(ROOT . '/packages/skeleton/app') || !is_file(ROOT . '/vendor/autoload.php')) {
        $this->markTestSkipped('The skeleton is not beside this package.');
    }

    $seen = onDatabase($driver);

    expect($seen['driver'])->toBe($driver)
        ->and($seen['migrated'])->toBe(array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob(ROOT . '/packages/skeleton/database/migrations/*.php') ?: [],
        ))
        ->and($seen['users'])->toBe([
            'found' => 'Grace',
            'createTaken' => true,
            'changeTaken' => true,
            'every hash' => true,
            'two' => 2,
            // Two of the three hashed at cost 4: the decoy is hashed as most are.
            'decoy cost' => 4,
            'changed' => true,
            // Refused from a hash that is not the one stored, and stored in
            // place of the one that is.
            'compare and set' => [false, true, true, true],
        ])
        ->and($seen['flashes'])->toBe([
            'pulled' => ['errors' => ['body' => ['Too long.']], 'old' => ['body' => 'a note']],
            // Taken as it was read: a reload shows nothing.
            'pulled again' => [],
            // One per session, the last put.
            'rows for one session' => 1,
            'replaced' => ['errors' => ['body' => ['second']]],
            'without a session' => [0, []],
            // Its time up: nothing shown, and nothing left.
            'expired' => [[], 0],
            // A batch of the expired swept per flash put, never more.
            'after one sweep' => 6,
            'after two' => 2,
            'kept' => [['errors' => ['body' => ['kept']]], ['errors' => ['body' => ['kept too']]]],
            // Put in its place while it was taken: nothing shown now, and
            // the new one on the next page.
            'put while taken' => [[], ['errors' => ['body' => ['put meanwhile']]]],
            // Put again once a sweep had read it as expired: kept.
            'put while swept' => [['errors' => ['body' => ['put again']]], ['errors' => ['body' => ['sweeping']]]],
        ]);
})->with(['sqlite', 'pgsql', 'mysql']);
