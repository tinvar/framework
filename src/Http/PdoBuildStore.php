<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * A BuildHistory kept in a database every server reaches: Postgres or MySQL,
 * or SQLite for the processes of one host.
 *
 * FileBuildStore is one machine's history. Behind a load balancer, a file on
 * each server is several histories: `tinvar build:revoke` run on one machine
 * revokes the build there and nowhere else, and the clients the revocation
 * was for go on being served by the others. Here every worker of every
 * server reads one row, so a revocation written from any of them is in force
 * on all of them by their next request.
 *
 * One row holds the history, as the file holds it: the JSON
 * BuildHistory::toArray() writes, beside a revision -- random, changed with
 * every write. A check reads the revision alone and parses the history only
 * when it differs from the one last parsed. Random, never a counter: a row
 * deleted and written again would start a counter over, and a worker that
 * had cached revision 1 of the old history would take revision 1 of the new
 * one for it -- a revocation missed, the failure FileBuildStore compares
 * whole bytes to rule out. Read in one statement with the history it names,
 * so the two cannot come from different writes.
 *
 * A change is read, applied and written in one transaction holding the
 * row's lock, as FileBuildStore holds its lock file, so two operators'
 * commands on two machines cannot lose one of their changes. Anything that
 * cannot be read -- no table, a row that is not a history -- is an error,
 * which BuildRegistry fails closed on; only a table with no row is the empty
 * history of an application that has recorded nothing yet.
 *
 * The connection must be the store's own, as PdoThrottleStore's is: a change
 * made inside the request's transaction would roll back with it. Opened on
 * first use when given as a closure, so a request that checks no build never
 * connects. Whoever can write the table can lift a revocation: grant the
 * workers SELECT on it, and only the deploying user more.
 */
final class PdoBuildStore implements BuildStore
{
    /** The table the history is kept in, unless the store is given another. */
    public const string TABLE = 'tinvar_build_history';

    /** The one row's key. */
    private const int ROW = 1;

    private ?\PDO $pdo = null;

    private string $driver = '';

    /** The revision the cached history was parsed from, or null for none. */
    private ?string $revision = null;

    private ?BuildHistory $history = null;

    /** One empty history for a table with no row, so its identity is stable too. */
    private ?BuildHistory $empty = null;

    /**
     * @param \PDO|(\Closure(): \PDO) $connection the store's own connection, or what opens it
     * @param string $table where the history is kept; `schema.table` names one in another schema
     */
    public function __construct(
        private readonly \PDO|\Closure $connection,
        private readonly string $table = self::TABLE,
    ) {
        self::assertIdentifier($table);
    }

    /**
     * The statements that make the table, for a migration or install().
     *
     * @return list<string>
     */
    public static function schema(string $driver, string $table = self::TABLE): array
    {
        self::assertIdentifier($table);

        return match ($driver) {
            'pgsql' => ["CREATE TABLE IF NOT EXISTS {$table} (id SMALLINT PRIMARY KEY, revision VARCHAR(32) NOT NULL, history TEXT NOT NULL)"],
            'mysql' => [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                . 'id SMALLINT NOT NULL PRIMARY KEY, revision VARCHAR(32) CHARACTER SET ascii NOT NULL, '
                . 'history MEDIUMTEXT NOT NULL) ENGINE=InnoDB',
            ],
            'sqlite' => ["CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY, revision TEXT NOT NULL, history TEXT NOT NULL)"],
            default => throw new \InvalidArgumentException(
                "A PDO build store keeps its history in Postgres, MySQL or SQLite, not '{$driver}'.",
            ),
        };
    }

    /**
     * The table, in an application's migration -- as DatabaseQueue and
     * DatabaseScheduleStore define theirs -- in whichever database its
     * servers all reach.
     */
    public static function define(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        self::assertIdentifier($table);

        $schema->create($table, static function (Table $history): void {
            $history->integer('id');
            $history->string('revision', 32);
            $history->text('history');
            $history->primary(['id']);
        }, $ifNotExists);
    }

    /** Makes the table on this store's connection, if it is not there. */
    public function install(): void
    {
        $pdo = $this->pdo();
        foreach (self::schema($this->driver, $this->table) as $statement) {
            $pdo->exec($statement);
        }
    }

    public function read(): BuildHistory
    {
        try {
            $pdo = $this->pdo();
            $statement = $pdo->prepare("SELECT revision FROM {$this->table} WHERE id = ?");
            $statement->execute([self::ROW]);
            $revision = $statement->fetchColumn();
            $statement->closeCursor();

            if ($revision === false) {
                $this->revision = null;

                return $this->history = $this->empty ??= new BuildHistory();
            }

            if ($revision === $this->revision && $this->history !== null) {
                return $this->history;
            }

            $statement = $pdo->prepare("SELECT revision, history FROM {$this->table} WHERE id = ?");
            $statement->execute([self::ROW]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            $statement->closeCursor();
        } catch (\PDOException $e) {
            // A connection the server has dropped -- a restart, a failover --
            // fails every statement after it, and a worker keeps its store
            // for request after request: kept, it left the worker answering
            // for its own build alone until it was restarted. One this store
            // opened is opened again by the next read; one it was handed, it
            // cannot open.
            if ($this->connection instanceof \Closure) {
                $this->pdo = null;
            }

            throw new \RuntimeException("Cannot read the build history from {$this->table}: {$e->getMessage()}", previous: $e);
        }

        // Gone between the two reads: nothing recorded, as above.
        if (!\is_array($row)) {
            $this->revision = null;

            return $this->history = $this->empty ??= new BuildHistory();
        }

        // Parsed before it is remembered: a row that fails to parse is tried
        // again on the next read rather than cached as broken.
        $history = $this->parse($row['history'] ?? null);
        $this->revision = \is_string($row['revision'] ?? null) ? $row['revision'] : null;

        return $this->history = $history;
    }

    public function update(\Closure $change): BuildHistory
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException(
                'The build store\'s connection has a transaction open: a change made in it would roll back with it. '
                . 'Give the store a connection of its own.',
            );
        }

