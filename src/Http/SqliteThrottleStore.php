<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Throttle counters in a SQLite file, shared by every process that opens it.
 *
 * For a server without APCu. PHP's built-in server runs each request from
 * nothing, so an ArrayThrottleStore there started every request empty and
 * a limit counted nothing at all: forty wrong passwords, then the right
 * one, signed in. A file is shared by whatever opens it -- requests, worker
 * threads, worker processes -- on one machine.
 *
 * Each count is one statement, an upsert that returns the new total, and a
 * statement that writes takes SQLite's write lock before it reads: two
 * processes can never both read a count and both write count+1. A second
 * writer waits its turn under the busy timeout rather than failing.
 *
 * Fixed windows aligned to the clock, as ApcuThrottleStore keeps them: a
 * window's counter is one row named for the window, so nothing has to reset
 * a count. A window that has passed is swept by whoever opens a new one, a
 * bounded batch at a time, so rows are removed at least as fast as they are
 * made and no one hit pays for a backlog.
 *
 * Opened on first use, not when built: a request that never meets a
 * throttle never touches the file.
 */
final class SqliteThrottleStore implements ThrottleStore
{
    /** Passed windows deleted, at most, each time a new window is opened. */
    public const int SWEEP_BATCH = 10;

    private ?\Pdo\Sqlite $pdo = null;

    /**
     * @param string $path the file, created if it is not there; ':memory:' for one process alone
     * @param (\Closure(): int)|null $clock for tests; defaults to time()
     */
    public function __construct(
        private readonly string $path,
        private readonly ?\Closure $clock = null,
        private readonly int $busyTimeoutMs = 5000,
    ) {
        if ($path === '') {
            throw new \InvalidArgumentException('A SQLite throttle store needs a file to keep its counts in.');
        }
    }

    public function increment(string $key, int $window): array
    {
        $window = max(1, $window);
        [$entry, $start] = $this->entry($key, $window);
        $resetsAt = $start + $window;
        $pdo = $this->pdo();

        $statement = $pdo->prepare(
            'INSERT INTO throttle (entry, hits, expires_at) VALUES (?, 1, ?)'
            . ' ON CONFLICT (entry) DO UPDATE SET hits = hits + 1 RETURNING hits',
        );
        $statement->execute([$entry, $resetsAt]);
        // Read to the end, which is what ends the statement's implicit
        // transaction and lets the write lock go.
        $hits = $statement->fetchAll(\PDO::FETCH_COLUMN)[0] ?? null;

        // A throttle that cannot count fails closed, as the APCu store does.
        if (!\is_int($hits)) {
            throw new \RuntimeException('SQLite would not count a throttle hit.');
        }

        // The first hit of a window made a row, so it clears a few that
        // have passed.
        if ($hits === 1) {
            $pdo->prepare(ThrottleWindow::sweep('sqlite', 'throttle', self::SWEEP_BATCH))->execute([$this->now()]);
        }

        return [$hits, $resetsAt];
    }

    public function release(string $key, int $window, int $hits = 1): void
    {
        [$entry] = $this->entry($key, max(1, $window));

        // One statement, like the count: no read for another process to
        // overtake. A row that is not there is a window that was never
        // opened, or has passed, and stays that way.
        $this->pdo()->prepare('UPDATE throttle SET hits = max(0, hits - ?) WHERE entry = ? AND hits > 0')
            ->execute([max(0, $hits), $entry]);
    }

    /** How many windows the file holds. For tests. */
    public function count(): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM throttle');
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * The row counting a key's current window, and when that window started.
     *
     * @return array{string, int}
     */
    private function entry(string $key, int $window): array
    {
        return ThrottleWindow::entry($key, max(1, $window), $this->now());
    }

