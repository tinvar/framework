<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;

/**
 * Notification preferences in a table of the application's database: one
 * row a choice, keyed by tenant, user, type and channel, every statement
 * naming the tenant and the user.
 */
final readonly class DatabaseNotificationPreferences implements NotificationPreferences
{
    public const string TABLE = 'notification_preferences';

    public function __construct(private Connection $connection, private string $table = self::TABLE)
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);
        $schema->create($table, static function (Table $choices): void {
            $choices->string('tenant');
            $choices->string('user_id');
            $choices->string('type', 64);
            $choices->string('channel', 16);
            $choices->boolean('enabled');
            $choices->timestamp('updated_at');
            $choices->primary(['tenant', 'user_id', 'type', 'channel']);
        }, $ifNotExists);
    }

    public function chosen(string $tenant, string $user): array
    {
        $chosen = [];
        foreach ($this->connection->select("SELECT type, channel, enabled FROM {$this->table} WHERE tenant = ? AND user_id = ? ORDER BY type, channel", [$tenant, $user]) as $row) {
            $type = $row['type'] ?? null;
            $channel = NotificationChannel::tryFrom(\is_string($row['channel'] ?? null) ? $row['channel'] : '');
            if (!\is_string($type) || $channel === null) {
                continue;
            }
            $enabled = $row['enabled'] ?? false;
            $chosen[$type][$channel->value] = $enabled === true || $enabled === 1 || $enabled === '1' || $enabled === 't';
        }

        return $chosen;
    }

    public function choose(string $tenant, string $user, string $type, NotificationChannel $channel, bool $on): void
    {
        $now = TimestampStorage::for($this->connection)->format(CarbonImmutable::now('UTC'));
        $this->connection->transaction(function () use ($tenant, $user, $type, $channel, $on, $now): void {
            $this->connection->execute(
                "DELETE FROM {$this->table} WHERE tenant = ? AND user_id = ? AND type = ? AND channel = ?",
                [$tenant, $user, $type, $channel->value],
            );
            $this->connection->execute(
                "INSERT INTO {$this->table} (tenant, user_id, type, channel, enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$tenant, $user, $type, $channel->value, $on, $now],
            );
        });
    }
}
