<?php

declare(strict_types=1);

namespace Tinvar\Cache;

use Tinvar\Data\SharedRegistry;
use Tinvar\Render\TokenClaims;

/**
 * Tag versions in a database every worker and server reaches: Postgres,
 * MySQL, or SQLite for the processes of one host (ADR 0074).
 *
 * One row a tag, keyed by a hash of the tag as TagNamespace files it -- a
 * fixed width, and never the tenant's id or the application's tag spelled
 * out -- holding its version and when it was last bumped. A bump is one
 * upsert to a version drawn at random, so a row that is lost and made
 * again never returns to a version an old hold was minted at.
 *
 * Rows go once their last bump is older than any hold can be (a day, and
 * the minute two clocks may disagree by): every hold minted before that
 * bump has expired by then, so the tag reading 0 again believes none of
 * them, and one minted since reads a version it no longer has -- it is
 * produced again, which is the safe way to be wrong.
 *
 * The connection must be the store's own, as PdoStore's is: a bump made
 * inside the request's transaction would roll back with it, and a hold
 * minted before the write would be believed after it. A connection with a
 * transaction open is refused.
 */
final class PdoTagVersions implements TagVersions
{
    /** The table, unless the store is given another. */
    public const string TABLE = 'tinvar_tag_versions';

    /** How long after its last bump a row may go: longer than any hold lives. */
    public const int KEEP_SECONDS = SharedRegistry::MAX_HOLD + TokenClaims::CLOCK_SKEW + 60;

    /** Bumps by one store between two sweeps of rows past KEEP_SECONDS. */
    public const int SWEEP_EVERY = 64;

    private ?\PDO $pdo = null;

    private string $driver = '';

    private int $bumps = 0;

    /**
     * @param \PDO|(\Closure(): \PDO) $connection the store's own connection, or what opens it
     * @param (\Closure(): int)|null $clock for tests; defaults to time()
     */
    public function __construct(
        private readonly \PDO|\Closure $connection,
        private readonly string $table = self::TABLE,
        private readonly ?\Closure $clock = null,
    ) {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]{0,62}\.)?[A-Za-z_][A-Za-z0-9_]{0,50}$/D', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name a tag version store will use.");
        }
    }

    /**
     * The statements that make the table, for a migration or install().
     *
     * @return list<string>
     */
    public static function schema(string $driver, string $table = self::TABLE): array
    {
        $index = str_replace('.', '_', $table) . '_bumped_at';

        return match ($driver) {
            'pgsql' => [
                "CREATE TABLE IF NOT EXISTS {$table} (tag CHAR(64) PRIMARY KEY, version BIGINT NOT NULL, bumped_at BIGINT NOT NULL)",
                "CREATE INDEX IF NOT EXISTS {$index} ON {$table} (bumped_at)",
            ],
            'mysql' => [
                "CREATE TABLE IF NOT EXISTS {$table} (tag CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, "
                . "version BIGINT NOT NULL, bumped_at BIGINT NOT NULL, INDEX {$index} (bumped_at)) ENGINE=InnoDB",
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS {$table} (tag TEXT PRIMARY KEY, version INTEGER NOT NULL, bumped_at INTEGER NOT NULL) WITHOUT ROWID",
                "CREATE INDEX IF NOT EXISTS {$index} ON {$table} (bumped_at)",
            ],
            default => throw new \InvalidArgumentException(
                "A PDO tag version store keeps its rows in Postgres, MySQL or SQLite, not '{$driver}'.",
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

    public function versions(array $tags): array
    {
        $out = [];
        $keys = [];
        foreach (array_values(array_unique($tags)) as $tag) {
            $out[$tag] = 0;
            $keys[self::key($tag)] = $tag;
        }
        if ($keys === []) {
            return [];
        }

        $statement = $this->unshared()->prepare(
            "SELECT tag, version FROM {$this->table} WHERE tag IN (" . implode(', ', array_fill(0, \count($keys), '?')) . ')',
        );
        $statement->execute(array_keys($keys));
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $tag = $keys[(string) $row[0]] ?? null;
            if ($tag !== null) {
                $out[$tag] = (int) $row[1];
            }
        }

        return $out;
    }

    public function bump(array $tags): void
    {
        $pdo = $this->unshared();
        $now = $this->now();
        $upsert = match ($this->driver) {
            'mysql' => "INSERT INTO {$this->table} (tag, version, bumped_at) VALUES (?, ?, ?) "
                . 'ON DUPLICATE KEY UPDATE version = VALUES(version), bumped_at = VALUES(bumped_at)',
            default => "INSERT INTO {$this->table} (tag, version, bumped_at) VALUES (?, ?, ?) "
                . 'ON CONFLICT (tag) DO UPDATE SET version = excluded.version, bumped_at = excluded.bumped_at',
        };
        $statement = $pdo->prepare($upsert);
        foreach (array_values(array_unique($tags)) as $tag) {
            $statement->execute([self::key($tag), random_int(1, PHP_INT_MAX), $now]);
        }

        if (++$this->bumps % self::SWEEP_EVERY === 0) {
            $pdo->prepare("DELETE FROM {$this->table} WHERE bumped_at < ?")->execute([$now - self::KEEP_SECONDS]);
        }
    }

    /** A tag's row key: a fixed width, and nothing of the tenant or the tag readable in the table. */
    private static function key(string $tag): string
    {
        return hash('sha256', $tag);
    }

    private function unshared(): \PDO
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException(
                'The tag version store\'s connection has a transaction open: a bump made in it would roll back '
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

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
