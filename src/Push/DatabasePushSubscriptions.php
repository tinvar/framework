<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Support\Uuid;

/**
 * Push subscriptions in a table of the application's database, one row a
 * browser per tenant, found by the tenant and a hash of the endpoint.
 *
 * Every statement names the tenant, whatever else it names: a
 * subscription's id from a job or a form finds nothing in another tenant.
 *
 * Each row carries the auth epoch its user subscribed at (`auth_epoch`,
 * ADR 0124). A table made by define() before it had that column needs it
 * added -- a string, '' for the rows already there, which then match no
 * epoch but a user's who has never had one, and are removed at their next
 * push otherwise.
 */
final readonly class DatabasePushSubscriptions implements PushSubscriptions
{
    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(
        private Connection $connection,
        private string $table = 'push_subscriptions',
        private PushServices $services = new PushServices(),
        private ?\Closure $clock = null,
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'push_subscriptions', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $subscriptions) use ($table): void {
            $subscriptions->string('id')->primary();
            $subscriptions->string('tenant');
            $subscriptions->string('user_id');
            $subscriptions->string('auth_epoch');
            $subscriptions->string('endpoint_hash', 64);
            $subscriptions->text('endpoint');
            $subscriptions->string('p256dh', 128);
            $subscriptions->string('auth', 32);
            $subscriptions->bigInteger('created_at');
            $subscriptions->unique(['tenant', 'endpoint_hash'], "{$table}_endpoint");
            $subscriptions->index(['tenant', 'user_id'], "{$table}_user");
        }, $ifNotExists);
    }

    public function subscribe(string $tenant, string $user, string $epoch, string $endpoint, string $p256dh, string $auth): PushSubscription
    {
        if ($user === '') {
            throw new \InvalidArgumentException('A push subscription is held by a user.');
        }
        if (!$this->services->allows($endpoint)) {
            throw new \InvalidArgumentException('A push endpoint is at a push service the application names.');
        }
        $subscription = new PushSubscription(Uuid::v7(), $tenant, $user, $epoch, $endpoint, $p256dh, $auth, $this->now());

        // Whoever held this browser in this tenant before holds it no more.
        $this->connection->transaction(function () use ($subscription): void {
            $this->connection->execute("DELETE FROM {$this->table} WHERE tenant = ? AND endpoint_hash = ?", [$subscription->tenant, self::hash($subscription->endpoint)]);
            $this->connection->execute(
                "INSERT INTO {$this->table} (id, tenant, user_id, auth_epoch, endpoint_hash, endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$subscription->id, $subscription->tenant, $subscription->user, $subscription->epoch, self::hash($subscription->endpoint), $subscription->endpoint, $subscription->p256dh, $subscription->auth, $subscription->createdAt],
            );
        });

        return $subscription;
    }

    public function unsubscribe(string $tenant, string $user, string $endpoint): bool
    {
        return $this->connection->execute(
            "DELETE FROM {$this->table} WHERE tenant = ? AND user_id = ? AND endpoint_hash = ?",
            [$tenant, $user, self::hash($endpoint)],
        ) > 0;
    }

    public function forUser(string $tenant, string $user): array
    {
        return array_map(self::subscription(...), $this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant = ? AND user_id = ? ORDER BY created_at, id",
            [$tenant, $user],
        ));
    }

    public function find(string $tenant, string $id): ?PushSubscription
    {
        $rows = $this->connection->select("SELECT * FROM {$this->table} WHERE tenant = ? AND id = ?", [$tenant, $id]);

        return $rows === [] ? null : self::subscription($rows[0]);
    }

    public function remove(string $tenant, string $id): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE tenant = ? AND id = ?", [$tenant, $id]);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @param array<string, mixed> $row */
    private static function subscription(array $row): PushSubscription
    {
        $string = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';

        return new PushSubscription(
            id: $string($row['id'] ?? ''),
            tenant: $string($row['tenant'] ?? ''),
            user: $string($row['user_id'] ?? ''),
            epoch: $string($row['auth_epoch'] ?? ''),
            endpoint: $string($row['endpoint'] ?? ''),
            p256dh: $string($row['p256dh'] ?? ''),
            auth: $string($row['auth'] ?? ''),
            createdAt: is_numeric($row['created_at'] ?? null) ? (int) $row['created_at'] : 0,
        );
    }
}
