<?php

declare(strict_types=1);

namespace Tinvar\Orm;

final class PdoConnection implements Connection
{
    private int $statements = 0;

    /** @var list<string> */
    private array $log = [];

    private bool $logSql = true;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $name = 'default',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function postgres(string $dsn, ?string $user = null, ?string $password = null, string $name = 'default'): self
    {
        return new self(new \PDO($dsn, $user, $password, [\PDO::ATTR_PERSISTENT => false]), $name);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->record($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->record($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public function statementCount(): int
    {
        return $this->statements;
    }

    public function resetStatementCount(): void
    {
        $this->statements = 0;
        $this->log = [];
    }

    public function statementLog(): array
    {
        return $this->log;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Turned off in benchmarks so log-keeping does not colour the numbers. */
    public function logSql(bool $on): void
    {
        $this->logSql = $on;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    private function record(string $sql): void
    {
        $this->statements++;
        if ($this->logSql) {
            $this->log[] = $sql;
        }
    }
}
