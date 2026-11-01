<?php

declare(strict_types=1);

namespace Tinvar\Actions;

/**
 * Hears every action that runs, around its body: what the audit log is to
 * the action runner (ADR 0150), held behind this port so the runner names
 * no module above it.
 *
 * around() is handed the body once the call has passed resolution,
 * #[Authorize] and validation -- a Precognition call never reaches it --
 * and must call it once and answer what it answered. What it writes after
 * the body writes on the Orm's connection, and where transactional() says
 * so the runner opens the action's transaction around the body and the
 * check of its answer, so what it wrote commits or rolls back with what
 * the action wrote.
 */
interface ActionObserver
{
    /** Whether $action must run in a transaction for what this observer writes of it. */
    public function transactional(CompiledAction $action): bool;

    /**
     * Runs $body, the action's, with the arguments it is called with.
     *
     * @param array<string, mixed> $arguments by parameter name
     * @param \Closure(): mixed $body
     */
    public function around(CompiledAction $action, array $arguments, \Closure $body): mixed;
}