    private function pdo(): \Pdo\Sqlite
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->path !== ':memory:') {
            $this->makePrivate();
        }

        $pdo = new \Pdo\Sqlite("sqlite:{$this->path}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // The timeout first, so every statement after it waits for the lock
        // rather than failing. WAL so a count does not block readers; NORMAL
        // because a count lost to a power cut is a few requests' worth of
        // budget, not worth a sync on every hit.
        $pdo->exec('PRAGMA busy_timeout = ' . max(0, $this->busyTimeoutMs));
        $this->untilUnlocked(static function () use ($pdo): void {
            $statement = $pdo->prepare('PRAGMA journal_mode = WAL');
            $statement->execute();
            $statement->fetchAll();
        });
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS throttle ('
            . 'entry TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires_at INTEGER NOT NULL) WITHOUT ROWID',
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS throttle_expires_at ON throttle (expires_at)');

        return $this->pdo = $pdo;
    }

    /**
     * Holds the file to its owner alone before SQLite opens it, as the
     * metrics file and the mail log are held: the counts are what stands
     * between a password and forty guesses at it, so a file another user
     * could write would let them reset a budget, and a link at its name
     * would have the store write wherever it points.
     *
     * Refused -- failing closed, the request with it -- are a directory
     * anyone may write that is not sticky, where a file could be swapped
     * in; and at the file's name or a sidecar SQLite reads as part of it
     * (`-wal`, `-shm`, `-journal`), a link of either kind, anything but a
     * plain file, or a file another user owns. A file of this user's that
     * its group or others may read or write -- one an older version made
     * under the process's umask -- is closed up to 0600. A new file is made
     * here, exclusively and under a 0077 umask, so it is private from its
     * first byte; SQLite gives each sidecar it makes the file's own mode.
     * Checked as the store opens; one swapped in after is SQLite's.
     */
    private function makePrivate(): void
    {
        $directory = \dirname($this->path);
        clearstatcache();
        $mode = is_dir($directory) ? fileperms($directory) : false;
        if ($mode !== false && ($mode & 0o002) !== 0 && ($mode & 0o1000) === 0) {
            throw new \RuntimeException("The throttle file's directory {$directory} is writable by anyone, and not sticky; it must not be.");
        }

        if (!file_exists($this->path) && !is_link($this->path)) {
            $previous = umask(0o077);
            try {
                $handle = @fopen($this->path, 'xb');
                if ($handle !== false) {
                    fclose($handle);
                }
            } finally {
                umask($previous);
            }
        }

        $owner = \function_exists('posix_geteuid') ? posix_geteuid() : null;
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            $file = $this->path . $suffix;
            clearstatcache(true, $file);
            if (!file_exists($file) && !is_link($file)) {
                continue;
            }
            $named = @lstat($file);
            if ($named === false) {
                throw new \RuntimeException("The throttle file {$file} could not be looked at; it is not opened.");
            }
            if (($named['mode'] & 0o170000) !== 0o100000) {
                throw new \RuntimeException("The throttle file {$file} is a link or not a plain file; it must be a file of its own.");
            }
            if ($named['nlink'] !== 1) {
                throw new \RuntimeException("The throttle file {$file} has another name, a hard link; it must be a file of its own.");
            }
            if ($owner !== null && $named['uid'] !== $owner) {
                throw new \RuntimeException("The throttle file {$file} belongs to another user; it must be this one's.");
            }
            if (($named['mode'] & 0o077) !== 0 && !@chmod($file, 0o600)) {
                throw new \RuntimeException("The throttle file {$file} is open to its group or to others, and could not be made its owner's alone.");
            }
        }
    }

    /**
     * Runs $work, again for as long as the busy timeout allows while SQLite
     * answers SQLITE_BUSY without waiting -- as it does when processes that
     * found a new file at the same moment each try to switch it to WAL.
     */
    private function untilUnlocked(\Closure $work): void
    {
        $deadline = microtime(true) + max(0, $this->busyTimeoutMs) / 1000;

        while (true) {
            try {
                $work();

                return;
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) !== 5 || microtime(true) >= $deadline) {
                    throw $e;
                }
                usleep(random_int(1_000, 10_000));
            }
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
