<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Throttle counters in a database every server reaches: Postgres or MySQL,
 * and SQLite for tests.
 *
 * ApcuThrottleStore shares counts between the worker threads of one
 * process and SqliteThrottleStore between the processes of one host.
 * Several servers behind a load balancer each counting alone multiply every
 * limit by the server count -- the limit that looks enforced and is not --
 * so they count here, in one table.
 *
 * Each count is one statement: an upsert that returns the new total, which
 * takes the row's lock before it reads, so two servers can never both read
 * a count and both write count+1. Postgres and SQLite say it with ON
 * CONFLICT ... RETURNING; MySQL with ON DUPLICATE KEY UPDATE, handing the
 * total back through LAST_INSERT_ID(expr), which the driver reads from the
 * statement's own reply.
 *
 * Fixed windows aligned to the clock, as the other stores keep them: a
 * window's counter is one row named for the window, so nothing has to reset
 * a count. On Postgres and MySQL the clock is the database's (ADR 0125):
 * each store asks the server for its time when it first counts and every
 * CLOCK_EVERY seconds after, and counts by its own clock moved by the
 * difference. Every application server then names the same window for the
 * same moment, however far its own clock has drifted -- one out of step by
 * a minute no longer counts a whole window apart from the rest -- to within
 * the second the answer is rounded to and the drift between two asks.
 * SQLite is on this host, and its clock is this host's.
 *
 * The connection must be the store's own. A count made inside the request's
 * transaction would roll back with it -- a failed sign-in counted, then
 * undone by the action that failed -- and inside a loader's read-only
 * transaction it would be refused; so a connection with a transaction open
 * is refused here, before anything is counted. Opened on first use when
 * given as a closure: a request that never meets a throttle never connects.
 */
final class PdoThrottleStore implements ThrottleStore
{
    /** The table the counts are kept in, unless the store is given another. */
    public const string TABLE = 'tinvar_throttle';

    /**
     * Passed windows deleted, at most, each time a new window is opened.
     *
     * Every window is a row, made by its first hit; that hit clears this many
     * that have passed. Rows go at least as fast as they come once they
     * expire, so a caller cycling source addresses grows the table by a
     * window's worth and no more, and no one hit pays for a backlog.
     */
    public const int SWEEP_BATCH = 10;

    /** Seconds between asking the database for its time. */
    public const int CLOCK_EVERY = 300;

    private ?\PDO $pdo = null;

    private string $driver = '';

    /** The database's clock less this server's, and when (by this server's) that was asked; null until it is. */
    private ?int $offset = null;

    private int $offsetAt = 0;

    /**
     * @param \PDO|(\Closure(): \PDO) $connection the store's own connection, or what opens it
     * @param string $table where the counts are kept; `schema.table` names one in another schema
     * @param (\Closure(): int)|null $clock for tests: the time windows are counted by, taken as it is;
     *        the database's clock otherwise
     * @param (\Closure(): int)|null $hostClock for tests: this server's own clock, which the
     *        database's is read against; time() unless given
     */
    public function __construct(
        private readonly \PDO|\Closure $connection,
        private readonly string $table = self::TABLE,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $hostClock = null,
    ) {
        self::assertIdentifier($table);
    }

    /**
     * The statements that make the table, for a migration or install().
     *
     * Postgres keeps it UNLOGGED: a count is written on every request, and a
     * logged table writes each one to the WAL as well. What that buys back is
     * the counts across a crash, which empties an unlogged table -- a
     * window's worth of budget handed back once -- and on replicas, which
     * never read them.
     *
     * @return list<string>
     */
    public static function schema(string $driver, string $table = self::TABLE): array
    {
        self::assertIdentifier($table);
        $index = str_replace('.', '_', $table) . '_expires_at';

        return match ($driver) {
            'pgsql' => [
                "CREATE UNLOGGED TABLE IF NOT EXISTS {$table} ("
                . 'entry VARCHAR(100) PRIMARY KEY, hits BIGINT NOT NULL, expires_at BIGINT NOT NULL)',
                "CREATE INDEX IF NOT EXISTS {$index} ON {$table} (expires_at)",
            ],
            // MySQL names an index within its table, so the column's name
            // is enough: one spelled after a schema-qualified table runs
            // past MySQL's 64 characters.
            'mysql' => [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                . 'entry VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
                . 'hits BIGINT NOT NULL, expires_at BIGINT NOT NULL, '
                . 'INDEX expires_at (expires_at)) ENGINE=InnoDB',
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                . 'entry TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires_at INTEGER NOT NULL) WITHOUT ROWID',
                "CREATE INDEX IF NOT EXISTS {$index} ON {$table} (expires_at)",
            ],
            default => throw new \InvalidArgumentException(
                "A PDO throttle store keeps its counts in Postgres, MySQL or SQLite, not '{$driver}'.",
            ),
        };
    }

    /** Makes the table on this store's connection, if it is not there. */
    public function install(): void
    {
        $pdo = $this->pdo();
        foreach (self::schema($this->driver, $this->table) as $statement) {
            $pdo->exec($statement);
        }
    }

