<?php

declare(strict_types=1);

namespace Tinvar\Orm;

final class PdoConnection implements Connection
{
    /**
     * The most statements the log keeps between resets; the count goes on.
     *
     * The reset contract clears the log after every request. This bounds it
     * whatever runs without one -- a long job, a caller that never resets --
     * since every statement the ORM builds is a fresh string, and a worker
     * kept twelve thousand of them, 1.45 MB, after three thousand requests.
     */
    public const int MAX_LOGGED = 1000;

    private int $statements = 0;

    /** @var list<string> */
    private array $log = [];

    private bool $logSql = true;

    /** Savepoints open inside the outermost transaction; names the next one. */
    private int $savepoints = 0;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $name = 'default',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * The password is sensitive: a refused connection throws from inside
     * this frame, and its trace would otherwise carry the password. (PDO's
     * own constructor already marks its password; a password written into
     * the DSN is recorded there whatever this does, so pass it separately.)
     */
    public static function postgres(
        string $dsn,
        ?string $user = null,
        #[\SensitiveParameter]
        ?string $password = null,
        string $name = 'default',
    ): self {
        return new self(new \PDO($dsn, $user, $password, [\PDO::ATTR_PERSISTENT => false]), $name);
    }

    /** How long a connection waits on another's write lock before giving up. */
    public const int SQLITE_BUSY_TIMEOUT_MS = 5000;

    /**
     * A SQLite database file, opened the way a server with several workers
     * needs it.
     *
     * Each pragma is per connection, and SQLite's defaults are wrong for
     * this: a rollback journal blocks every reader while anyone writes, a
     * busy connection fails at once instead of waiting its turn, and foreign
     * keys are not enforced at all -- so a REFERENCES clause in the schema
     * was decoration. WAL lets readers run beside the one writer; the busy
     * timeout queues writers instead of failing them; foreign_keys makes the
     * schema's references real.
     *
     * The file must exist unless $create says otherwise. Opening a mistyped
     * path made a fresh, empty database there, and every query then failed
     * with "no such table" far from the cause.
     *
     * Transactions begin IMMEDIATE unless $immediateTransactions says
     * otherwise. SQLite's default, DEFERRED, takes the write lock at the
     * first write, so a transaction that reads first holds a snapshot that
     * another worker's commit can overtake -- and then its write fails with
     * SQLITE_BUSY_SNAPSHOT at once, which the busy timeout does not wait
     * out, since waiting cannot make that snapshot current again. A
     * transaction() is a write by intent, so it takes the lock up front and
     * a second writer queues behind it under the busy timeout. postgres()
     * is untouched: under its default READ COMMITTED each statement reads a
     * fresh snapshot, and a second writer waits on the row lock instead.
     */
    public static function sqlite(
        string $path,
        string $name = 'default',
        bool $create = false,
        int $busyTimeoutMs = self::SQLITE_BUSY_TIMEOUT_MS,
        bool $immediateTransactions = true,
    ): self {
        if ($path === '') {
            throw new \InvalidArgumentException('A SQLite connection needs a database path.');
        }

        $flags = \Pdo\Sqlite::OPEN_READWRITE | ($create ? \Pdo\Sqlite::OPEN_CREATE : 0);
        $pdo = new \Pdo\Sqlite("sqlite:{$path}", null, null, [
            \Pdo\Sqlite::ATTR_OPEN_FLAGS => $flags,
            \Pdo\Sqlite::ATTR_TRANSACTION_MODE => $immediateTransactions
                ? \Pdo\Sqlite::TRANSACTION_MODE_IMMEDIATE
                : \Pdo\Sqlite::TRANSACTION_MODE_DEFERRED,
        ]);
        $connection = new self($pdo, $name);

        // Before anything else: the journal mode cannot change while another
        // statement holds the database, and the timeout has to be in place
        // before the first lock is contended.
        $pdo->exec('PRAGMA busy_timeout = ' . max(0, $busyTimeoutMs));
        $mode = $connection->select('PRAGMA journal_mode = WAL')[0]['journal_mode'] ?? null;
        $pdo->exec('PRAGMA foreign_keys = ON');

        // The pragma is setup, not the application's work: query budgets
        // count from the first statement a caller issues.
        $connection->resetStatementCount();

        // An in-memory database has no journal file to put in WAL mode, and
        // answers 'memory'. A file that answers anything but 'wal' -- one on
        // a filesystem without shared memory, say -- would silently go back
        // to blocking readers on every write.
        if ($path !== ':memory:' && !str_starts_with($path, 'file::memory:') && $mode !== 'wal') {
            throw new \RuntimeException(\sprintf(
                'SQLite would not put %s in WAL mode (it answered %s).',
                $path,
                var_export($mode, true),
            ));
        }

        return $connection;
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->record($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->record($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public function transaction(\Closure $work): mixed
    {
        // A nested call runs in a savepoint. It used to join the outer
        // transaction, so an inner call that threw kept its writes, and an
        // outer caller that caught the throw and carried on committed them.
        // The throw still propagates, so the inner rollback cannot look like
        // success to the outer caller.
        if ($this->pdo->inTransaction()) {
            return $this->savepoint($work);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->pdo->commit();

        return $result;
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function savepoint(\Closure $work): mixed
    {
        $name = 'tinvar_' . ++$this->savepoints;
        $this->pdo->exec("SAVEPOINT {$name}");

        try {
            $result = $work();
        } catch (\Throwable $e) {
            // ROLLBACK TO leaves the savepoint open on SQLite and Postgres
            // alike, so it is released as well. On Postgres this is also what
            // brings the outer transaction back from a failed statement:
            // until then it refuses every statement that follows.
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
            $this->pdo->exec("RELEASE SAVEPOINT {$name}");

            throw $e;
        } finally {
            $this->savepoints--;
        }

        $this->pdo->exec("RELEASE SAVEPOINT {$name}");

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function statementCount(): int
    {
        return $this->statements;
    }

    public function resetStatementCount(): void
    {
        $this->statements = 0;
        $this->log = [];
    }

    public function statementLog(): array
    {
        return $this->log;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Turned off in benchmarks so log-keeping does not colour the numbers. */
    public function logSql(bool $on): void
    {
        $this->logSql = $on;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    private function record(string $sql): void
    {
        $this->statements++;
        if ($this->logSql && \count($this->log) < self::MAX_LOGGED) {
            $this->log[] = $sql;
        }
    }
}
