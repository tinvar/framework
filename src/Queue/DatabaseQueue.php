<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Orm\Connection;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * A queue in two tables of the application's own database, SQLite or
 * Postgres.
 *
 * On the application's connection, a push is a write like any other: inside
 * a transaction it commits or rolls back with the writes around it, so a job
 * exists exactly when the order it was dispatched for does, and no worker
 * on another connection can see it before the commit (enlistsIn()). That is
 * the outbox, for free -- the job and the data it is about cannot disagree.
 *
 * Reserving takes one row by a conditional update, which only one worker
 * can win: on Postgres the candidate is locked with SKIP LOCKED, so workers
 * do not queue behind one another; SQLite's transactions hold its one write
 * lock, so they take turns. The tables are the application's to make, in
 * a migration that calls define().
 */
final class DatabaseQueue implements Queue
{
    /** The last enqueue time this process wrote, so its own pushes keep their order within a microsecond. */
    private static int $lastQueuedAt = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'jobs',
        private readonly string $failedTable = 'failed_jobs',
    ) {
        foreach ([$table, $failedTable] as $name) {
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $name) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a table name this queue will write.");
            }
        }
    }

    /**
     * The queue's two tables, for the application's migration to make:
     *
     *     return new class extends Migration {
     *         public function up(Schema $schema): void { DatabaseQueue::define($schema); }
     *     };
     *
     * $ifNotExists leaves tables already there as they are, as a migration
     * adopting an existing database asks of every table it makes.
     */
    public static function define(Schema $schema, string $table = 'jobs', string $failedTable = 'failed_jobs', bool $ifNotExists = false): void
    {
        // Checked as the constructor checks them, before anything is made.
        new self($schema->connection(), $table, $failedTable);

        $schema->create($table, static function (Table $jobs) use ($table): void {
            $jobs->string('id')->primary();
            $jobs->string('queue', 64);
            $jobs->text('payload');
            $jobs->integer('attempts')->default(0);
            $jobs->bigInteger('available_at');
            $jobs->bigInteger('reserved_until')->nullable();
            $jobs->string('token', 64)->nullable();
            $jobs->bigInteger('queued_at');
            // What reserve() asks: the next row of one queue that is available.
            $jobs->index(['queue', 'available_at'], "{$table}_queue_available");
        }, $ifNotExists);
        $schema->create($failedTable, static function (Table $failed): void {
            $failed->string('id')->primary();
            $failed->string('queue', 64);
            $failed->text('payload');
            $failed->text('error');
            $failed->bigInteger('failed_at');
        }, $ifNotExists);
    }

    public function push(PendingJob $job): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, queue, payload, attempts, available_at, queued_at) VALUES (?, ?, ?, 0, ?, ?)",
            [$job->id, $job->queue, $job->payload, $job->availableAt, self::queuedAt()],
        );
    }

    public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob
    {
        foreach ($queues as $queue) {
            $reserved = $this->connection->transaction(fn (): ?ReservedJob => $this->reserveFrom($queue, $now, $reserveFor));
            if ($reserved !== null) {
                return $reserved;
            }
        }

        return null;
    }

    private function reserveFrom(string $queue, int $now, int $reserveFor): ?ReservedJob
    {
        $lock = $this->isPostgres() ? ' FOR UPDATE SKIP LOCKED' : '';
        $row = $this->connection->select(
            "SELECT id, payload, attempts FROM {$this->table}
              WHERE queue = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?)
              ORDER BY available_at, queued_at, id LIMIT 1{$lock}",
            [$queue, $now, $now],
        )[0] ?? null;

        if ($row === null) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        // Conditional on the row still being free, so two workers that read
        // the same candidate cannot both take it: one update matches.
        $taken = $this->connection->execute(
            "UPDATE {$this->table} SET attempts = attempts + 1, reserved_until = ?, token = ?
              WHERE id = ? AND (reserved_until IS NULL OR reserved_until <= ?)",
            [$now + $reserveFor, $token, $row['id'], $now],
        );
        if ($taken !== 1) {
            return null;
        }

        return new ReservedJob((string) $row['id'], $queue, (string) $row['payload'], (int) $row['attempts'] + 1, $token);
    }

    public function extend(ReservedJob $job, int $until): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET reserved_until = ? WHERE id = ? AND token = ?",
            [$until, $job->id, $job->token],
        ) === 1;
    }

    public function delete(ReservedJob $job): bool
    {
        return $this->connection->execute("DELETE FROM {$this->table} WHERE id = ? AND token = ?", [$job->id, $job->token]) === 1;
    }

    public function release(ReservedJob $job, int $availableAt): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET available_at = ?, reserved_until = NULL, token = NULL WHERE id = ? AND token = ?",
            [$availableAt, $job->id, $job->token],
        ) === 1;
    }

    public function requeue(ReservedJob $job, int $availableAt, ?string $payload = null): bool
    {
        // The attempt reserve() counted is taken back: what ended this run
        // was a pause or a stop, not the job failing.
        return $this->connection->execute(
            "UPDATE {$this->table} SET available_at = ?, reserved_until = NULL, token = NULL,
                    attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END, payload = COALESCE(?, payload)
              WHERE id = ? AND token = ?",
            [$availableAt, $payload, $job->id, $job->token],
        ) === 1;
    }

    public function fail(ReservedJob $job, string $error, int $failedAt): bool
    {
        return $this->connection->transaction(function () use ($job, $error, $failedAt): bool {
            // Only the holder records the failure: a worker that ran past
            // its reservation, while another took the job, has nothing to
            // fail any more.
            if ($this->connection->execute("DELETE FROM {$this->table} WHERE id = ? AND token = ?", [$job->id, $job->token]) !== 1) {
                return false;
            }
            $this->connection->execute(
                "INSERT INTO {$this->failedTable} (id, queue, payload, error, failed_at) VALUES (?, ?, ?, ?, ?)",
                [$job->id, $job->queue, $job->payload, $error, $failedAt],
            );

            return true;
        });
    }

    public function enlistsIn(Connection $connection): bool
    {
        return $connection === $this->connection;
    }

    public function size(string $queue): int
    {
        return (int) ($this->connection->select("SELECT COUNT(*) AS n FROM {$this->table} WHERE queue = ?", [$queue])[0]['n'] ?? 0);
    }

    public function failed(): array
    {
        return array_map(
            static fn (array $row): FailedJob => new FailedJob(
                (string) $row['id'],
                (string) $row['queue'],
                (string) $row['payload'],
                (string) $row['error'],
                (int) $row['failed_at'],
            ),
            $this->connection->select("SELECT id, queue, payload, error, failed_at FROM {$this->failedTable} ORDER BY failed_at DESC, id DESC"),
        );
    }

    public function retry(string $id, int $now): bool
    {
        return $this->connection->transaction(function () use ($id, $now): bool {
            $row = $this->connection->select("SELECT queue, payload FROM {$this->failedTable} WHERE id = ?", [$id])[0] ?? null;
            if ($row === null) {
                return false;
            }
            $this->connection->execute("DELETE FROM {$this->failedTable} WHERE id = ?", [$id]);
            $this->push(new PendingJob($id, (string) $row['queue'], (string) $row['payload'], $now));

            return true;
        });
    }

    /**
     * When a job was queued, in microseconds: the order jobs available in
     * the same second are taken in. Their ids are UUIDv7, ordered only to
     * the millisecond and random within it, so two jobs one request
     * dispatched would otherwise run in either order.
     */
    private static function queuedAt(): int
    {
        return self::$lastQueuedAt = max(self::$lastQueuedAt + 1, (int) (microtime(true) * 1_000_000));
    }

    private function isPostgres(): bool
    {
        return $this->connection instanceof PdoConnection
            && $this->connection->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
