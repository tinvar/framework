<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Uploads in a table of the application's database, so the worker that
 * stored a file and the one whose action claims it -- and the scheduler
 * that prunes what nobody claimed -- read one record of it.
 *
 * The claim is one conditional UPDATE, so of two requests claiming one
 * upload exactly one changes a row: an upload is attached to one record,
 * once, however the requests race.
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
        }, $ifNotExists);
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

    public function find(string $id): ?PendingUpload
    {
        $row = $this->connection->select('SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = ?", [$id])[0] ?? null;

        return $row === null ? null : self::hydrate($row);
    }

    public function pendingCount(string $ownerHash, int $now): int
    {
        $row = $this->connection->select(
            "SELECT COUNT(*) AS n FROM {$this->table} WHERE owner_hash = ? AND claimed_at IS NULL AND expires_at > ?",
            [$ownerHash, $now],
        )[0] ?? [];

        return self::int($row['n'] ?? 0);
    }

    public function claim(string $id, int $now): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET claimed_at = ? WHERE id = ? AND claimed_at IS NULL AND expires_at > ?",
            [$now, $id, $now],
        ) === 1;
    }

    public function release(string $id): void
    {
        $this->connection->execute("UPDATE {$this->table} SET claimed_at = NULL WHERE id = ?", [$id]);
    }

    public function expired(int $now, int $limit): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE claimed_at IS NULL AND expires_at <= ? ORDER BY expires_at LIMIT " . max(1, $limit),
            [$now],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function forget(string $id): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE id = ?", [$id]);
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
