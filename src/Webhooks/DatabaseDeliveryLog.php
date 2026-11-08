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
            // What a subscription's own log asks (ADR 0197).
            $attempts->index(['tenant', 'subscription', 'attempted_at'], "{$table}_subscription_recent");
            // What pruning asks: every tenant's, oldest first (prune()).
            $attempts->index(['attempted_at'], "{$table}_attempted");
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

    public function forSubscription(string $tenant, string $subscription, int $limit = 50): array
    {
        // On the `_subscription_recent` index define() makes; a table made
        // before ADR 0197 reads it off `_tenant_recent` until a migration
        // adds it (DatabaseDeliveryLog::addSubscriptionIndex()).
        return array_map(self::attempt(...), $this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant = ? AND subscription = ? ORDER BY attempted_at DESC, id DESC LIMIT " . max(0, $limit),
            [$tenant, $subscription],
        ));
    }

    /**
     * The index forSubscription() reads by, for a log table define() made
     * before ADR 0197: the application's migration calls this once.
     */
    public static function addSubscriptionIndex(Schema $schema, string $table = 'webhook_deliveries'): void
    {
        new self($schema->connection(), $table);

        $schema->table($table, static function (Table $attempts) use ($table): void {
            $attempts->index(['tenant', 'subscription', 'attempted_at'], "{$table}_subscription_recent");
        });
    }

    public function prune(int $before, int $limit): int
    {
        // The ids read first, oldest first, as SentWebhooks prunes: MySQL
        // refuses a LIMIT in a subquery naming the table it deletes from.
        // On the `_attempted` index define() makes; a table made before it
        // had one is read whole, a batch at a time, until a migration adds it.
        $ids = array_map(
            static fn (array $row): string => \is_scalar($row['id'] ?? null) ? (string) $row['id'] : '',
            $this->connection->select("SELECT id FROM {$this->table} WHERE attempted_at < ? ORDER BY attempted_at LIMIT " . max(1, $limit), [$before]),
        );
        if ($ids === []) {
            return 0;
        }

        return $this->connection->execute(
            "DELETE FROM {$this->table} WHERE id IN (" . implode(', ', array_fill(0, \count($ids), '?')) . ')',
            $ids,
        );
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
