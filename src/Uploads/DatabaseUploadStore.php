<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Uploads in a table of the application's database, so the worker that
 * stored a file and the one whose action claims it -- and the scheduler
 * that prunes what nobody claimed -- read one record of it.
 *
 * The claim is one conditional UPDATE, so of two requests claiming one
 * upload exactly one changes a row: an upload is attached to one record,
 * once, however the requests race. The per-user cap is counted and the
 * upload added in one transaction, holding the owner's lock across both
 * (addWithin()), so uploads sent at once cannot each find room.
 */
final readonly class DatabaseUploadStore implements UploadStore
{
    private const string COLUMNS = 'id, storage_key, action, field, target_class, target_id, prefix, tenant, panel, '
        . 'session_hash, auth_hash, name, type, size, created_at, expires_at, claimed_at, owner_hash';

    public function __construct(private Connection $connection, private string $table = 'uploads')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /**
     * The store's table, for the application's migration to make. Not
     * tenant-scoped like a model's: the tenant is a column the binding is
     * checked against, and pruning reads every tenant's at once.
     */
    public static function define(Schema $schema, string $table = 'uploads', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $uploads) use ($table): void {
            $uploads->string('id', 64)->primary();
            // Not `key`, which MySQL reserves.
            $uploads->string('storage_key', 255);
            $uploads->string('action', 191);
            $uploads->string('field', 64);
            $uploads->string('target_class', 255);
            $uploads->string('target_id', 191);
            $uploads->string('prefix', 191);
            $uploads->string('tenant', 191);
            $uploads->string('panel', 64);
            $uploads->string('session_hash', 64);
            $uploads->string('auth_hash', 64);
            $uploads->string('name', 255);
            $uploads->string('type', 64);
            $uploads->bigInteger('size');
            $uploads->bigInteger('created_at');
            $uploads->bigInteger('expires_at');
            $uploads->bigInteger('claimed_at')->nullable();
            // Who uploaded it, whatever their epoch: what the per-user cap counts.
            $uploads->string('owner_hash', 64)->default('');
            // What pruning asks, and what the per-user cap counts.
            $uploads->index(['claimed_at', 'expires_at'], "{$table}_unclaimed");
            $uploads->index(['owner_hash'], "{$table}_owner");
            // What removing a file by its key looks up (Uploads::discard());
            // a table made before gets it from indexKeys().
            $uploads->index(['storage_key'], "{$table}_key");
        }, $ifNotExists);
    }

    /**
     * The index removing a file by its key looks up, for a table define()
     * made before Uploads::discard() did: an application's migration calls
     * this once. Nothing for a table that has it, as one define() makes now.
     */
    public static function indexKeys(Schema $schema, string $table = 'uploads'): void
    {
        new self($schema->connection(), $table);
        $connection = $schema->connection();
        $name = "{$table}_key";
        $driver = $connection instanceof NamesDriver ? $connection->driver() : 'sqlite';
        $found = match ($driver) {
            'pgsql' => $connection->select('SELECT 1 AS found FROM pg_indexes WHERE indexname = ?', [$name]),
            'mysql' => $connection->select('SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = ?', [$name]),
            default => $connection->select("SELECT 1 AS found FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]),
        };
        if ($found !== []) {
            return;
        }

        $schema->table($table, static function (Table $uploads) use ($name): void {
            $uploads->index(['storage_key'], $name);
        });
    }

    /**
     * Brings a table define() made before the per-user cap counted users --
     * when it counted the user binding, epoch and all, and a sign-in started
     * it over -- up to the shape define() makes now. Nothing for a table
     * that has it already. An upload stored before has no owner, and counts
     * for no one until it is claimed or pruned, within the hour.
     */
    public static function upgrade(Schema $schema, string $table = 'uploads'): void
    {
        new self($schema->connection(), $table);
        if ($schema->hasColumn($table, 'owner_hash')) {
            return;
        }

        $schema->table($table, static function (Table $uploads) use ($table): void {
            $uploads->dropIndex("{$table}_owner");
            $uploads->string('owner_hash', 64)->default('');
            $uploads->index(['owner_hash'], "{$table}_owner");
        });
    }

    public function add(PendingUpload $upload): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (" . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $upload->id,
                $upload->key,
                $upload->action,
                $upload->field,
                $upload->targetClass,
                $upload->targetId,
                $upload->prefix,
                $upload->tenant,
                $upload->panel,
                $upload->sessionHash,
                $upload->authHash,
                $upload->name,
                $upload->type,
                $upload->size,
                $upload->createdAt,
                $upload->expiresAt,
                $upload->claimedAt,
                $upload->ownerHash,
            ],
        );
    }

    /**
     * The count and the insert in one transaction, under a lock no other
     * upload of the owner's can take between them:
     *
     *  - SQLite: the transaction begins IMMEDIATE (PdoConnection::sqlite()),
     *    holding the database's write lock from before the count; on a
     *    connection opened DEFERRED the second writer's insert fails rather
     *    than overtake the first's count, which refuses that upload.
     *  - Postgres: a transaction-scoped advisory lock on the owner, which
     *    READ COMMITTED's row locks cannot give for rows not yet written.
     *  - MySQL: a named lock on the owner, held to the commit. Under
     *    REPEATABLE READ a locking read's gap locks would let both count and
     *    then deadlock on their inserts.
     *
     * A lock not had in five seconds refuses the upload by throwing, rather
     * than count without it.
     */
    public function addWithin(PendingUpload $upload, int $max, int $now, ?int $quotaBytes = null): bool
    {
        $driver = $this->connection instanceof NamesDriver ? $this->connection->driver() : 'sqlite';
        $lock = 'tinvar-upload:' . hash('sha256', $this->table . "\0" . $upload->ownerHash);

        $work = function () use ($upload, $max, $now, $driver, $lock, $quotaBytes): bool {
            if ($driver === 'pgsql') {
                $this->connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$lock]);
            }
            if ($this->pendingCount($upload->ownerHash, $now) >= $max
                || ($quotaBytes !== null && $this->bytesHeld($upload->ownerHash, $now) + $upload->size > $quotaBytes)) {
                return false;
            }
            $this->add($upload);

            return true;
        };

        if ($driver !== 'mysql') {
            return $this->connection->transaction($work);
        }

        $held = $this->connection->select('SELECT GET_LOCK(?, 5) AS held', [substr($lock, 0, 64)])[0]['held'] ?? null;
        if ((string) $held !== '1') {
            throw new \RuntimeException("The upload owner's lock could not be taken.");
        }
        try {
            return $this->connection->transaction($work);
        } finally {
            $this->connection->select('SELECT RELEASE_LOCK(?) AS released', [substr($lock, 0, 64)]);
        }
    }

    public function find(string $id): ?PendingUpload
    {
        $row = $this->connection->select('SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = ?", [$id])[0] ?? null;

        return $row === null ? null : self::hydrate($row);
    }

    public function pendingCount(string $ownerHash, int $now): int
    {
        $row = $this->connection->select(
            // Expired or not: its file is kept until pruning removes it.
            "SELECT COUNT(*) AS n FROM {$this->table} WHERE owner_hash = ? AND claimed_at IS NULL",
            [$ownerHash],
        )[0] ?? [];

        return self::int($row['n'] ?? 0);
    }

    public function bytesHeld(string $ownerHash, int $now): int
    {
        $row = $this->connection->select(
            // Every record, until pruning or a discard forgets it.
            "SELECT COALESCE(SUM(size), 0) AS n FROM {$this->table} WHERE owner_hash = ?",
            [$ownerHash],
        )[0] ?? [];

        return self::int($row['n'] ?? 0);
    }

    public function forgetKey(string $key): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE storage_key = ?", [$key]);
    }

    public function claim(string $id, int $now): bool
    {
        return $this->connection->execute(
            // A resumable session's claim (of no type) older than the lease
            // was left by a process that died holding it, and is taken over.
            "UPDATE {$this->table} SET claimed_at = ? WHERE id = ? AND expires_at > ?"
            . " AND (claimed_at IS NULL OR (type = '' AND claimed_at <= ?))",
            [$now, $id, $now, $now - self::SESSION_CLAIM_LEASE],
        ) === 1;
    }

    public function release(string $id, ?int $claimedAt = null): void
    {
        $claimedAt === null
            ? $this->connection->execute("UPDATE {$this->table} SET claimed_at = NULL WHERE id = ?", [$id])
            : $this->connection->execute("UPDATE {$this->table} SET claimed_at = NULL WHERE id = ? AND claimed_at = ?", [$id, $claimedAt]);
    }

    public function renew(string $id, int $claimedAt, int $now): bool
    {
        return $this->connection->execute("UPDATE {$this->table} SET claimed_at = ? WHERE id = ? AND claimed_at = ?", [$now, $id, $claimedAt]) === 1;
    }

    public function expired(int $now, int $limit): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE ((claimed_at IS NULL OR type = '') AND expires_at <= ?)"
            . " OR (type = '' AND claimed_at IS NOT NULL AND claimed_at <= ?) ORDER BY expires_at LIMIT " . max(1, $limit),
            [$now, $now - self::SESSION_CLAIM_LEASE],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function forget(string $id, ?int $claimedAt = null): bool
    {
        return ($claimedAt === null
            ? $this->connection->execute("DELETE FROM {$this->table} WHERE id = ?", [$id])
            : $this->connection->execute("DELETE FROM {$this->table} WHERE id = ? AND claimed_at = ?", [$id, $claimedAt])) === 1;
    }

    public function forgetClaimedBefore(int $before): int
    {
        return $this->connection->execute("DELETE FROM {$this->table} WHERE claimed_at IS NOT NULL AND claimed_at < ?", [$before]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): PendingUpload
    {
        return new PendingUpload(
            id: self::string($row['id'] ?? null),
            key: self::string($row['storage_key'] ?? null),
            action: self::string($row['action'] ?? null),
            field: self::string($row['field'] ?? null),
            targetClass: self::string($row['target_class'] ?? null),
            targetId: self::string($row['target_id'] ?? null),
            prefix: self::string($row['prefix'] ?? null),
            tenant: self::string($row['tenant'] ?? null),
            panel: self::string($row['panel'] ?? null),
            sessionHash: self::string($row['session_hash'] ?? null),
            authHash: self::string($row['auth_hash'] ?? null),
            name: self::string($row['name'] ?? null),
            type: self::string($row['type'] ?? null),
            size: self::int($row['size'] ?? 0),
            createdAt: self::int($row['created_at'] ?? 0),
            expiresAt: self::int($row['expires_at'] ?? 0),
            claimedAt: ($row['claimed_at'] ?? null) === null ? null : self::int($row['claimed_at']),
            ownerHash: self::string($row['owner_hash'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) && is_numeric($value) ? (int) $value : 0);
    }
}