    public function increment(string $key, int $window): array
    {
        $window = max(1, $window);
        [$entry, $start] = $this->entry($key, $window);
        $resetsAt = $start + $window;
        $pdo = $this->unshared();

        if ($this->driver === 'mysql') {
            // LAST_INSERT_ID(1) in VALUES for a row that is new, (hits + 1)
            // in the update for one that is not: either way the total is
            // what the driver reports as the insert id, with no second
            // round trip and no read another server could overtake.
            $pdo->prepare(
                "INSERT INTO {$this->table} (entry, hits, expires_at) VALUES (?, LAST_INSERT_ID(1), ?)"
                . ' ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)',
            )->execute([$entry, $resetsAt]);
            $hits = $pdo->lastInsertId();
        } else {
            $statement = $pdo->prepare(
                "INSERT INTO {$this->table} (entry, hits, expires_at) VALUES (?, 1, ?)"
                . " ON CONFLICT (entry) DO UPDATE SET hits = {$this->table}.hits + 1 RETURNING hits",
            );
            $statement->execute([$entry, $resetsAt]);
            // Read to the end, which is what ends the statement and, on
            // SQLite, lets its write lock go.
            $hits = $statement->fetchAll(\PDO::FETCH_COLUMN)[0] ?? null;
        }

        // A throttle that cannot count fails closed, as the other stores do.
        if (!\is_int($hits) && !(\is_string($hits) && ctype_digit($hits))) {
            throw new \RuntimeException('The database would not count a throttle hit.');
        }
        $hits = (int) $hits;

        if ($hits === 1) {
            $this->sweep($pdo);
        }

        return [$hits, $resetsAt];
    }

    public function release(string $key, int $window, int $hits = 1): void
    {
        [$entry] = $this->entry($key, max(1, $window));
        $hits = max(0, $hits);

        // One statement, like the count: no read for another server to
        // overtake. A row that is not there is a window never opened, or
        // passed, and stays that way. CASE rather than GREATEST or max(),
        // which the three do not share, and rather than subtracting first,
        // which overflows for PHP_INT_MAX.
        $statement = $this->unshared()->prepare(
            "UPDATE {$this->table} SET hits = CASE WHEN hits > ? THEN hits - ? ELSE 0 END WHERE entry = ? AND hits > 0",
        );
        $statement->bindValue(1, $hits, \PDO::PARAM_INT);
        $statement->bindValue(2, $hits, \PDO::PARAM_INT);
        $statement->bindValue(3, $entry);
        $statement->execute();
    }

    /** How many windows the table holds. For tests. */
    public function count(): int
    {
        $statement = $this->pdo()->query("SELECT COUNT(*) FROM {$this->table}");

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Clears a few windows that have passed.
     *
     * Postgres skips rows another server's sweep has locked rather than
     * waiting on them, so sweepers on several servers take different rows
     * and never deadlock. A sweep that fails anyway -- a lock MySQL would not
     * wait out -- is left to the next window: the count it followed is
     * already committed, and a failed sweep is no reason to fail a request.
     */
    private function sweep(\PDO $pdo): void
    {
        $sql = ThrottleWindow::sweep($this->driver, $this->table, self::SWEEP_BATCH);

        try {
            $pdo->prepare($sql)->execute([$this->now()]);
        } catch (\PDOException) {
        }
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

    /** The connection, refused while a transaction is open on it; see the class comment. */
    private function unshared(): \PDO
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException(
                'The throttle store\'s connection has a transaction open: a count made in it would roll back '
                . 'with it. Give the store a connection of its own.',
            );
        }

        return $pdo;
    }

    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $pdo = $this->connection instanceof \PDO ? $this->connection : ($this->connection)();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $driver = \is_string($driver) ? $driver : '';

        // Asked now, so a connection to anything else fails before it counts.
        self::schema($driver, $this->table);
        $this->driver = $driver;

        return $this->pdo = $pdo;
    }

    /**
     * The table name goes into the SQL as written, so it is held to a plain
     * identifier, optionally schema-qualified: nothing a caller could build
     * from input reaches a statement.
     */
    private static function assertIdentifier(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}(\.[A-Za-z_][A-Za-z0-9_]{0,62})?$/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name a throttle store will use.");
        }
    }

    /**
     * Now, by the database's clock where it has one of its own; see the
     * class comment. A clock given for tests is taken as it is.
     */
    private function now(): int
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        $pdo = $this->pdo();
        $local = $this->hostClock === null ? time() : ($this->hostClock)();
        if ($this->driver === 'sqlite') {
            return $local;
        }

        if ($this->offset === null || $local - $this->offsetAt >= self::CLOCK_EVERY || $local < $this->offsetAt) {
            $statement = $pdo->query($this->driver === 'mysql'
                ? 'SELECT UNIX_TIMESTAMP()'
                // clock_timestamp(), not now(): the moment of asking, not
                // the start of a transaction the connection may be in.
                : 'SELECT CAST(FLOOR(EXTRACT(EPOCH FROM clock_timestamp())) AS BIGINT)');
            $remote = $statement === false ? false : $statement->fetchColumn();
            // A throttle that cannot tell the time cannot count: closed.
            if (!\is_int($remote) && !(\is_string($remote) && ctype_digit($remote))) {
                throw new \RuntimeException('The database would not say what time it is.');
            }
            $this->offset = (int) $remote - $local;
            $this->offsetAt = $local;
        }

        return $local + $this->offset;
    }
}
