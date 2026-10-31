<?php

declare(strict_types=1);

namespace Tinvar\Observability;

/**
 * Counts in a SQLite file, shared by every process and thread that opens
 * it: the fallback where APCu is missing, as SqliteThrottleStore is the
 * throttle's -- and the one store the server, its queue worker and its
 * scheduler can all add to.
 *
 * One transaction a flush, an upsert a series, which takes SQLite's write
 * lock before it reads, so two writers adding to one series both count. A
 * writer waits its turn under the busy timeout rather than failing. Metrics
 * flushes it at most once every `flushEvery` seconds per worker, after the
 * response has gone, so a request never waits on it.
 *
 * Opened on first use, not when built.
 */
final class SqliteMetricsStore implements MetricsStore
{
    private ?\Pdo\Sqlite $pdo = null;

    /** @param string $path the file, created if it is not there; ':memory:' for one process alone */
    public function __construct(
        private readonly string $path,
        private readonly int $busyTimeoutMs = 5000,
    ) {
        if ($path === '') {
            throw new \InvalidArgumentException('A SQLite metrics store needs a file to keep its counts in.');
        }
    }

    public function add(array $deltas): void
    {
        if ($deltas === []) {
            return;
        }

        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $statement = $pdo->prepare(
                'INSERT INTO metrics (series, value) VALUES (?, ?) ON CONFLICT (series) DO UPDATE SET value = value + excluded.value',
            );
            foreach ($deltas as $key => $delta) {
                $statement->execute([$key, $delta]);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public function all(): array
    {
        $values = [];
        $statement = $this->pdo()->query('SELECT series, value FROM metrics');
        foreach ($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_NUM) as [$series, $value]) {
            if (\is_string($series) && \is_int($value)) {
                $values[$series] = $value;
            }
        }

        return $values;
    }

    /**
     * Refuses a file someone else could write a series into, before it is
     * opened: the file or a sidecar SQLite reads as part of it -- `-wal`,
     * `-shm`, `-journal` -- that its group or anyone may write, or that
     * another user owns, as one planted ahead of it would be; and a
     * directory anyone may write without the sticky bit, where another
     * user could swap a file out from under it. A directory its group may
     * write is allowed: a group's shared data directory often is, and what
     * a member could plant there is someone else's file, refused by its
     * owner. Checked as the store opens; one swapped in after is SQLite's.
     */
    private function assertPrivate(): void
    {
        $directory = \dirname($this->path);
        clearstatcache();
        $mode = is_dir($directory) ? fileperms($directory) : false;
        if ($mode !== false && ($mode & 0o002) !== 0 && ($mode & 0o1000) === 0) {
            throw new \RuntimeException("The metrics file's directory {$directory} is writable by anyone, and not sticky; it must not be.");
        }

        $owner = \function_exists('posix_geteuid') ? posix_geteuid() : null;
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            $file = $this->path . $suffix;
            $mode = file_exists($file) ? fileperms($file) : false;
            if ($mode === false) {
                continue;
            }
            if (($mode & 0o022) !== 0) {
                throw new \RuntimeException("The metrics file {$file} is writable by its group or by anyone; it must be its owner's alone.");
            }
            if ($owner !== null && fileowner($file) !== $owner) {
                throw new \RuntimeException("The metrics file {$file} belongs to another user; it must be this one's.");
            }
        }
    }

    private function pdo(): \Pdo\Sqlite
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        // Its series are read by the server and written out at /_metrics:
        // made by this user alone, and refused where anyone else may write
        // to it -- the file, and the write-ahead log, shared memory and
        // journal SQLite reads beside it as part of it. Everything SQLite
        // creates is created under a umask that denies everyone else, as
        // the log is (StreamSink), so none is ever wider for a moment.
        $memory = $this->path === ':memory:';
        if (!$memory) {
            $this->assertPrivate();
        }

        $previous = umask();
        umask($previous | 0o077);
        try {
            if (!$memory && !file_exists($this->path)) {
                @touch($this->path);
                $this->assertPrivate();
            }

            $pdo = new \Pdo\Sqlite("sqlite:{$this->path}");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = ' . max(0, $this->busyTimeoutMs));

            // WAL so a scrape does not wait on a flush; NORMAL because counts
            // lost to a power cut are a second's worth of requests.
            $deadline = microtime(true) + max(0, $this->busyTimeoutMs) / 1000;
            while (true) {
                try {
                    $statement = $pdo->prepare('PRAGMA journal_mode = WAL');
                    $statement->execute();
                    $statement->fetchAll();
                    break;
                } catch (\PDOException $e) {
                    // SQLITE_BUSY answered without waiting, as it is when two
                    // processes find a new file at once and both switch it.
                    if (($e->errorInfo[1] ?? null) !== 5 || microtime(true) >= $deadline) {
                        throw $e;
                    }
                    usleep(random_int(1_000, 10_000));
                }
            }
        } finally {
            umask($previous);
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS metrics (series TEXT PRIMARY KEY, value INTEGER NOT NULL) WITHOUT ROWID');

        return $this->pdo = $pdo;
    }
}
