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
            $pdo->prepare(
                'DELETE FROM throttle WHERE entry IN (SELECT entry FROM throttle WHERE expires_at <= ? LIMIT '
                . self::SWEEP_BATCH . ')',
            )->execute([$this->now()]);
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
        $start = intdiv($this->now(), $window) * $window;

        // Hashed: a key is built from caller identity and can be long, and
        // the file is not somewhere addresses should be listed.
        return [hash('xxh128', $key) . ':' . $window . ':' . $start, $start];
    }

    private function pdo(): \Pdo\Sqlite
    {
        if ($this->pdo !== null) {
            return $this->pdo;
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
