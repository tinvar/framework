<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * Cached query results in a database every server reaches: Postgres or
 * MySQL, and SQLite for the processes of one host.
 *
 * ApcuStore shares entries between the worker threads of one process.
 * Behind a load balancer each server's APCu is its own, and an action's
 * forgetTags() on one server leaves the others serving the answer from
 * before the write until its TTL runs out; here every server reads and
 * forgets in the same two tables.
 *
 * Tags are versions, as in ApcuStore and for the same reasons: a tag is a
 * row holding a counter, an entry carries the version each of its tags had
 * when it was written, and a read that finds any of them moved is a miss.
 * Forgetting a tag is one upsert, however many entries carry it, with no
 * list of keys for servers to race each other editing -- and no count of
 * what it reached: forgetTags() returns 0. Versions come from one clock, a
 * row moved once per forgetTags(), so a put can refuse an answer read
 * before a forget that landed after the read (Misses). A tag row that is
 * gone -- swept, trimmed, deleted by hand -- is made again at the clock,
 * and the entries that recorded it miss unless nothing was forgotten in
 * between.
 *
 * The table is a trust boundary, as the jobs table is (ADR 0031): whoever
 * can write it chooses what a read hands back. A value is written as the
 * framework's MessagePack and read back by its pure-PHP decoder, never
 * serialize(), and anything that does not decode to the shape a cached
 * value has is refused -- a miss, and the row deleted (Payload).
 *
 * Bounded as the throttle table is: every put deletes a few entries that
 * have expired, so they go at least as fast as they come. A TTL can be a
 * year, though, and a caller can mint distinct keys faster than that; so
 * every TRIM_EVERY puts the table is counted, and past $maxEntries the
 * entries nearest their expiry go first. The tag table is trimmed the same
 * way, at four times as many. A value larger than $maxBytes is not kept.
 *
 * Postgres keeps both tables UNLOGGED: a cache is rebuilt by missing, and a
 * logged table writes every put to the WAL as well. A crash empties both
 * together. Read them on the primary: an unlogged table is not replicated.
 *
 * The connection must be the store's own, as PdoThrottleStore's is: a
 * forget made inside the request's transaction would roll back with it,
 * leaving the entries it dropped standing. A connection with a transaction
 * open is refused. Opened on first use when given as a closure. And a
 * forget must last as long as the writes it follows: on SQLite, open the
 * connection with every commit synced in full, or a power cut can take a
 * forget back and leave its entries beside writes that were kept.
 */
final class PdoStore implements Store
{
    /** The entries' table, unless the store is given another; the tags' is named after it. */
    public const string TABLE = 'tinvar_cache';

    /** Expired entries deleted, at most, by each put. */
    public const int SWEEP_BATCH = 10;

    /** The largest value kept, in bytes as written, unless the store is given another. */
    public const int MAX_BYTES = 1_048_576;

    /** Entries kept at most, unless the store is given another. */
    public const int MAX_ENTRIES = 100_000;

    /** Puts by one store between two counts of its tables. */
    public const int TRIM_EVERY = 64;

    /** Rows one trim deletes at most, so no one put pays for a backlog. */
    public const int TRIM_BATCH = 1_000;

    /**
     * How long a tag's row lives after it was made or last forgotten. Its
     * entries are good only while it does, so one cached for longer misses
     * early once anything has been forgotten since.
     */
    public const int TAG_TTL = 604_800;

    /**
     * The clock's row in the tag table. No tag is named this: EntryName
     * never returns a name with a `!` in it.
     */
    private const string CLOCK = '!clock';

    /** An entry kept with no TTL expires at the end of time. */
    private const int NEVER = PHP_INT_MAX;

    private ?\PDO $pdo = null;

    private string $driver = '';

    private readonly string $tags;

    private readonly Misses $misses;

    private int $puts = 0;

    /**
     * @param \PDO|(\Closure(): \PDO) $connection the store's own connection, or what opens it
     * @param string $table where the entries are kept, and `{$table}_tags` the tags;
     *        `schema.table` names one in another schema
     * @param (\Closure(): int)|null $clock for tests; defaults to time()
     */
    public function __construct(
        private readonly \PDO|\Closure $connection,
        private readonly string $table = self::TABLE,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly ?\Closure $clock = null,
    ) {
        self::assertIdentifier($table);
        $this->tags = $table . '_tags';
        $this->misses = new Misses();
    }

