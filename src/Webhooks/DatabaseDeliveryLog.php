<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Support\Uuid;

/**
 * Delivery attempts in a table of the application's database, one row a
 * try, written as each try ends -- outside any transaction the job holds,
 * since a worker runs a job in none, so a try that throws is recorded as
 * surely as one that succeeds.
 */
final readonly class DatabaseDeliveryLog implements DeliveryLog
{
    public function __construct(private Connection $connection, private string $table = 'webhook_deliveries')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this log will write.");
        }
    }

    /** The log's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'webhook_deliveries', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $attempts) use ($table): void {
            $attempts->string('id')->primary();
            $attempts->string('delivery', 64);
            $attempts->string('subscription', 128);
            $attempts->string('event', 64);
            $attempts->string('tenant');
            $attempts->integer('attempt');
            $attempts->string('outcome', 16);
            $attempts->integer('status')->nullable();
            $attempts->string('reason', 32)->nullable();
            $attempts->integer('milliseconds');
            $attempts->bigInteger('attempted_at');
            $attempts->index(['delivery'], "{$table}_delivery");
            // What a tenant's deliveries page asks: theirs, newest first.
            $attempts->index(['tenant', 'attempted_at'], "{$table}_tenant_recent");
        }, $ifNotExists);
    }

    public function record(DeliveryAttempt $attempt): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, delivery, subscription, event, tenant, attempt, outcome, status, reason, milliseconds, attempted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                Uuid::v7(),
                $attempt->delivery,
                $attempt->subscription,
                $attempt->event,
                $attempt->tenant,
                $attempt->attempt,
                $attempt->outcome,
                $attempt->status,
                $attempt->reason,
                $attempt->milliseconds,
                $attempt->attemptedAt,
            ],
        );
    }

    public function attempts(string $delivery): array
    {
        return array_map(self::attempt(...), $this->connection->select(
            "SELECT * FROM {$this->table} WHERE delivery = ? ORDER BY attempt, attempted_at",
            [$delivery],
        ));
    }

    public function recent(string $tenant, int $limit = 50): array
    {
        return array_map(self::attempt(...), $this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant = ? ORDER BY attempted_at DESC, id DESC LIMIT " . max(0, $limit),
            [$tenant],
        ));
    }

    /** @param array<string, mixed> $row */
    private static function attempt(array $row): DeliveryAttempt
    {
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $string = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';

        return new DeliveryAttempt(
            delivery: $string($row['delivery'] ?? ''),
            subscription: $string($row['subscription'] ?? ''),
            event: $string($row['event'] ?? ''),
            tenant: $string($row['tenant'] ?? ''),
            attempt: $int($row['attempt'] ?? 0),
            outcome: $string($row['outcome'] ?? ''),
            status: isset($row['status']) ? $int($row['status']) : null,
            reason: isset($row['reason']) ? $string($row['reason']) : null,
            milliseconds: $int($row['milliseconds'] ?? 0),
            attemptedAt: $int($row['attempted_at'] ?? 0),
        );
    }
}
