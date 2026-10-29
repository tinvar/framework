<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Sent events in a table of the application's database, one row a
 * delivery, written as it is queued -- inside the sender's transaction, so
 * an event a rollback takes back is not kept either.
 */
final readonly class DatabaseSentWebhooks implements SentWebhooks
{
    public function __construct(private Connection $connection, private string $table = 'webhook_events')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'webhook_events', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $events) use ($table): void {
            $events->string('delivery', 64)->primary();
            $events->string('tenant');
            $events->string('subscription', 128);
            $events->string('event', 64);
            $events->text('data');
            $events->bigInteger('sent_at');
            $events->string('resend_of', 64)->nullable();
            $events->string('resent_by')->nullable();
            $events->index(['tenant', 'resend_of'], "{$table}_resends");
            $events->index(['sent_at'], "{$table}_sent_at");
        }, $ifNotExists);
    }

    public function keep(SentWebhook $sent): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (delivery, tenant, subscription, event, data, sent_at, resend_of, resent_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$sent->delivery, $sent->tenant, $sent->subscription, $sent->event, $sent->data, $sent->sentAt, $sent->resendOf, $sent->resentBy],
        );
    }

    public function find(string $tenant, string $delivery): ?SentWebhook
    {
        $rows = $this->connection->select("SELECT * FROM {$this->table} WHERE tenant = ? AND delivery = ?", [$tenant, $delivery]);

        return $rows === [] ? null : self::sent($rows[0]);
    }

    public function many(string $tenant, array $deliveries): array
    {
        if ($deliveries === []) {
            return [];
        }
        $found = [];
        foreach ($this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant = ? AND delivery IN (" . implode(', ', array_fill(0, \count($deliveries), '?')) . ')',
            [$tenant, ...$deliveries],
        ) as $row) {
            $sent = self::sent($row);
            $found[$sent->delivery] = $sent;
        }

        return $found;
    }

    public function resendsOf(string $tenant, array $deliveries): array
    {
        if ($deliveries === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, \count($deliveries), '?'));
        $resends = [];
        foreach ($this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant = ? AND resend_of IN ({$marks}) ORDER BY sent_at, delivery",
            [$tenant, ...$deliveries],
        ) as $row) {
            $sent = self::sent($row);
            $resends[(string) $sent->resendOf][] = $sent;
        }

        return $resends;
    }

    public function prune(int $before, int $limit): int
    {
        // The deliveries read first: MySQL refuses a LIMIT in a subquery
        // naming the table it deletes from.
        $deliveries = array_map(
            static fn (array $row): string => \is_scalar($row['delivery'] ?? null) ? (string) $row['delivery'] : '',
            $this->connection->select("SELECT delivery FROM {$this->table} WHERE sent_at < ? ORDER BY sent_at LIMIT " . max(1, $limit), [$before]),
        );
        if ($deliveries === []) {
            return 0;
        }

        return $this->connection->execute(
            "DELETE FROM {$this->table} WHERE delivery IN (" . implode(', ', array_fill(0, \count($deliveries), '?')) . ')',
            $deliveries,
        );
    }

    /** @param array<string, mixed> $row */
    private static function sent(array $row): SentWebhook
    {
        $string = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';

        return new SentWebhook(
            delivery: $string($row['delivery'] ?? ''),
            tenant: $string($row['tenant'] ?? ''),
            subscription: $string($row['subscription'] ?? ''),
            event: $string($row['event'] ?? ''),
            data: $string($row['data'] ?? ''),
            sentAt: is_numeric($row['sent_at'] ?? null) ? (int) $row['sent_at'] : 0,
            resendOf: isset($row['resend_of']) ? $string($row['resend_of']) : null,
            resentBy: isset($row['resent_by']) ? $string($row['resent_by']) : null,
        );
    }
}
