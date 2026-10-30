<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

/** Workflows' progress and signals in this process's memory: for tests, and a worker in the same process. */
final class ArrayWorkflowStore implements WorkflowStore
{
    /** @var array<string, WorkflowRecord> */
    private array $records = [];

    /** @var array<string, array<string, string>> payloads by workflow and name */
    private array $signals = [];

    public function start(WorkflowRecord $record): void
    {
        $this->records[$record->id] ??= $record;
    }

    public function find(string $id): ?WorkflowRecord
    {
        return $this->records[$id] ?? null;
    }

    public function update(string $id, string $status, ?string $step, int $steps, ?int $wakeAt, int $now): void
    {
        $record = $this->records[$id] ?? null;
        if ($record === null || $record->ended()) {
            return;
        }
        $this->records[$id] = new WorkflowRecord(
            $record->id,
            $record->workflow,
            $record->tenant,
            $record->panel,
            $record->user,
            $status,
            $step,
            $steps,
            $wakeAt,
            $record->startedAt,
            $now,
            \in_array($status, WorkflowRecord::ENDED, true) ? $now : null,
        );
    }

    public function signal(string $id, string $name, string $payload, int $now): bool
    {
        if (isset($this->signals[$id][$name])) {
            return false;
        }
        $this->signals[$id][$name] = $payload;

        return true;
    }

    public function received(string $id, string $name): ?string
    {
        return $this->signals[$id][$name] ?? null;
    }

    public function prune(int $endedBefore): int
    {
        $pruned = 0;
        foreach ($this->records as $id => $record) {
            if ($record->finishedAt !== null && $record->finishedAt < $endedBefore) {
                unset($this->records[$id], $this->signals[$id]);
                $pruned++;
            }
        }

        return $pruned;
    }
}
