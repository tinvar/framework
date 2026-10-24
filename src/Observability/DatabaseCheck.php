<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Orm\Connection;

/** The database answers a statement. */
final class DatabaseCheck implements HealthCheck
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $name = 'database',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        $row = $this->connection->select('SELECT 1 AS ok')[0] ?? null;
        if (!\is_array($row) || (int) ($row['ok'] ?? 0) !== 1) {
            throw new \RuntimeException('The database did not answer SELECT 1.');
        }
    }
}
