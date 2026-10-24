<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Orm\Connection;
use Tinvar\Support\PrivateDirectory;

/**
 * Databases for tests: a migrated, seeded template built once, and a copy
 * of it for every test that asks.
 *
 * "Each worker gets its own database, cloned from a migrated template." A
 * page test goes through the Kernel, whose reset contract rolls back any
 * transaction left open at the end of a request -- so a test cannot hold one
 * open around its requests and roll it back afterwards, the way the ORM
 * layer's tests can (rollback()). Each test gets a file of its own instead.
 *
 * The template is built under a lock, so parallel workers starting together
 * build it once, and kept between runs while its fingerprint -- a hash of the
 * migrations and seeder, say -- stays the same. It lives in a directory only
 * this user can enter, which is refused if it is anything else: a template a
 * test copies is a database the application under test trusts, and one
 * planted by another user of the machine would be theirs.
 *
 * Copies go in a private directory of this process's, removed when it ends
 * (or, if it was killed, by the next process to start).
 */
final class TestDatabase
{
    private static ?string $copies = null;

    private static int $copied = 0;

    /**
     * A fresh copy of the SQLite template named $name, built by $build when
     * there is none for this fingerprint yet.
     *
     * @param \Closure(string): void $build creates and fills a database at the path given
     */
    public static function sqlite(string $name, \Closure $build, string $fingerprint = ''): string
    {
        $template = self::template($name, $build, $fingerprint);
        $copy = self::copies() . '/' . $name . '-' . ++self::$copied . '.sqlite';

        if (!copy($template, $copy)) {
            throw new \RuntimeException("Could not copy the {$name} template to {$copy}.");
        }
        chmod($copy, 0600);
        // Marked as in use, so pruning a stale one never takes it.
        touch($template);

        return $copy;
    }

    /**
     * The template's path, built first if needed: by one process at a time,
     * into a temporary file renamed into place once it is whole, so no copy
     * is ever taken of a template half built.
     *
     * @param \Closure(string): void $build
     */
    public static function template(string $name, \Closure $build, string $fingerprint = ''): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('A test database name is 1 to 32 lowercase letters, digits and dashes.');
        }

        $directory = self::templates();
        $key = substr(hash('sha256', $fingerprint), 0, 16);
        $path = "{$directory}/{$name}-{$key}.sqlite";
        if (is_file($path) && !is_link($path)) {
            return $path;
        }

        $lock = fopen("{$directory}/{$name}-{$key}.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Could not lock the {$name} template for building.");
        }

        try {
            // Another process may have built it while this one waited.
            if (is_file($path) && !is_link($path)) {
                return $path;
            }

            $building = "{$directory}/{$name}-{$key}." . bin2hex(random_bytes(6)) . '.building';
            try {
                $build($building);
                self::settle($building);
                chmod($building, 0600);
                if (!rename($building, $path)) {
                    throw new \RuntimeException("Could not put the {$name} template in place.");
                }
                self::prune($directory, $name, $path);
            } finally {
                foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                    if (is_file($building . $suffix)) {
                        unlink($building . $suffix);
                    }
                }
            }

            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A fingerprint of every file under $paths -- their names and contents --
     * for a template that must be rebuilt when its migrations or seeder
     * change.
     */
    public static function fingerprint(string ...$paths): string
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                throw new \InvalidArgumentException("No file or directory at {$path} to fingerprint.");
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, $file . "\0");
            hash_update_file($hash, $file);
        }

        return hash_final($hash);
    }

    /**
     * Runs $work in a transaction that is always rolled back, for a test of
     * the ORM alone: one that sends no request through the Kernel, whose
     * reset would roll it back first.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function rollback(Connection $connection, \Closure $work): mixed
    {
        $result = null;
        try {
            $connection->transaction(static function () use ($work, &$result): never {
                $result = $work();

                throw new RolledBack();
            });
        } catch (RolledBack) {
        }

        /** @var T $result */
        return $result;
    }

    /** How long a template of an older fingerprint is kept unused: a day. */
    public const int KEEP_UNUSED = 86_400;

    /**
     * Removes the templates of $name an older fingerprint left -- migrations
     * since changed -- that nothing has copied for a day. Another checkout's
     * run still copying one keeps it, by marking it at every copy.
     */
    private static function prune(string $directory, string $name, string $current): void
    {
        foreach (glob("{$directory}/{$name}-*.sqlite") ?: [] as $template) {
            if ($template !== $current && !is_link($template) && (int) filemtime($template) < time() - self::KEEP_UNUSED) {
                @unlink($template);
                @unlink(substr($template, 0, -\strlen('.sqlite')) . '.lock');
            }
        }
    }

    /**
     * Folds the write-ahead log into the file and leaves it in rollback-journal
     * mode, so the template is one file a copy can be taken of; whoever opens
     * a copy puts it back in WAL mode (PdoConnection::sqlite()).
     */
    private static function settle(string $path): void
    {
        $pdo = new \PDO("sqlite:{$path}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pdo->exec('PRAGMA journal_mode = DELETE');
    }

    /** The template directory: this user's, entered by no one else, or refused. */
    private static function templates(): string
    {
        $user = \function_exists('posix_geteuid') ? posix_geteuid() : 0;
        $directory = rtrim(sys_get_temp_dir(), '/') . "/tinvar-db-templates-{$user}";

        if (!file_exists($directory) && !is_link($directory)) {
            @mkdir($directory, 0700);
        }
        clearstatcache(true, $directory);

        if (is_link($directory) || !is_dir($directory)
            || (\function_exists('posix_geteuid') && fileowner($directory) !== $user)
            || (fileperms($directory) & 0o077) !== 0) {
            throw new \RuntimeException(
                "{$directory} is not a directory only this user owns and can enter; test databases will not be built from it.",
            );
        }

        return $directory;
    }

    /** This process's directory for copies, made on first use and removed at its end. */
    private static function copies(): string
    {
        if (self::$copies !== null && is_dir(self::$copies)) {
            return self::$copies;
        }

        $owner = getmypid() ?: 0;
        PrivateDirectory::removeAbandoned('tinvar-testdb');
        $directory = PrivateDirectory::create('tinvar-testdb', owner: $owner);
        register_shutdown_function(static function () use ($directory, $owner): void {
            // Not in a forked child, which runs shutdown functions too.
            if ((getmypid() ?: 0) === $owner) {
                PrivateDirectory::remove($directory);
            }
        });

        return self::$copies = $directory;
    }
}
