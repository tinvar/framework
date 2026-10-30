<?php

declare(strict_types=1);

/**
 * The shop's throttles counted in one store every server reaches, when
 * SHOP_THROTTLE_STORE names one (ADR 0023): two servers behind a load
 * balancer spend from one budget, rather than each from its own -- the
 * limit that looks enforced and, multiplied by the servers, is not.
 */

namespace Tinvar\Tests\Integration\ShopSharedThrottle;

use Shop\Auth\SignInThrottle;
use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\PdoThrottleStore;
use Tinvar\Http\RedisThrottleStore;
use Tinvar\Http\Request;
use Tinvar\Http\SqliteThrottleStore;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const KEY = 'shop-shared-throttle-test-key-32-bytes!';

/** A scratch directory, removed when the process ends. */
function directory(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-shared-throttle-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (glob("{$directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    });

    return $directory;
}

/**
 * $work with the environment set as given -- null unsets -- and put back
 * after.
 *
 * @template T
 * @param array<string, string|null> $env
 * @param \Closure(): T $work
 * @return T
 */
function withEnv(array $env, \Closure $work): mixed
{
    $previous = [];
    foreach ($env as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        return $work();
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

/** A failed or good sign-in to $kernel from a fresh session: its status. */
function signIn(Kernel $kernel, string $password): int
{
    $cookie = Kernel::newSession(KEY);
    $token = (string) new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (): string => (string) Kernel::sessionFrom($cookie, KEY))
        ->for(Request::get('/'));

    return $kernel->handle(
        new Request(
            'POST',
            '/_action/account.signIn',
            ['host' => 'shop.test', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
            [],
            [],
            [Kernel::SESSION_COOKIE => $cookie, '__Host-XSRF-TOKEN' => $token],
            null,
            '127.0.0.1',
        ),
        json_encode(['email' => DemoAccount::EMAIL, 'password' => $password, 'next' => '/'], JSON_THROW_ON_ERROR),
    )->status;
}

describe('SHOP_THROTTLE_STORE', function () {
    it('names nothing unless it is set, and the shop counts on its own server as before', function () {
        withEnv(['SHOP_THROTTLE_STORE' => null], function (): void {
            expect(Kernel::sharedThrottleStore())->toBeNull()
                ->and(Kernel::throttleStore(':memory:'))->not->toBeInstanceOf(PdoThrottleStore::class);
        });
    });

    it('opens a table in the database a PDO DSN names, and Redis a redis:// URL names', function () {
        withEnv(['SHOP_THROTTLE_STORE' => 'sqlite::memory:'], function (): void {
            expect(Kernel::sharedThrottleStore())->toBeInstanceOf(PdoThrottleStore::class)
                ->and(Kernel::throttleStore(':memory:'))->toBeInstanceOf(PdoThrottleStore::class);
        });

        if (RedisThrottleStore::available()) {
            withEnv(['SHOP_THROTTLE_STORE' => 'redis://127.0.0.1:6379'], function (): void {
                expect(Kernel::sharedThrottleStore())->toBeInstanceOf(RedisThrottleStore::class);
            });
        }
    });

    // Falling back to each server's own count is the limit that looks
    // enforced and is not; and the value may carry a password, so it is
    // never repeated in the refusal.
    it('refuses what it cannot read, rather than counting alone, and never repeats it', function () {
        foreach (['postgres://db/shop', 'pgsql:', 'redis://:secret@cache:6379', 'redis://cache:6379/2', 'redis://'] as $value) {
            withEnv(['SHOP_THROTTLE_STORE' => $value], function () use ($value): void {
                try {
                    Kernel::sharedThrottleStore();
                    $message = 'accepted';
                } catch (\RuntimeException $e) {
                    $message = $e->getMessage();
                }

                expect($message)->toStartWith('SHOP_THROTTLE_STORE')->and($message)->not->toContain('secret');
            });
        }
    });

    it('has bin/seed make the table, once, where it names a database', function () {
        $directory = directory();
        $store = new PdoThrottleStore(new \PDO("sqlite:{$directory}/throttle.sqlite"));

        withEnv(['SHOP_THROTTLE_STORE' => "sqlite:{$directory}/throttle.sqlite"], function () use ($store): void {
            $shared = Kernel::sharedThrottleStore();
            \assert($shared instanceof PdoThrottleStore);
            $shared->install();
            $shared->install();

            expect($store->count())->toBe(0);
        });
    });

    // Opened bare, a new SQLite file kept a rollback journal, where every
    // reader waits for a writer. Opened as the shop's own database is now
    // (ADR 0050), it is in WAL mode, and a count waits its turn.
    it('counts in a SQLite file in WAL mode, waiting its turn while another server writes', function () {
        $directory = directory();
        $throttles = "{$directory}/throttle.sqlite";
        new PdoThrottleStore(new \PDO("sqlite:{$throttles}"))->install();

        withEnv(['SHOP_THROTTLE_STORE' => "sqlite:{$throttles}"], function () use ($throttles): void {
            $shared = Kernel::sharedThrottleStore();
            \assert($shared instanceof PdoThrottleStore);
            $holder = proc_open([
                PHP_BINARY, '-r',
                '$pdo = new PDO("sqlite:" . $argv[1]); $pdo->exec("BEGIN IMMEDIATE"); echo "held\n"; usleep(200000); $pdo->exec("COMMIT");',
                '--', $throttles,
            ], [1 => ['pipe', 'w']], $pipes);
            \assert($holder !== false);
            expect(trim((string) fgets($pipes[1])))->toBe('held');

            [$hits] = $shared->increment('guesses', 60);
            proc_close($holder);

            expect($hits)->toBe(1)
                ->and(new \PDO("sqlite:{$throttles}")->query('PRAGMA journal_mode')?->fetchColumn())->toBe('wal');
        });
    });
});

/**
 * $work with the store SHOP_THROTTLE_STORE names on $server -- a SQLite
 * file, a MySQL database of the test's own, or Redis -- made empty, and
 * the environment that names it; the test is skipped where the server is
 * not there.
 *
 * @param \Closure(array<string, string|null>): void $work
 */
function onStore(string $server, \Closure $work): void
{
    $missing = match ($server) {
        'mysql' => Databases::mysqlMissing(),
        'redis' => Databases::redisMissing(),
        default => null,
    };
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }

    if ($server === 'sqlite') {
        $throttles = directory() . '/throttle.sqlite';
        new PdoThrottleStore(new \PDO("sqlite:{$throttles}"))->install();
        $work(['SHOP_THROTTLE_STORE' => "sqlite:{$throttles}"]);

        return;
    }

    if ($server === 'redis') {
        $found = Databases::redis();
        \assert(\is_array($found));
        if ($found[1] === 0) {
            test()->markTestSkipped('The shop names Redis by host and port, and the one found is on a socket.');
        }
        $redis = Databases::redisClient();
        $left = $redis->keys('shop:throttle:*');
        if (\is_array($left) && $left !== []) {
            $redis->del($left);
        }
        $work(['SHOP_THROTTLE_STORE' => "redis://{$found[0]}:{$found[1]}"]);

        return;
    }

    // The credentials go apart from the DSN, as the shop reads them.
    Databases::inScratchSchema('mysql', static function (\PDO $pdo, string $schema) use ($work): void {
        new PdoThrottleStore($pdo)->install();
        $user = $password = null;
        $pairs = [];
        foreach (explode(';', substr(Databases::dsn('mysql'), \strlen('mysql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            match ($key) {
                'user' => $user = $value,
                'password' => $password = $value,
                'dbname' => null,
                default => $pairs[] = $pair,
            };
        }
        $work([
            'SHOP_THROTTLE_STORE' => 'mysql:' . implode(';', [...$pairs, "dbname={$schema}"]),
            'SHOP_THROTTLE_USER' => $user,
            'SHOP_THROTTLE_PASSWORD' => $password,
        ]);
    });
}

// Two servers, each with a database of its own and APCu or a file of its
// own to count in -- and one store, named, that both reach.
it('spends one sign-in budget across two servers counting in the store it names', function (string $server) {
    onStore($server, static function (array $env): void {
        $directory = directory();
        [$first, $second] = withEnv([...$env, 'TINVAR_THROTTLE' => null], static function () use ($directory): array {
            $servers = [];
            foreach (['first', 'second'] as $name) {
                $database = "{$directory}/{$name}.sqlite";
                new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);
                $servers[] = Kernel::fromEnvironment("{$directory}/no-renderer.sock", key: KEY, database: $database);
            }

            return $servers;
        });

        $wrong = [];
        for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
            $wrong[] = signIn($i % 2 === 0 ? $first : $second, "guess-{$i}");
        }

        expect(array_unique($wrong))->toBe([422])
            ->and(signIn($first, DemoAccount::PASSWORD))->toBe(429, 'the budget was spent across both servers')
            ->and(signIn($second, DemoAccount::PASSWORD))->toBe(429);
    });
})->with(['sqlite', 'mysql', 'redis']);

// What the store is for: the same guesses against two servers counting
// each in its own file leave both with budget to spare.
it('is what stops two servers each counting half the guesses alone', function () {
    $directory = directory();

    [$first, $second] = withEnv(['SHOP_THROTTLE_STORE' => null, 'TINVAR_THROTTLE' => null], static function () use ($directory): array {
        $servers = [];
        foreach (['first', 'second'] as $name) {
            $database = "{$directory}/{$name}.sqlite";
            new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);
            $servers[] = Kernel::fromEnvironment(
                "{$directory}/no-renderer.sock",
                key: KEY,
                database: $database,
                throttleStore: new SqliteThrottleStore("{$database}-throttle"),
            );
        }

        return $servers;
    });

    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        signIn($i % 2 === 0 ? $first : $second, "guess-{$i}");
    }

    expect(signIn($first, DemoAccount::PASSWORD))->toBe(303);
});
