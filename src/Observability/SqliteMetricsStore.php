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

    private function pdo(): \Pdo\Sqlite
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        // Its series are read by the server and written out at /_metrics:
        // made by this user alone, and refused where anyone else may write
        // to it. Created under a umask that denies everyone else, as the log
        // is (StreamSink), so it is never wider for a moment.
        if ($this->path !== ':memory:') {
            $previous = umask();
            umask($previous | 0o077);
            try {
                if (!file_exists($this->path)) {
                    @touch($this->path);
                }
            } finally {
                umask($previous);
            }
            clearstatcache(true, $this->path);
            $mode = @fileperms($this->path);
            if ($mode !== false && ($mode & 0o022) !== 0) {
                throw new \RuntimeException("The metrics file {$this->path} is writable by its group or by anyone; it must be its owner's alone.");
            }
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
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS metrics (series TEXT PRIMARY KEY, value INTEGER NOT NULL) WITHOUT ROWID');

        return $this->pdo = $pdo;
    }
}
