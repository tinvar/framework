<?php

declare(strict_types=1);

namespace Tinvar\Orm;

final class PdoConnection implements Connection, TenantSetting
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

    /**
     * How a read-only scope tells the database, by driver. Postgres runs it
     * in a READ ONLY transaction of its own, or -- when the caller already
     * has one open -- in a savepoint of that transaction set read-only for
     * its length. SQLite sets query_only on this same connection: a second,
     * read-only connection could not see an in-memory database at all, nor
     * this connection's uncommitted writes, and would read a different
     * snapshot from the one the rest of the request reads. Any other driver
     * is refused in PHP alone, by the ORM.
     */
    private const string READ_ONLY_TRANSACTION = 'transaction';

    private const string READ_ONLY_SAVEPOINT = 'savepoint';

    private const string READ_ONLY_QUERY_ONLY = 'query_only';

    private const string READ_ONLY_IN_PHP = 'php';

    /** Read-only scopes open now, across every fiber; see readOnly(). */
    private int $readOnlyScopes = 0;

    /**
     * Which run of read-only scopes is open. A reset ends one with scopes
     * still counted -- a fiber suspended inside one and never resumed -- and
     * that fiber, unwound later, must not close the run after it.
     */
    private int $readOnlyRun = 0;

    /** How the open run told the database (one of the READ_ONLY_ constants); null when none is open. */
    private ?string $readOnlyMode = null;

    private readonly string $driver;

    /**
     * Asks the Orm which tenant is in force for whoever runs the next
     * statement; null until an Orm follows this connection (TenantSetting).
     *
     * @var (\Closure(): array{string, bool, list<string>})|null
     */
    private ?\Closure $tenantScope = null;

    /**
     * What the session was last told, as tenant NUL across NUL subtree;
     * null when that is not known. A rollback makes it unknown: Postgres
     * undoes a set_config() made inside the transaction or savepoint
     * rolled back, so the session may be back on an earlier tenant.
     */
    private ?string $tenantSetting = null;

    /** Whether this session has ever been told a tenant, so has one to clear. */
    private bool $tenantSettingTouched = false;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $name = 'default',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = \is_string($driver) ? $driver : '';
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
        $mode = self::switchToWal($connection, $busyTimeoutMs);
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

    /**
     * Puts the database in WAL mode, waiting out another connection doing
     * the same: what the journal mode answers.
     *
     * The switch takes the database's exclusive lock, and SQLite answers a
     * switch that finds it held with SQLITE_BUSY at once, without asking the
     * busy handler -- so two processes opening one new file together, two
     * deploys migrating it, had one fail outright. Tried again here within
     * the busy timeout, as any other statement would wait: once one of them
     * has switched, the mode is in the file and the rest find it there.
     */
    private static function switchToWal(self $connection, int $busyTimeoutMs): mixed
    {
        $deadline = microtime(true) + max(0, $busyTimeoutMs) / 1000;
        for ($wait = 1_000; ; $wait = min($wait * 2, 50_000)) {
            try {
                return $connection->select('PRAGMA journal_mode = WAL')[0]['journal_mode'] ?? null;
            } catch (\PDOException $e) {
                // SQLITE_BUSY and SQLITE_LOCKED; anything else is not a turn
                // to wait for.
                if (!\in_array($e->errorInfo[1] ?? null, [5, 6], true) || microtime(true) >= $deadline) {
                    throw $e;
                }
                usleep($wait);
            }
        }
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->record($sql);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run(function () use ($sql, $bindings): array {
            $this->applyTenantSetting();
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bindings);

            return $statement->fetchAll();
        });

        return $rows;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->record($sql);

        return $this->run(function () use ($sql, $bindings): int {
            $this->applyTenantSetting();
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bindings);

            return $statement->rowCount();
        });
    }

    /**
     * Runs one statement, keeping a read-only scope usable after it fails.
     *
     * On Postgres a failed statement aborts the transaction it ran in, and
     * every statement after it is refused until that transaction ends. In a
     * read-only scope that transaction is shared by every fiber in the
     * render, so one query's failure -- a malformed uuid, a write refused --
     * would fail every query after it, where it should fail its own call
     * alone. Nothing was written to keep, so the transaction is begun again
     * and the failure goes on to its caller.
     *
     * @template T
     * @param \Closure(): T $statement
     * @return T
     */
    private function run(\Closure $statement): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $statement();
            } catch (\PDOException $e) {
                if ($this->readOnlyMode === null) {
                    throw $e;
                }

                $this->recoverReadOnly();

                // Aborted before this statement arrived -- by a raw one sent
                // through pdo(), which nothing recovered from -- so this one
                // never ran, and runs in the transaction begun again.
                if ($attempt === 1 && self::sqlState($e) === '25P02') {
                    continue;
                }

                throw $this->refusedWrite($e) ? ReadOnlyViolation::refusedByDatabase($e) : $e;
            }
        }
    }

    /**
     * Runs $work in a read-only transaction, counted across fibers: see
     * Connection::readOnly().
     *
     * The database is told when the first scope opens rather than at the
     * first statement, so a write sent around this class -- through pdo(),
     * or a PDO handle taken from it earlier -- is refused as well.
     */
    public function readOnly(\Closure $work): mixed
    {
        $run = $this->enterReadOnly();
        $returned = false;

        // A finally, not a catch: a fiber destroyed while suspended in here
        // is unwound, which runs finally blocks and no catch, and its scope
        // has to close with it.
        try {
            $result = $work();
            $returned = true;

            return $result;
        } finally {
            if ($returned) {
                $this->leaveReadOnly($run);
            } else {
                // The work's failure is the one reported. A scope that will
                // not close is the reset contract's to end.
                try {
                    $this->leaveReadOnly($run);
                } catch (\Throwable) {
                }
            }
        }
    }

    public function isReadOnly(): bool
    {
        return $this->readOnlyMode !== null;
    }

    private function enterReadOnly(): int
    {
        if ($this->readOnlyScopes === 0) {
            $this->readOnlyMode = $this->beginReadOnly();
            $this->readOnlyRun++;
        }

        $this->readOnlyScopes++;

        return $this->readOnlyRun;
    }

    private function leaveReadOnly(int $run): void
    {
        // Ended already, by a reset; the run open now, if any, is another's.
        if ($run !== $this->readOnlyRun || $this->readOnlyScopes === 0) {
            return;
        }

        if (--$this->readOnlyScopes > 0) {
            return;
        }

        $mode = $this->readOnlyMode;
        $this->readOnlyMode = null;
        if ($mode !== null) {
            $this->endReadOnly($mode);
        }
    }

    private function beginReadOnly(): string
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA query_only = ON');

            return self::READ_ONLY_QUERY_ONLY;
        }

        if ($this->driver !== 'pgsql') {
            return self::READ_ONLY_IN_PHP;
        }

        // Inside a transaction the caller opened, a BEGIN would be refused,
        // and a transaction that has run a statement cannot be made
        // read-only and then read-write again. A savepoint can be both: set
        // read-only for the scope, and rolled back to at its end, which
        // drops the setting with it.
        if ($this->pdo->inTransaction()) {
            $this->pdo->exec('SAVEPOINT tinvar_read_only; SET LOCAL transaction_read_only = on');

            return self::READ_ONLY_SAVEPOINT;
        }

        $this->pdo->exec('BEGIN READ ONLY');

        return self::READ_ONLY_TRANSACTION;
    }

    private function endReadOnly(string $mode): void
    {
        // Rolled back to, below, which takes back a tenant set inside it.
        if ($mode === self::READ_ONLY_SAVEPOINT) {
            $this->tenantSetting = null;
        }

        match ($mode) {
            // Committed rather than rolled back: there is nothing to keep
            // either way, and monitoring reads a rollback as a failure.
            self::READ_ONLY_TRANSACTION => $this->pdo->inTransaction() ? $this->pdo->exec('COMMIT') : 0,
            self::READ_ONLY_SAVEPOINT => $this->pdo->inTransaction()
                ? $this->pdo->exec('ROLLBACK TO SAVEPOINT tinvar_read_only; RELEASE SAVEPOINT tinvar_read_only')
                : 0,
            self::READ_ONLY_QUERY_ONLY => $this->pdo->exec('PRAGMA query_only = OFF'),
            default => 0,
        };
    }

    /**
     * Brings a read-only scope's transaction back from a failed statement.
     *
     * Not while a savepoint of transaction() is open inside the scope: that
     * savepoint rolls back to itself on the way out, which recovers the
     * transaction too, and beginning it again here would take the savepoint
     * away from under it. SQLite does not abort a transaction over a failed
     * statement, so there is nothing to recover.
     */
    private function recoverReadOnly(): void
    {
        if ($this->savepoints > 0) {
            return;
        }

        $this->tenantSetting = null;

        try {
            match ($this->readOnlyMode) {
                self::READ_ONLY_TRANSACTION => $this->pdo->exec('ROLLBACK; BEGIN READ ONLY'),
                self::READ_ONLY_SAVEPOINT => $this->pdo->exec(
                    'ROLLBACK TO SAVEPOINT tinvar_read_only; SET LOCAL transaction_read_only = on',
                ),
                default => 0,
            };
        } catch (\PDOException) {
            // A connection that cannot recover -- one that has gone away --
            // fails the next statement too; the failure that caused this is
            // the one worth reporting, and the reset contract ends the scope.
        }
    }

    /** Whether a failure is the database refusing a write in a read-only scope. */
    private function refusedWrite(\PDOException $e): bool
    {
        return match ($this->driver) {
            // read_only_sql_transaction
            'pgsql' => self::sqlState($e) === '25006',
            // SQLITE_READONLY: what query_only answers a write with.
            'sqlite' => ($e->errorInfo[1] ?? null) === 8,
            default => false,
        };
    }

    private static function sqlState(\PDOException $e): string
    {
        $state = $e->errorInfo[0] ?? $e->getCode();

        return \is_string($state) ? $state : (string) $state;
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
            $this->tenantSetting = null;
            $this->pdo->rollBack();

            throw $e;
        }

        try {
            $this->pdo->commit();
        } catch (\Throwable $e) {
            // SQLite leaves the transaction open when a COMMIT fails -- a
            // deferred constraint, a writer it could not wait out -- and the
            // next statement on this connection, on a worker the next
            // request's, ran inside it. Rolled back here; a rollback that
            // fails as well is left to the reset contract, and the commit's
            // failure is the one reported.
            //
            // The transaction alone: a read-only scope open around this call
            // belongs to fibers still inside it.
            try {
                $this->rollBackTransaction();
            } catch (\Throwable) {
            }

            throw $e;
        }

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
            $this->tenantSetting = null;
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

    public function rollBackOpenTransaction(): bool
    {
        // A read-only scope still open is ended first: a fiber suspended
        // inside one and never resumed left the connection refusing writes,
        // and on a worker the next request would have found it so. Its
        // Postgres transaction, or the savepoint it sat in, goes with the
        // rollback below; query_only has to be turned off.
        $mode = $this->readOnlyMode;
        $this->readOnlyMode = null;
        $this->readOnlyScopes = 0;
        $this->readOnlyRun++;
        if ($mode === self::READ_ONLY_QUERY_ONLY) {
            $this->pdo->exec('PRAGMA query_only = OFF');
        }

        return $this->rollBackTransaction();
    }

    private function rollBackTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            return false;
        }

        // The savepoints go with the transaction they were in, and so does
        // a tenant set inside it.
        $this->savepoints = 0;
        $this->tenantSetting = null;
        $this->pdo->rollBack();

        return true;
    }

    public function followTenant(\Closure $scope): void
    {
        $this->tenantScope = $scope;
        $this->tenantSetting = null;
    }

    public function clearTenantSetting(): void
    {
        if ($this->driver !== 'pgsql' || !$this->tenantSettingTouched) {
            return;
        }

        $this->tellTenant('', false, []);
    }

    /**
     * Brings the session variable row-level security reads into line with
     * the tenant in force for whoever is running the next statement.
     *
     * Session-wide (set_config's is_local false), not SET LOCAL: there is
     * no transaction around a request to scope it to, and outside one a
     * SET LOCAL lasts a single statement. Only on a change, so a request
     * pays one round trip per tenant it enters, not one per statement; and
     * not counted or logged, as the SQLite pragmas are not, since query
     * budgets count the application's statements.
     *
     * Postgres alone: the variable is what its policies read, and nothing
     * else has them.
     */
    private function applyTenantSetting(): void
    {
        if ($this->driver !== 'pgsql' || $this->tenantScope === null) {
            return;
        }

        [$tenant, $across, $subtree] = ($this->tenantScope)();
        $this->tellTenant($tenant, $across, $subtree);
    }

    /** @param list<string> $subtree */
    private function tellTenant(string $tenant, bool $across, array $subtree = []): void
    {
        $tenants = RowLevelSecurity::arrayLiteral($subtree);
        $wanted = $tenant . "\0" . ($across ? 'on' : 'off') . "\0" . $tenants;
        if ($wanted === $this->tenantSetting) {
            return;
        }

        $this->pdo->prepare('SELECT set_config(?, ?, false), set_config(?, ?, false), set_config(?, ?, false)')->execute([
            RowLevelSecurity::TENANT_SETTING,
            $tenant,
            RowLevelSecurity::ACROSS_SETTING,
            $across ? 'on' : 'off',
            RowLevelSecurity::SUBTREE_SETTING,
            $tenants,
        ]);
        $this->tenantSetting = $wanted;
        $this->tenantSettingTouched = true;
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
