<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

/**
 * Where workflows' progress and the signals sent to them are kept (ADR
 * 0119): `DatabaseWorkflowStore` in the application's own database, or
 * `ArrayWorkflowStore` in memory for tests.
 *
 * It stores and never decides: who may read a record or send a signal is
 * Workflows', held to the tenant and panel the workflow was started in.
 */
interface WorkflowStore
{
    /** Keeps $record, unless one is kept under its id already. */
    public function start(WorkflowRecord $record): void;

    public function find(string $id): ?WorkflowRecord;

    /**
     * Moves workflow $id on, unless it has ended: an ended status is the
     * last word, and a run that ends it sets when.
     */
    public function update(string $id, string $status, ?string $step, int $steps, ?int $wakeAt, int $now): void;

    /**
     * Keeps signal $name for workflow $id with its payload (JSON), unless
     * one of that name was sent already: whether this one was kept.
     */
    public function signal(string $id, string $name, string $payload, int $now): bool;

    /** The payload (JSON) of signal $name sent to workflow $id; null when none has been. */
    public function received(string $id, string $name): ?string;

    /** Forgets workflows that ended before $endedBefore, and their signals: how many. */
    public function prune(int $endedBefore): int;
}