        // Synced to the disk at the commit. A connection opened as ADR 0050
        // opens the application's -- synchronous = NORMAL -- commits that an
        // OS crash can take back, and a revocation taken back is a revoked
        // build served again; so is a floor. FULL for this one commit, as
        // PdoConnection::durableTransaction() does, set before the
        // transaction begins and put back after.
        $synchronous = null;
        if ($this->driver === 'sqlite') {
            $asked = $pdo->query('PRAGMA synchronous');
            $synchronous = $asked === false ? 0 : (int) $asked->fetchColumn();
        }
        if ($synchronous !== null && $synchronous < 2) {
            $pdo->exec('PRAGMA synchronous = FULL');
        }

        try {
            return $this->change($pdo, $change);
        } finally {
            if ($synchronous !== null && $synchronous < 2) {
                $pdo->exec("PRAGMA synchronous = {$synchronous}");
            }
        }
    }

    /** @param \Closure(BuildHistory): BuildHistory $change */
    private function change(\PDO $pdo, \Closure $change): BuildHistory
    {
        try {
            // SQLite takes the database's write lock at BEGIN IMMEDIATE, so a
            // second command waits here rather than reading what the first is
            // about to replace. Postgres and MySQL lock the row; the insert
            // first makes sure there is one to lock.
            if ($this->driver === 'sqlite') {
                $pdo->exec('BEGIN IMMEDIATE');
            } else {
                $pdo->beginTransaction();
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException("Cannot lock the build history in {$this->table}: {$e->getMessage()}", previous: $e);
        }

        try {
            $empty = json_encode(new BuildHistory()->toArray(), JSON_THROW_ON_ERROR);
            $insert = $this->driver === 'mysql'
                ? "INSERT IGNORE INTO {$this->table} (id, revision, history) VALUES (?, ?, ?)"
                : "INSERT INTO {$this->table} (id, revision, history) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING";
            $pdo->prepare($insert)->execute([self::ROW, bin2hex(random_bytes(16)), $empty]);

            $select = "SELECT history FROM {$this->table} WHERE id = ?" . ($this->driver === 'sqlite' ? '' : ' FOR UPDATE');
            $statement = $pdo->prepare($select);
            $statement->execute([self::ROW]);
            $stored = $statement->fetchColumn();
            $statement->closeCursor();

            // Fresh from the row, never the cached copy: another server may
            // have written since this process last looked.
            $next = $change($this->parse($stored));

            $pdo->prepare("UPDATE {$this->table} SET revision = ?, history = ? WHERE id = ?")->execute([
                bin2hex(random_bytes(16)),
                json_encode($next->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                self::ROW,
            ]);

            $this->driver === 'sqlite' ? $pdo->exec('COMMIT') : $pdo->commit();

            return $next;
        } catch (\Throwable $e) {
            try {
                $this->driver === 'sqlite' ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
            } catch (\PDOException) {
            }

            if ($e instanceof \PDOException || $e instanceof \JsonException) {
                throw new \RuntimeException("Cannot change the build history in {$this->table}: {$e->getMessage()}", previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Takes another store's history -- the file a server kept before the
     * application gave its servers this one -- when this store has recorded
     * nothing, so moving to it drops no revocation and no floor. Never over
     * a history: once anything is recorded here, this one is the record.
     *
     * @return bool whether the history was taken
     */
    public function adopt(BuildStore $from): bool
    {
        $previous = $from->read();
        if ($previous == new BuildHistory()) {
            return false;
        }

        $adopted = false;
        $this->update(static function (BuildHistory $history) use ($previous, &$adopted): BuildHistory {
            if ($history != new BuildHistory()) {
                return $history;
            }
            $adopted = true;

            return $previous;
        });

        return $adopted;
    }

    private function parse(mixed $raw): BuildHistory
    {
        try {
            if (!\is_string($raw)) {
                throw new \UnexpectedValueException('the stored history is not text');
            }

            return BuildHistory::fromArray(json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        } catch (\JsonException|\UnexpectedValueException $e) {
            throw new \RuntimeException("The build history in {$this->table} cannot be read: {$e->getMessage()}.", previous: $e);
        }
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
     * identifier, optionally schema-qualified.
     */
    private static function assertIdentifier(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}(?:\.[A-Za-z_][A-Za-z0-9_]{0,62})?\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name a build store can use.");
        }
    }
}
