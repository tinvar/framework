<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Workflows' progress and their signals in two tables of the application's
 * database (ADR 0119), made by define() in a migration.
 *
 * On the application's connection a workflow's record is written with the
 * transaction it is started in, as its job is on a database queue: a start
 * that rolls back leaves neither. A signal is a row keyed by the workflow
 * and its name, so of two sent at once one is kept -- `ON CONFLICT DO
 * NOTHING`, `INSERT IGNORE` on MySQL.
 */
final readonly class DatabaseWorkflowStore implements WorkflowStore
{
    private string $insertRecord;

    private string $insertSignal;

    public function __construct(
        private Connection $connection,
        private string $table = 'workflows',
        private string $signalsTable = 'workflow_signals',
    ) {
        foreach ([$table, $signalsTable] as $name) {
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $name) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a table name this store will write.");
            }
        }
        $dialect = Dialect::for($connection);
        $this->insertRecord = $dialect->insertUnlessTaken($table, ['id', 'workflow', 'tenant', 'panel', 'user_id', 'status', 'step', 'steps', 'wake_at', 'started_at', 'updated_at', 'finished_at']);
        $this->insertSignal = $dialect->insertUnlessTaken($signalsTable, ['workflow_id', 'name', 'payload', 'received_at']);
    }

    /** The store's tables, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'workflows', string $signalsTable = 'workflow_signals', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table, $signalsTable);

        $schema->create($table, static function (Table $workflows) use ($table): void {
            $workflows->string('id')->primary();
            $workflows->string('workflow', 191);
            $workflows->string('tenant', 191);
            $workflows->string('panel', 64);
            $workflows->string('user_id', 191);
            $workflows->string('status', 16);
            $workflows->string('step', 64)->nullable();
            $workflows->integer('steps')->default(0);
            $workflows->bigInteger('wake_at')->nullable();
            $workflows->bigInteger('started_at');
            $workflows->bigInteger('updated_at');
            $workflows->bigInteger('finished_at')->nullable();
            $workflows->index(['finished_at'], "{$table}_finished");
        }, $ifNotExists);
        $schema->create($signalsTable, static function (Table $signals): void {
            $signals->string('workflow_id');
            $signals->string('name', 64);
            $signals->text('payload');
            $signals->bigInteger('received_at');
            $signals->primary(['workflow_id', 'name']);
        }, $ifNotExists);
    }

    public function start(WorkflowRecord $record): void
    {
        $this->connection->execute($this->insertRecord, [
            $record->id, $record->workflow, $record->tenant, $record->panel, $record->user, $record->status,
            $record->step, $record->steps, $record->wakeAt, $record->startedAt, $record->updatedAt, $record->finishedAt,
        ]);
    }

    public function find(string $id): ?WorkflowRecord
    {
        $row = $this->connection->select(
            "SELECT id, workflow, tenant, panel, user_id, status, step, steps, wake_at, started_at, updated_at, finished_at FROM {$this->table} WHERE id = ?",
            [$id],
        )[0] ?? null;
        if ($row === null) {
            return null;
        }

        $workflow = self::text($row['workflow']);
        if (!is_subclass_of($workflow, Workflow::class)) {
            return null;
        }

        return new WorkflowRecord(
            id: self::text($row['id']),
            workflow: $workflow,
            tenant: self::text($row['tenant']),
            panel: self::text($row['panel']),
            user: self::text($row['user_id']),
            status: self::text($row['status']),
            step: $row['step'] === null ? null : self::text($row['step']),
            steps: self::number($row['steps']),
            wakeAt: $row['wake_at'] === null ? null : self::number($row['wake_at']),
            startedAt: self::number($row['started_at']),
            updatedAt: self::number($row['updated_at']),
            finishedAt: $row['finished_at'] === null ? null : self::number($row['finished_at']),
        );
    }

    public function update(string $id, string $status, ?string $step, int $steps, ?int $wakeAt, int $now): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, step = ?, steps = ?, wake_at = ?, updated_at = ?, finished_at = ?
              WHERE id = ? AND finished_at IS NULL",
            [$status, $step, $steps, $wakeAt, $now, \in_array($status, WorkflowRecord::ENDED, true) ? $now : null, $id],
        );
    }

    public function signal(string $id, string $name, string $payload, int $now): bool
    {
        return $this->connection->execute($this->insertSignal, [$id, $name, $payload, $now]) === 1;
    }

    public function received(string $id, string $name): ?string
    {
        $payload = $this->connection->select("SELECT payload FROM {$this->signalsTable} WHERE workflow_id = ? AND name = ?", [$id, $name])[0]['payload'] ?? null;

        return \is_string($payload) ? $payload : null;
    }

    public function prune(int $endedBefore): int
    {
        return $this->connection->transaction(function () use ($endedBefore): int {
            $this->connection->execute(
                "DELETE FROM {$this->signalsTable} WHERE workflow_id IN (SELECT id FROM {$this->table} WHERE finished_at IS NOT NULL AND finished_at < ?)",
                [$endedBefore],
            );

            return $this->connection->execute("DELETE FROM {$this->table} WHERE finished_at IS NOT NULL AND finished_at < ?", [$endedBefore]);
        });
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }

    private static function number(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) && is_numeric($value) ? (int) $value : 0);
    }
}