    /**
     * The statements that make the tables, for a migration or install().
     *
     * @return list<string>
     */
    public static function schema(string $driver, string $table = self::TABLE): array
    {
        self::assertIdentifier($table);
        $tags = $table . '_tags';
        $entriesIndex = str_replace('.', '_', $table) . '_expires_at';
        $tagsIndex = str_replace('.', '_', $tags) . '_expires_at';

        return match ($driver) {
            'pgsql' => [
                "CREATE UNLOGGED TABLE IF NOT EXISTS {$table} ("
                . 'entry VARCHAR(100) PRIMARY KEY, payload BYTEA NOT NULL, expires_at BIGINT NOT NULL)',
                "CREATE INDEX IF NOT EXISTS {$entriesIndex} ON {$table} (expires_at)",
                "CREATE UNLOGGED TABLE IF NOT EXISTS {$tags} ("
                . 'tag VARCHAR(100) PRIMARY KEY, version BIGINT NOT NULL, expires_at BIGINT NOT NULL)',
                "CREATE INDEX IF NOT EXISTS {$tagsIndex} ON {$tags} (expires_at)",
            ],
            // MySQL names an index within its table, so the column's name
            // is enough: one spelled after a schema-qualified table runs
            // past MySQL's 64 characters.
            'mysql' => [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                . 'entry VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
                . 'payload LONGBLOB NOT NULL, expires_at BIGINT NOT NULL, '
                . 'INDEX expires_at (expires_at)) ENGINE=InnoDB',
                "CREATE TABLE IF NOT EXISTS {$tags} ("
                . 'tag VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
                . 'version BIGINT NOT NULL, expires_at BIGINT NOT NULL, '
                . 'INDEX expires_at (expires_at)) ENGINE=InnoDB',
            ],
            // The entries keep their rowid: a payload is often larger than
            // the share of a page a WITHOUT ROWID row is meant to fill.
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                . 'entry TEXT PRIMARY KEY, payload BLOB NOT NULL, expires_at INTEGER NOT NULL)',
                "CREATE INDEX IF NOT EXISTS {$entriesIndex} ON {$table} (expires_at)",
                "CREATE TABLE IF NOT EXISTS {$tags} ("
                . 'tag TEXT PRIMARY KEY, version INTEGER NOT NULL, expires_at INTEGER NOT NULL) WITHOUT ROWID',
                "CREATE INDEX IF NOT EXISTS {$tagsIndex} ON {$tags} (expires_at)",
            ],
            default => throw new \InvalidArgumentException(
                "A PDO cache store keeps its entries in Postgres, MySQL or SQLite, not '{$driver}'.",
            ),
        };
    }

    /** Makes the tables on this store's connection, and the clock in them, if they are not there. */
    public function install(): void
    {
        $pdo = $this->pdo();
        foreach (self::schema($this->driver, $this->table) as $statement) {
            $pdo->exec($statement);
        }
        $this->clock($this->unshared());
    }

    public function get(string $key): array
    {
        $pdo = $this->unshared();
        $name = EntryName::of($key);

        // The clock and the entry in one statement, so the clock a miss is
        // noted at is the one the entry was read beside.
        $statement = $pdo->prepare(
            "SELECT (SELECT version FROM {$this->tags} WHERE tag = ?), c.payload, c.expires_at"
            . " FROM (SELECT 1 AS one) o LEFT JOIN {$this->table} c ON c.entry = ?",
        );
        $statement->execute([self::CLOCK, $name]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        // Postgres hands a BYTEA back as a stream; read before the cursor goes.
        $payload = \is_array($row) ? self::bytes($row[1] ?? null) : null;
        $statement->closeCursor();

        $clock = \is_array($row) && $row[0] !== null ? (int) $row[0] : null;
        $expires = \is_array($row) ? (int) ($row[2] ?? 0) : 0;

        if ($payload !== null && $expires > $this->now()) {
            $decoded = Payload::decode($payload);
            if ($decoded === null) {
                // Not what this store writes. Refused, and deleted, so it is
                // not read and refused again on every request.
                $this->drop($pdo, $name);
            } elseif ($this->current($pdo, $decoded['versions'])) {
                $this->misses->take($key);

                return ['hit' => true, 'value' => $decoded['value']];
            }
        }

        // Noted for the put that follows (Misses). A store installed
        // without its clock gets one now, once.
        $this->misses->note($key, $clock ?? $this->clock($pdo));

        return ['hit' => false, 'value' => null];
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $since = $this->misses->take($key);
        $pdo = $this->unshared();
        $name = EntryName::of($key);

        // A put replaces: a value that is not kept must not leave the one
        // before it to be read in its place.
        $names = array_values(array_unique(array_map(EntryName::of(...), $tags)));
        if (($ttlSeconds !== null && $ttlSeconds <= 0) || \count($names) > Payload::MAX_TAGS) {
            $this->drop($pdo, $name);

            return;
        }

        $versions = $this->versions($pdo, $names);

        // Forgotten after the miss this answers: the body may have read the
        // rows from before the write. Whatever is under the name now is left
        // alone -- an entry from before the forget already misses, and one
        // put since is newer than this.
        if ($since !== null) {
            foreach ($versions as $version) {
                if ($version > $since) {
                    return;
                }
            }
        }

        $payload = Payload::encode($value, $versions);
        if ($payload === null || \strlen($payload) > $this->maxBytes) {
            $this->drop($pdo, $name);

            return;
        }

        $statement = $pdo->prepare($this->driver === 'mysql'
            ? "REPLACE INTO {$this->table} (entry, payload, expires_at) VALUES (?, ?, ?)"
            : "INSERT INTO {$this->table} (entry, payload, expires_at) VALUES (?, ?, ?)"
                . ' ON CONFLICT (entry) DO UPDATE SET payload = excluded.payload, expires_at = excluded.expires_at');
        $statement->bindValue(1, $name);
        $statement->bindValue(2, $payload, \PDO::PARAM_LOB);
        $statement->bindValue(3, $ttlSeconds === null ? self::NEVER : $this->now() + $ttlSeconds, \PDO::PARAM_INT);
        $statement->execute();

        $this->sweep($pdo, $this->table, 'entry', self::SWEEP_BATCH, expired: true);
        if (++$this->puts % self::TRIM_EVERY === 0) {
            $this->trim($pdo);
        }
    }

    public function forget(string $key): void
    {
        $this->misses->take($key);
        $this->drop($this->unshared(), EntryName::of($key));
    }

    public function forgetTags(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        $pdo = $this->unshared();
        $at = $this->tick($pdo);
        $expires = $this->now() + self::TAG_TTL;

        // Raised, never lowered: two forgets finishing out of order leave
        // the later version. A tag with no row yet is made at the new
        // version, so a put that read none before it cannot slip under it.
        $statement = $pdo->prepare($this->driver === 'mysql'
            ? "INSERT INTO {$this->tags} (tag, version, expires_at) VALUES (?, ?, ?)"
                . ' ON DUPLICATE KEY UPDATE version = GREATEST(version, ?), expires_at = ?'
            : "INSERT INTO {$this->tags} (tag, version, expires_at) VALUES (?, ?, ?)"
                . " ON CONFLICT (tag) DO UPDATE SET version = CASE WHEN {$this->tags}.version < excluded.version"
                . " THEN excluded.version ELSE {$this->tags}.version END, expires_at = excluded.expires_at");
        foreach (array_unique(array_map(EntryName::of(...), $tags)) as $name) {
            $statement->bindValue(1, $name);
            $statement->bindValue(2, $at, \PDO::PARAM_INT);
            $statement->bindValue(3, $expires, \PDO::PARAM_INT);
            if ($this->driver === 'mysql') {
                $statement->bindValue(4, $at, \PDO::PARAM_INT);
                $statement->bindValue(5, $expires, \PDO::PARAM_INT);
            }
            $statement->execute();
        }

        return 0;
    }

    public function flush(): void
    {
        $pdo = $this->unshared();
        $pdo->exec("DELETE FROM {$this->table}");
        // The clock stays, so versions still only rise.
        $pdo->prepare("DELETE FROM {$this->tags} WHERE tag <> ?")->execute([self::CLOCK]);
    }

    /** How many entries the table holds. For tests. */
    public function count(): int
    {
        $statement = $this->pdo()->query("SELECT COUNT(*) FROM {$this->table}");

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Whether every tag an entry recorded still has the version it recorded.
     *
     * @param array<string, int> $versions
     */
    private function current(\PDO $pdo, array $versions): bool
    {
        if ($versions === []) {
            return true;
        }

        $found = $this->fetchVersions($pdo, array_map('strval', array_keys($versions)));
        foreach ($versions as $name => $version) {
            if (($found[(string) $name] ?? null) !== $version) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each tag's version, made at the clock where it has no row.
     *
     * @param list<string> $names
     * @return array<string, int>
     */
    private function versions(\PDO $pdo, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $versions = $this->fetchVersions($pdo, $names);
        $missing = array_values(array_filter($names, static fn (string $name): bool => !isset($versions[$name])));
        if ($missing === []) {
            return $versions;
        }

        // Made, not overwritten: another server's forget may have made one a
        // moment ago, at a version this must not take back.
        $clock = $this->clock($pdo);
        $statement = $pdo->prepare($this->driver === 'mysql'
            ? "INSERT IGNORE INTO {$this->tags} (tag, version, expires_at) VALUES (?, ?, ?)"
            : "INSERT INTO {$this->tags} (tag, version, expires_at) VALUES (?, ?, ?) ON CONFLICT (tag) DO NOTHING");
        foreach ($missing as $name) {
            $statement->bindValue(1, $name);
            $statement->bindValue(2, $clock, \PDO::PARAM_INT);
            $statement->bindValue(3, $this->now() + self::TAG_TTL, \PDO::PARAM_INT);
            $statement->execute();
        }

        $versions += $this->fetchVersions($pdo, $missing);
        if (\count($versions) !== \count($names)) {
            throw new \RuntimeException('The database would not keep a cache tag.');
        }

        return $versions;
    }

    /**
     * @param list<string> $names
     * @return array<string, int>
     */
    private function fetchVersions(\PDO $pdo, array $names): array
    {
        $statement = $pdo->prepare(
            "SELECT tag, version FROM {$this->tags} WHERE tag IN (" . implode(', ', array_fill(0, \count($names), '?')) . ')',
        );
        $statement->execute($names);

        $versions = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (\is_array($row) && \is_scalar($row[0] ?? null) && is_numeric($row[1] ?? null)) {
                $versions[(string) $row[0]] = (int) $row[1];
            }
        }

        return $versions;
    }

    /** The clock, made if the table has none. */
    private function clock(\PDO $pdo): int
    {
        $read = $pdo->prepare("SELECT version FROM {$this->tags} WHERE tag = ?");
        $read->execute([self::CLOCK]);
        $at = $read->fetchColumn();
        $read->closeCursor();
        if (is_numeric($at)) {
            return (int) $at;
        }

        // From the time, in microseconds, as ApcuStore's is: a clock made
        // again after its row was lost starts above every version taken
        // from the one before.
        $pdo->prepare($this->driver === 'mysql'
            ? "INSERT IGNORE INTO {$this->tags} (tag, version, expires_at) VALUES (?, ?, ?)"
            : "INSERT INTO {$this->tags} (tag, version, expires_at) VALUES (?, ?, ?) ON CONFLICT (tag) DO NOTHING")
            ->execute([self::CLOCK, $this->now() * 1_000_000, self::NEVER]);

        $read->execute([self::CLOCK]);
        $at = $read->fetchColumn();
        $read->closeCursor();
        if (!is_numeric($at)) {
            throw new \RuntimeException('The database would not keep the cache\'s clock.');
        }

        return (int) $at;
    }

    /**
     * The clock moved on by one, in one statement that returns where it
     * landed: no read for another server's forget to overtake.
     */
    private function tick(\PDO $pdo): int
    {
        $this->clock($pdo);

        if ($this->driver === 'mysql') {
            $statement = $pdo->prepare("UPDATE {$this->tags} SET version = LAST_INSERT_ID(version + 1) WHERE tag = ?");
            $statement->execute([self::CLOCK]);
            $at = $statement->rowCount() === 1 ? $pdo->lastInsertId() : null;
        } else {
            $statement = $pdo->prepare("UPDATE {$this->tags} SET version = version + 1 WHERE tag = ? RETURNING version");
            $statement->execute([self::CLOCK]);
            // Read to the end, which is what ends the statement and, on
            // SQLite, lets its write lock go.
            $at = $statement->fetchAll(\PDO::FETCH_COLUMN)[0] ?? null;
        }

        if (!is_numeric($at)) {
            throw new \RuntimeException('The database would not move the cache\'s clock.');
        }

        return (int) $at;
    }

    /**
     * Counts both tables, and deletes past their limits -- the rows nearest
     * their expiry first -- and the tags that have expired.
     */
    private function trim(\PDO $pdo): void
    {
        foreach ([[$this->table, 'entry', $this->maxEntries], [$this->tags, 'tag', $this->maxEntries * 4]] as [$table, $column, $limit]) {
            try {
                $statement = $pdo->query("SELECT COUNT(*) FROM {$table}");
                $over = ($statement === false ? 0 : (int) $statement->fetchColumn()) - max(0, $limit);
            } catch (\PDOException) {
                continue;
            }
            if ($over > 0) {
                $this->sweep($pdo, $table, $column, min($over, self::TRIM_BATCH), expired: false);
            }
        }

        $this->sweep($pdo, $this->tags, 'tag', self::TRIM_BATCH, expired: true);
    }

    /**
     * Deletes up to $limit rows of a table, those nearest their expiry
     * first: only those that have expired, or any. Never the clock.
     *
     * Postgres skips rows another server's sweep has locked rather than
     * waiting on them, so sweepers on several servers take different rows
     * and never deadlock. A sweep that fails anyway is left to the next put:
     * the write it followed is in, and a failed sweep is no reason to fail a
     * request.
     */
    private function sweep(\PDO $pdo, string $table, string $column, int $limit, bool $expired): void
    {
        $where = "{$column} <> ?" . ($expired ? ' AND expires_at <= ?' : '');
        $sql = match ($this->driver) {
            'pgsql' => "DELETE FROM {$table} WHERE {$column} IN (SELECT {$column} FROM {$table} WHERE {$where}"
                . " ORDER BY expires_at LIMIT {$limit} FOR UPDATE SKIP LOCKED)",
            'mysql' => "DELETE FROM {$table} WHERE {$where} ORDER BY expires_at LIMIT {$limit}",
            default => "DELETE FROM {$table} WHERE {$column} IN (SELECT {$column} FROM {$table} WHERE {$where}"
                . " ORDER BY expires_at LIMIT {$limit})",
        };

        try {
            $pdo->prepare($sql)->execute($expired ? [self::CLOCK, $this->now()] : [self::CLOCK]);
        } catch (\PDOException) {
        }
    }

    private function drop(\PDO $pdo, string $name): void
    {
        $pdo->prepare("DELETE FROM {$this->table} WHERE entry = ?")->execute([$name]);
    }

    /** A BLOB or BYTEA column's bytes, which Postgres hands back as a stream. */
    private static function bytes(mixed $column): ?string
    {
        if (\is_resource($column)) {
            $bytes = stream_get_contents($column);

            return $bytes === false ? null : $bytes;
        }

        return \is_string($column) ? $column : null;
    }

    /** The connection, refused while a transaction is open on it; see the class comment. */
    private function unshared(): \PDO
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException(
                'The cache store\'s connection has a transaction open: a forget made in it would roll back '
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

        // Asked now, so a connection to anything else fails before it is used.
        self::schema($driver, $this->table);
        $this->driver = $driver;

        return $this->pdo = $pdo;
    }

    /**
     * The table name goes into the SQL as written, so it is held to a plain
     * identifier, optionally schema-qualified: nothing a caller could build
     * from input reaches a statement. Short enough that the tag table's
     * name and its index's stay within every database's limit.
     */
    private static function assertIdentifier(string $table): void
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]{0,62}\.)?[A-Za-z_][A-Za-z0-9_]{0,39}$/D', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name a cache store will use.");
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
