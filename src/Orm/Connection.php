<?php

declare(strict_types=1);

namespace Tinvar\Orm;

/**
 * The ORM's one driver interface. PDO backs the sync path; an amphp driver
 * backs fiber mode later (tinvar-spec.md, "Engine layers").
 *
 * Statement counting is part of the interface because query budgets and the
 * batching assertions in the test layer are built on it.
 */
interface Connection
{
    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /** @param list<mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int;

    /** Statements issued since the last reset: the number batching must hold down. */
    public function statementCount(): int;

    public function resetStatementCount(): void;

    /** @return list<string> the SQL issued since the last reset, for assertions and the dev toolbar */
    public function statementLog(): array;

    public function name(): string;

    /**
     * Runs $work in a transaction, committing on return and rolling back on a
     * throw. Nested calls join the outer transaction rather than opening a
     * second one, so an inner rollback does not silently commit the outer.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed;

    public function inTransaction(): bool;
}
