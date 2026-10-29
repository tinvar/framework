<?php

declare(strict_types=1);

namespace Tinvar\Queue;

use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
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
 *
 * Unique jobs' locks, rate-limited queues' windows and batches' counts
 * (Coordination, ADR 0100) are three more tables beside them, made by
 * defineCoordination(): written on the same connection, so each moves in
 * the transaction that pushes, deletes or fails the job it is about.
 */
final class DatabaseQueue implements Queue, Coordination
{
    /** The last enqueue time this process wrote, so its own pushes keep their order within a microsecond. */
    private static int $lastQueuedAt = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'jobs',
        private readonly string $failedTable = 'failed_jobs',
        private readonly string $locksTable = 'job_locks',
        private readonly string $batchesTable = 'job_batches',
        private readonly string $ratesTable = 'job_rates',
        private readonly string $endsTable = 'job_batch_ends',
    ) {
        foreach ([$table, $failedTable, $locksTable, $batchesTable, $ratesTable, $endsTable] as $name) {
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

    /**
     * The tables of Coordination, for the application's migration to make:
     * unique jobs' locks, the windows of rate-limited queues, batches, and
     * the jobs each batch has counted.
     */
    public static function defineCoordination(
        Schema $schema,
        string $locksTable = 'job_locks',
        string $batchesTable = 'job_batches',
        string $ratesTable = 'job_rates',
        string $endsTable = 'job_batch_ends',
        bool $ifNotExists = false,
    ): void {
        new self($schema->connection(), locksTable: $locksTable, batchesTable: $batchesTable, ratesTable: $ratesTable, endsTable: $endsTable);

        $schema->create($locksTable, static function (Table $locks) use ($locksTable): void {
            // Not `key`, which MySQL reserves: a digest, never the job's own words.
            $locks->string('lock_key', 64)->primary();
            $locks->string('job_id');
            $locks->bigInteger('expires_at');
            $locks->index(['expires_at'], "{$locksTable}_expiry");
        }, $ifNotExists);
        $schema->create($batchesTable, static function (Table $batches) use ($batchesTable): void {
            $batches->string('id')->primary();
            $batches->string('tenant', 191);
            $batches->string('panel', 64);
            $batches->string('user_id', 191);
            $batches->integer('total');
            $batches->integer('pending');
            $batches->integer('failed')->default(0);
            $batches->boolean('allow_failures')->default(false);
            $batches->text('callbacks');
            $batches->bigInteger('created_at');
            $batches->bigInteger('cancelled_at')->nullable();
            $batches->bigInteger('finished_at')->nullable();
            $batches->index(['finished_at'], "{$batchesTable}_finished");
        }, $ifNotExists);
        $schema->create($ratesTable, static function (Table $rates): void {
            $rates->string('queue', 64)->primary();
            $rates->bigInteger('window_start');
            $rates->integer('used');
        }, $ifNotExists);
        $schema->create($endsTable, static function (Table $ends): void {
            $ends->string('batch_id');
            $ends->string('job_id');
            $ends->primary(['batch_id', 'job_id']);
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

    public function lock(string $key, string $job, int $until, int $now): ?string
    {
        // Free: taken by the insert. Lapsed: taken over by an update that
        // only a lapsed lock matches. Else whoever holds it, read inside the
        // transaction the caller pushes in, so the answer is the one in force.
        for ($try = 0; $try < 2; $try++) {
            if ($this->insertIgnoring($this->locksTable, ['lock_key', 'job_id', 'expires_at'], [$key, $job, $until])) {
                return null;
            }
            $taken = $this->connection->execute(
                "UPDATE {$this->locksTable} SET job_id = ?, expires_at = ? WHERE lock_key = ? AND expires_at <= ?",
                [$job, $until, $key, $now],
            );
            if ($taken === 1) {
                return null;
            }
            $holder = $this->connection->select("SELECT job_id FROM {$this->locksTable} WHERE lock_key = ?", [$key])[0]['job_id'] ?? null;
            if (\is_string($holder)) {
                return $holder === $job ? null : $holder;
            }
        }

        // Taken and given back between each look, twice: a lock this busy
        // is held, as far as this push is concerned.
        return '';
    }

    public function unlock(string $key, string $job): bool
    {
        return $this->connection->execute("DELETE FROM {$this->locksTable} WHERE lock_key = ? AND job_id = ?", [$key, $job]) === 1;
    }

    public function slot(string $queue, int $runs, int $per, int $now): bool
    {
        $window = $now - $now % $per;
        for ($try = 0; $try < 2; $try++) {
            // This window's, while it has some left.
            if ($this->connection->execute(
                "UPDATE {$this->ratesTable} SET used = used + 1 WHERE queue = ? AND window_start = ? AND used < ?",
                [$queue, $window, $runs],
            ) === 1) {
                return true;
            }
            // A window gone by: this one starts, with this start in it.
            if ($this->connection->execute(
                "UPDATE {$this->ratesTable} SET window_start = ?, used = 1 WHERE queue = ? AND window_start < ?",
                [$window, $queue, $window],
            ) === 1) {
                return true;
            }
            // The queue's first.
            if ($this->insertIgnoring($this->ratesTable, ['queue', 'window_start', 'used'], [$queue, $window, 1])) {
                return true;
            }
        }

        return false;
    }

    public function unslot(string $queue, int $per, int $now): void
    {
        $this->connection->execute(
            "UPDATE {$this->ratesTable} SET used = used - 1 WHERE queue = ? AND window_start = ? AND used > 0",
            [$queue, $now - $now % $per],
        );
    }

    public function addBatch(BatchRecord $batch): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->batchesTable} (id, tenant, panel, user_id, total, pending, failed, allow_failures, callbacks, created_at, cancelled_at, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $batch->id, $batch->tenant, $batch->panel, $batch->user, $batch->total, $batch->pending, $batch->failed,
                $batch->allowFailures ? 1 : 0, $batch->callbacksJson(), $batch->createdAt, $batch->cancelledAt, $batch->finishedAt,
            ],
        );
    }

    public function batch(string $id): ?BatchRecord
    {
        $row = $this->connection->select(
            "SELECT id, tenant, panel, user_id, total, pending, failed, allow_failures, callbacks, created_at, cancelled_at, finished_at
               FROM {$this->batchesTable} WHERE id = ?",
            [$id],
        )[0] ?? null;

        return $row === null ? null : new BatchRecord(
            id: self::text($row['id']),
            tenant: self::text($row['tenant']),
            panel: self::text($row['panel']),
            user: self::text($row['user_id']),
            total: self::number($row['total']),
            pending: self::number($row['pending']),
            failed: self::number($row['failed']),
            allowFailures: (bool) $row['allow_failures'],
            createdAt: self::number($row['created_at']),
            cancelledAt: $row['cancelled_at'] === null ? null : self::number($row['cancelled_at']),
            finishedAt: $row['finished_at'] === null ? null : self::number($row['finished_at']),
            callbacks: BatchRecord::callbacksFrom($row['callbacks']),
        );
    }

    public function batchJobDone(string $id, string $job, int $now): ?BatchStep
    {
        return $this->atomically(function () use ($id, $job, $now): ?BatchStep {
            if (!$this->insertIgnoring($this->endsTable, ['batch_id', 'job_id'], [$id, $job])) {
                return $this->unmoved($id);
            }
            $this->connection->execute("UPDATE {$this->batchesTable} SET pending = pending - 1 WHERE id = ? AND pending > 0", [$id]);

            return $this->step($id, $now, firstFailure: false);
        });
    }

    public function batchJobFailed(string $id, string $job, int $now): ?BatchStep
    {
        return $this->atomically(function () use ($id, $job, $now): ?BatchStep {
            if (!$this->insertIgnoring($this->endsTable, ['batch_id', 'job_id'], [$id, $job])) {
                return $this->unmoved($id);
            }
            if ($this->connection->execute(
                "UPDATE {$this->batchesTable} SET pending = pending - 1, failed = failed + 1 WHERE id = ? AND pending > 0",
                [$id],
            ) !== 1) {
                return $this->step($id, $now, firstFailure: false);
            }
            // The first failure cancels a batch that allows none: its jobs
            // still waiting are counted, not run.
            $this->connection->execute(
                "UPDATE {$this->batchesTable} SET cancelled_at = ? WHERE id = ? AND cancelled_at IS NULL AND allow_failures = ?",
                [$now, $id, 0],
            );
            // Read after this update, which holds the row until the
            // transaction ends: one failure, of any number of workers', is
            // the one that reads 1.
            $failed = $this->connection->select("SELECT failed FROM {$this->batchesTable} WHERE id = ?", [$id])[0]['failed'] ?? 0;

            return $this->step($id, $now, firstFailure: self::number($failed) === 1);
        });
    }

    /** The batch as it stands, for an end it has counted already: nothing moved, nothing finished. */
    private function unmoved(string $id): ?BatchStep
    {
        $batch = $this->batch($id);

        return $batch === null ? null : new BatchStep($batch, false, false);
    }

    /** The batch after a count moved, finished if that was its last pending job -- once, by a conditional update. */
    private function step(string $id, int $now, bool $firstFailure): ?BatchStep
    {
        $finished = $this->connection->execute(
            "UPDATE {$this->batchesTable} SET finished_at = ? WHERE id = ? AND pending = 0 AND finished_at IS NULL",
            [$now, $id],
        ) === 1;
        $batch = $this->batch($id);

        return $batch === null ? null : new BatchStep($batch, $finished, $firstFailure);
    }

    public function prune(int $now, int $finishedBefore): int
    {
        return $this->atomically(function () use ($now, $finishedBefore): int {
            $this->connection->execute(
                "DELETE FROM {$this->endsTable} WHERE batch_id IN (SELECT id FROM {$this->batchesTable} WHERE finished_at IS NOT NULL AND finished_at < ?)",
                [$finishedBefore],
            );

            return $this->connection->execute("DELETE FROM {$this->batchesTable} WHERE finished_at IS NOT NULL AND finished_at < ?", [$finishedBefore])
                + $this->connection->execute("DELETE FROM {$this->locksTable} WHERE expires_at <= ?", [$now]);
        });
    }

    public function atomically(\Closure $work): mixed
    {
        return $this->connection->transaction($work);
    }

    /**
     * Inserts a row unless one has its key, without failing the transaction
     * it runs in -- which a unique violation would, on Postgres: true when
     * it inserted.
     *
     * @param list<string> $columns
     * @param list<mixed> $values
     */
    private function insertIgnoring(string $table, array $columns, array $values): bool
    {
        $list = implode(', ', $columns);
        $marks = implode(', ', array_fill(0, \count($columns), '?'));
        $sql = $this->connection instanceof NamesDriver && $this->connection->driver() === 'mysql'
            ? "INSERT IGNORE INTO {$table} ({$list}) VALUES ({$marks})"
            : "INSERT INTO {$table} ({$list}) VALUES ({$marks}) ON CONFLICT DO NOTHING";

        return $this->connection->execute($sql, $values) === 1;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }

    private static function number(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) && is_numeric($value) ? (int) $value : 0);
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
        return $this->connection instanceof NamesDriver && $this->connection->driver() === 'pgsql';
    }
}
