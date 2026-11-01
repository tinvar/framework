<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Transfers in a table of the application's database, so the request that
 * starts one, the worker that runs it and the page that shows it read one
 * record of it.
 *
 * Written on its own statements, not the Orm's: a record says what became
 * of a job, and an import's rows are applied in transactions that a
 * failure rolls back -- the record of that failure must outlive them. The
 * worker writes it outside them (TransferRunner), but for an import's
 * progress: on the application's own connection (enlistsIn()), the count
 * of rows written goes in the transaction that writes them, so it can
 * never say more or less than committed.
 */
final readonly class DatabaseTransferStore implements TransferStore
{
    private const string COLUMNS = 'id, kind, name, format, tenant, panel, user_id, status, created_at, row_count, '
        . 'error_count, errors, storage_key, size, finished_at, expires_at, file_name, failure, checked_count, updated_at, host';

    public function __construct(private Connection $connection, private string $table = 'transfers')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'transfers', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $transfers) use ($table): void {
            $transfers->string('id', 64)->primary();
            $transfers->string('kind', 16);
            $transfers->string('name', 191);
            $transfers->string('format', 16);
            $transfers->string('tenant', 191);
            $transfers->string('panel', 64);
            $transfers->string('user_id', 191);
            $transfers->string('status', 16);
            $transfers->bigInteger('created_at');
            $transfers->bigInteger('row_count')->default(0);
            $transfers->bigInteger('error_count')->default(0);
            $transfers->text('errors');
            // Not `key`, which MySQL reserves.
            $transfers->string('storage_key', 255);
            $transfers->bigInteger('size')->default(0);
            $transfers->bigInteger('finished_at')->nullable();
            $transfers->bigInteger('expires_at')->default(0);
            $transfers->string('file_name', 255);
            $transfers->string('failure', 255);
            $transfers->bigInteger('checked_count')->default(0);
            $transfers->bigInteger('updated_at')->default(0);
            $transfers->string('host', 255)->default('');
            // What a user's list asks, and what pruning asks.
            $transfers->index(['tenant', 'panel', 'user_id', 'created_at'], "{$table}_mine");
            $transfers->index(['expires_at'], "{$table}_expiry");
            $transfers->index(['created_at'], "{$table}_age");
        }, $ifNotExists);
    }

    /**
     * The column an import's check counts its rows in (ADR 0101), for a
     * migration of a table define() made before it: added once, and
     * nothing where it is there already -- as define() now makes it.
     */
    public static function defineProgress(Schema $schema, string $table = 'transfers'): void
    {
        new self($schema->connection(), $table);
        if ($schema->hasColumn($table, 'checked_count')) {
            return;
        }

        $schema->table($table, static function (Table $transfers): void {
            $transfers->bigInteger('checked_count')->default(0);
        });
    }

    /**
     * The column a record's heartbeat is kept in, for a migration of a table
     * define() made before it: added once, and nothing where it is there
     * already -- as define() now makes it. A record written before reads as
     * never having beaten, stale an hour from its start, as before.
     */
    public static function defineHeartbeat(Schema $schema, string $table = 'transfers'): void
    {
        new self($schema->connection(), $table);
        if ($schema->hasColumn($table, 'updated_at')) {
            return;
        }

        $schema->table($table, static function (Table $transfers): void {
            $transfers->bigInteger('updated_at')->default(0);
        });
    }

    /**
     * The column the host a transfer was started on is kept in (ADR 0149),
     * for a migration of a table define() made before it: added once, and
     * nothing where it is there already -- as define() now makes it. A
     * record written before reads as started on no host, and its link opens
     * nothing where the application routes by host: it is exported again.
     */
    public static function defineHost(Schema $schema, string $table = 'transfers'): void
    {
        new self($schema->connection(), $table);
        if ($schema->hasColumn($table, 'host')) {
            return;
        }

        $schema->table($table, static function (Table $transfers): void {
            $transfers->string('host', 255)->default('');
        });
    }

    public function add(TransferRecord $record): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (" . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            self::values($record),
        );
    }

    public function find(string $id, string $tenant, string $panel, string $user): ?TransferRecord
    {
        $row = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = ? AND tenant = ? AND panel = ? AND user_id = ?",
            [$id, $tenant, $panel, $user],
        )[0] ?? null;

        return $row === null ? null : self::hydrate($row);
    }

    public function claim(string $id): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET status = ? WHERE id = ? AND status = ?",
            [TransferRecord::RUNNING, $id, TransferRecord::QUEUED],
        ) === 1;
    }

    public function update(TransferRecord $record): void
    {
        $values = self::values($record);
        $id = array_shift($values);
        $this->connection->execute(
            "UPDATE {$this->table} SET kind = ?, name = ?, format = ?, tenant = ?, panel = ?, user_id = ?, status = ?, "
            . 'created_at = ?, row_count = ?, error_count = ?, errors = ?, storage_key = ?, size = ?, finished_at = ?, '
            . 'expires_at = ?, file_name = ?, failure = ?, checked_count = ?, updated_at = ?, host = ? WHERE id = ?',
            [...$values, $id],
        );
    }

    public function recent(string $tenant, string $panel, string $user, int $limit): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE tenant = ? AND panel = ? AND user_id = ? "
            . 'ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$tenant, $panel, $user],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function expired(int $now, int $createdBefore, int $limit): array
    {
        $rows = $this->connection->select(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE (expires_at > 0 AND expires_at <= ?) OR created_at < ? "
            . 'ORDER BY created_at LIMIT ' . max(1, $limit),
            [$now, $createdBefore],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function forget(string $id): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE id = ?", [$id]);
    }

    public function enlistsIn(Connection $connection): bool
    {
        return $connection === $this->connection;
    }

    /** @return list<mixed> in COLUMNS' order */
    private static function values(TransferRecord $record): array
    {
        return [
            $record->id,
            $record->kind,
            $record->name,
            $record->format,
            $record->tenant,
            $record->panel,
            $record->user,
            $record->status,
            $record->createdAt,
            $record->rows,
            $record->errorCount,
            json_encode($record->errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $record->key,
            $record->size,
            $record->finishedAt,
            $record->expiresAt,
            $record->fileName,
            $record->failure,
            $record->checked,
            $record->updatedAt,
            $record->host,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): TransferRecord
    {
        $kind = self::string($row['kind'] ?? null);
        $status = self::string($row['status'] ?? null);

        return new TransferRecord(
            id: self::string($row['id'] ?? null),
            kind: $kind === CompiledTransfer::IMPORT ? CompiledTransfer::IMPORT : CompiledTransfer::EXPORT,
            name: self::string($row['name'] ?? null),
            format: self::string($row['format'] ?? null),
            tenant: self::string($row['tenant'] ?? null),
            panel: self::string($row['panel'] ?? null),
            user: self::string($row['user_id'] ?? null),
            status: match ($status) {
                TransferRecord::QUEUED, TransferRecord::RUNNING, TransferRecord::DONE => $status,
                default => TransferRecord::FAILED,
            },
            createdAt: self::int($row['created_at'] ?? 0),
            rows: self::int($row['row_count'] ?? 0),
            errorCount: self::int($row['error_count'] ?? 0),
            errors: self::errors($row['errors'] ?? null),
            key: self::string($row['storage_key'] ?? null),
            size: self::int($row['size'] ?? 0),
            finishedAt: ($row['finished_at'] ?? null) === null ? null : self::int($row['finished_at']),
            expiresAt: self::int($row['expires_at'] ?? 0),
            fileName: self::string($row['file_name'] ?? null),
            failure: self::string($row['failure'] ?? null),
            checked: self::int($row['checked_count'] ?? 0),
            updatedAt: self::int($row['updated_at'] ?? 0),
            host: self::string($row['host'] ?? null),
        );
    }

    /** @return list<array{row: int, field: string, message: string}> */
    private static function errors(mixed $json): array
    {
        $decoded = \is_string($json) ? json_decode($json, true) : null;
        $errors = [];
        foreach (\is_array($decoded) ? $decoded : [] as $error) {
            if (\is_array($error) && \is_int($error['row'] ?? null) && \is_string($error['field'] ?? null) && \is_string($error['message'] ?? null)) {
                $errors[] = ['row' => $error['row'], 'field' => $error['field'], 'message' => $error['message']];
            }
        }

        return $errors;
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
