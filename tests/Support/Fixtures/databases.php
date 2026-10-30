<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\Fixtures;

use Composer\InstalledVersions;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Tests\Orm\Fixtures\Databases as Servers;

// The database package's finder, through the path Composer installed
// tinvar/database at -- the workspace's, a package run alone's, a mirror's
// vendor/ -- never by a path out of this package (ADR 0115).
require_once InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';

/**
 * The servers a test can reach, beside the SQLite every test has: Postgres,
 * MySQL and Redis.
 *
 * Tests that need one run when it is there and are skipped, saying why, when
 * it is not: the suite has to pass on a machine with PHP alone, and a test
 * that quietly passed without its server would be worse than a skipped one.
 *
 * Postgres and MySQL are found by the database package's finder, which its
 * own suite uses with nothing of the framework's installed; Redis, which
 * only the framework's tests use, is found here.
 */
final class Databases
{
    /** @var array{0: string, 1: int}|string|null */
    private static array|string|null $redis = null;

    /** A Postgres connection, or why there is none (the database package's finder). */
    public static function postgres(): \PDO|string
    {
        return Servers::postgres();
    }

    /** A MySQL connection, or why there is none (the database package's finder). */
    public static function mysql(): \PDO|string
    {
        return Servers::mysql();
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
        return Servers::postgresMissing();
    }

    /** Why the MySQL tests are skipped, or null when they run. */
    public static function mysqlMissing(): ?string
    {
        return Servers::mysqlMissing();
    }

    /**
     * A fresh connection to the server found, for a test that needs a
     * second one -- another process's view, a second worker.
     */
    public static function another(string $driver): \PDO
    {
        return Servers::another($driver);
    }

    /** The DSN the server for $driver was found at. */
    public static function dsn(string $driver): string
    {
        return Servers::dsn($driver);
    }

    /**
     * Runs $work in a schema or database of its own on $driver's server,
     * dropped after (the database package's finder).
     *
     * @template T
     * @param \Closure(\PDO, string): T $work
     * @return T
     */
    public static function inScratchSchema(string $driver, \Closure $work): mixed
    {
        return Servers::inScratchSchema($driver, $work);
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
}
