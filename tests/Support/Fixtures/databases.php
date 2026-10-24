<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\Fixtures;

use Tinvar\Support\PrivateDirectory;

/**
 * The servers a test can reach, beside the SQLite every test has: Postgres,
 * MySQL and Redis.
 *
 * Tests that need one run when it is there and are skipped, saying why, when
 * it is not: the suite has to pass on a machine with PHP alone, and a test
 * that quietly passed without its server would be worse than a skipped one.
 */
final class Databases
{
    /** @var array<string, \PDO|string> by driver: a connection, or why there is none */
    private static array $found = [];

    /** @var array<string, string> by driver: where the server was found */
    private static array $dsns = [];

    /** @var array{0: string, 1: int}|string|null */
    private static array|string|null $redis = null;

    /**
     * A Postgres connection, or why there is none.
     *
     * TINVAR_TEST_PGSQL_DSN names the server, as a PDO DSN with the user and
     * password in it (`pgsql:host=…;dbname=…;user=…;password=…`). Without it,
     * a server pg_isready finds on this machine is tried as PGUSER, or the
     * postgres role, which is what a local install trusts.
     */
    public static function postgres(): \PDO|string
    {
        return self::$found['pgsql'] ??= self::connect('pgsql', static function (): ?string {
            $dsn = getenv('TINVAR_TEST_PGSQL_DSN');
            if (\is_string($dsn) && $dsn !== '') {
                return $dsn;
            }

            exec('pg_isready -q 2>/dev/null', $output, $status);
            if ($status !== 0) {
                return null;
            }

            return \sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;user=%s;connect_timeout=2',
                self::env('PGHOST', 'localhost'),
                self::env('PGPORT', '5432'),
                self::env('PGDATABASE', 'postgres'),
                self::env('PGUSER', 'postgres'),
            );
        }, 'Postgres: set TINVAR_TEST_PGSQL_DSN, or run a server pg_isready can find.');
    }

    /**
     * A MySQL connection, or why there is none. Only TINVAR_TEST_MYSQL_DSN
     * names one (`mysql:host=…;dbname=…;user=…;password=…`): nothing like
     * pg_isready says one is running, and guessing at a root password is
     * not something a test suite should do.
     */
    public static function mysql(): \PDO|string
    {
        return self::$found['mysql'] ??= self::connect('mysql', static function (): ?string {
            $dsn = getenv('TINVAR_TEST_MYSQL_DSN');

            return \is_string($dsn) && $dsn !== '' ? $dsn : null;
        }, 'MySQL: set TINVAR_TEST_MYSQL_DSN.');
    }

    /**
     * Where a Redis server is, or why there is none.
     *
     * TINVAR_TEST_REDIS names one, as host:port or a socket path. Without
     * it, a redis-server on the PATH is started for this process alone, on
     * a socket in a PrivateDirectory -- short, since macOS caps a socket path
     * at 104 bytes -- saving nothing, and stopped when the process ends.
     *
     * @return array{0: string, 1: int}|string a host and port to connect to (port 0 for a socket), or the reason
     */
    public static function redis(): array|string
    {
        return self::$redis ??= self::findRedis();
    }

    /** Why the Redis tests are skipped, or null when they run. */
    public static function redisMissing(): ?string
    {
        $found = self::redis();

        return \is_string($found) ? $found : null;
    }

    /** A new client, connected to the server found. */
    public static function redisClient(): \Redis
    {
        $found = self::redis();
        if (\is_string($found)) {
            throw new \LogicException($found);
        }

        $redis = new \Redis();
        $redis->connect($found[0], $found[1], 2.0);

        return $redis;
    }

    /** Why the Postgres tests are skipped, or null when they run. */
    public static function postgresMissing(): ?string
    {
        $found = self::postgres();

        return \is_string($found) ? $found : null;
    }

    /** Why the MySQL tests are skipped, or null when they run. */
    public static function mysqlMissing(): ?string
    {
        $found = self::mysql();

        return \is_string($found) ? $found : null;
    }

    /**
     * A fresh connection to the server found, for a test that needs a
     * second one -- another process's view, a second worker.
     */
    public static function another(string $driver): \PDO
    {
        $found = $driver === 'pgsql' ? self::postgres() : self::mysql();
        if (\is_string($found)) {
            throw new \LogicException($found);
        }

        return self::open(self::$dsns[$driver]);
    }

    /** The DSN the server was found at, to hand to a child process. */
    public static function dsn(string $driver): string
    {
        $driver === 'pgsql' ? self::postgres() : self::mysql();

        return self::$dsns[$driver] ?? throw new \LogicException("No {$driver} server was found.");
    }

    /**
     * A schema (Postgres) or database (MySQL) of the test's own, made now
     * and dropped when $work returns, with the connection pointed at it --
     * so a test never sees another's tables, nor leaves its own behind.
     *
     * @template T
     * @param \Closure(\PDO, string): T $work given the connection and the schema's name
     * @return T
     */
    public static function inScratchSchema(string $driver, \Closure $work): mixed
    {
        $pdo = $driver === 'pgsql' ? self::postgres() : self::mysql();
        if (\is_string($pdo)) {
            throw new \LogicException($pdo);
        }

        $schema = 'tinvar_test_' . bin2hex(random_bytes(6));
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE SCHEMA {$schema}");
            $pdo->exec("SET search_path TO {$schema}");
        } else {
            $pdo->exec("CREATE DATABASE {$schema}");
            $pdo->exec("USE {$schema}");
        }

        try {
            return $work($pdo, $schema);
        } finally {
            // Whatever the test left open goes first, or the drop waits on it.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec($driver === 'pgsql' ? "DROP SCHEMA {$schema} CASCADE" : "DROP DATABASE {$schema}");
            if ($driver === 'pgsql') {
                $pdo->exec('SET search_path TO DEFAULT');
            }
        }
    }

    /** @param \Closure(): ?string $dsn */
    private static function connect(string $driver, \Closure $dsn, string $missing): \PDO|string
    {
        if (!\extension_loaded("pdo_{$driver}")) {
            return "pdo_{$driver} is not loaded.";
        }

        $found = $dsn();
        if ($found === null) {
            return $missing;
        }

        try {
            $pdo = self::open($found);
        } catch (\PDOException $e) {
            return "{$missing} The server found refused a connection: {$e->getMessage()}";
        }

        self::$dsns[$driver] = $found;

        return $pdo;
    }

    /** @return array{0: string, 1: int}|string */
    private static function findRedis(): array|string
    {
        if (!\extension_loaded('redis')) {
            return 'Redis: ext-redis is not loaded.';
        }

        $named = getenv('TINVAR_TEST_REDIS');
        if (\is_string($named) && $named !== '') {
            $at = str_starts_with($named, '/') ? [$named, 0] : [explode(':', $named)[0], (int) (explode(':', $named)[1] ?? 6379)];

            return self::answers($at) ? $at : "Redis: nothing answered at TINVAR_TEST_REDIS ({$named}).";
        }

        exec('command -v redis-server 2>/dev/null', $output, $status);
        $binary = $output[0] ?? '';
        if ($status !== 0 || $binary === '') {
            return 'Redis: set TINVAR_TEST_REDIS, or put redis-server on the PATH.';
        }

        $directory = PrivateDirectory::create('tinvar-redis');
        $socket = "{$directory}/redis.sock";
        $process = proc_open(
            [$binary, '--port', '0', '--unixsocket', $socket, '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--dir', $directory],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if ($process === false) {
            PrivateDirectory::remove($directory);

            return 'Redis: redis-server would not start.';
        }

        // Stopped by the process that started it, and only that one.
        $owner = getmypid();
        register_shutdown_function(static function () use ($process, $directory, $owner): void {
            if (getmypid() !== $owner) {
                return;
            }
            proc_terminate($process);
            proc_close($process);
            PrivateDirectory::remove($directory);
        });

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (file_exists($socket) && self::answers([$socket, 0])) {
                return [$socket, 0];
            }
            usleep(20_000);
        }

        return 'Redis: redis-server started but never answered.';
    }

    /** @param array{0: string, 1: int} $at */
    private static function answers(array $at): bool
    {
        try {
            $redis = new \Redis();
            $redis->connect($at[0], $at[1], 1.0);

            return $redis->ping() !== false;
        } catch (\RedisException) {
            return false;
        }
    }

    private static function open(string $dsn): \PDO
    {
        return new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 2,
        ]);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
