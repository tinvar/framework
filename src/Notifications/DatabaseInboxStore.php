<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;

/**
 * Inbox entries in a table of the application's database, one row each.
 *
 * Every statement but prune() names the tenant and the user: an entry's id
 * read from a request finds nothing in another tenant or of another user.
 * Moments are written as the ORM writes them (TimestampStorage), so they
 * sort as they read on SQLite and are instants on Postgres.
 */
final readonly class DatabaseInboxStore implements InboxStore
{
    public const string TABLE = 'notification_inbox';

    /** Ids marked read by one statement. */
    private const int CHUNK = 100;

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
        $schema->create($table, static function (Table $entries) use ($table): void {
            $entries->string('id', 36)->primary();
            $entries->string('tenant');
            $entries->string('user_id');
            $entries->string('type', 64);
            $entries->string('title', InboxMessage::MAX_TITLE);
            $entries->text('body');
            $entries->text('url')->nullable();
            $entries->timestamp('created_at');
            $entries->timestamp('read_at')->nullable();
            $entries->index(['tenant', 'user_id', 'id'], "{$table}_feed");
            $entries->index(['tenant', 'user_id', 'read_at'], "{$table}_unread");
            $entries->index(['created_at'], "{$table}_age");
        }, $ifNotExists);
    }

    public function add(InboxEntry $entry): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, tenant, user_id, type, title, body, url, created_at, read_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $entry->id,
                $entry->tenant,
                $entry->user,
                $entry->type,
                $entry->message->title,
                $entry->message->body,
                $entry->message->url,
                $this->moment($entry->createdAt),
                $entry->readAt === null ? null : $this->moment($entry->readAt),
            ],
        );
    }

    public function page(string $tenant, string $user, int $limit, ?string $before = null): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $before === null
            ? $this->connection->select("SELECT * FROM {$this->table} WHERE tenant = ? AND user_id = ? ORDER BY id DESC LIMIT {$limit}", [$tenant, $user])
            : $this->connection->select("SELECT * FROM {$this->table} WHERE tenant = ? AND user_id = ? AND id < ? ORDER BY id DESC LIMIT {$limit}", [$tenant, $user, $before]);

        return array_values(array_filter(array_map(self::entry(...), $rows)));
    }

    public function unread(string $tenant, string $user): int
    {
        $rows = $this->connection->select("SELECT COUNT(*) AS unread FROM {$this->table} WHERE tenant = ? AND user_id = ? AND read_at IS NULL", [$tenant, $user]);
        $count = $rows[0]['unread'] ?? 0;

        return is_numeric($count) ? (int) $count : 0;
    }

    public function markRead(string $tenant, string $user, ?array $ids, CarbonImmutable $at): int
    {
        $moment = $this->moment($at);
        if ($ids === null) {
            return $this->connection->execute(
                "UPDATE {$this->table} SET read_at = ? WHERE tenant = ? AND user_id = ? AND read_at IS NULL",
                [$moment, $tenant, $user],
            );
        }

        $marked = 0;
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $marks = implode(', ', array_fill(0, \count($chunk), '?'));
            $marked += $this->connection->execute(
                "UPDATE {$this->table} SET read_at = ? WHERE tenant = ? AND user_id = ? AND read_at IS NULL AND id IN ({$marks})",
                [$moment, $tenant, $user, ...$chunk],
            );
        }

        return $marked;
    }

    public function prune(CarbonImmutable $before): int
    {
        return $this->connection->execute("DELETE FROM {$this->table} WHERE created_at < ?", [$this->moment($before)]);
    }

    private function moment(CarbonImmutable $at): string
    {
        return TimestampStorage::for($this->connection)->format($at);
    }

    /** @param array<string, mixed> $row */
    private static function entry(array $row): ?InboxEntry
    {
        $string = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';
        $read = $row['read_at'] ?? null;

        try {
            return new InboxEntry(
                id: $string($row['id'] ?? ''),
                tenant: $string($row['tenant'] ?? ''),
                user: $string($row['user_id'] ?? ''),
                type: $string($row['type'] ?? ''),
                message: new InboxMessage($string($row['title'] ?? ''), $string($row['body'] ?? ''), ($row['url'] ?? null) === null ? null : $string($row['url'])),
                createdAt: CarbonImmutable::parse($string($row['created_at'] ?? ''), 'UTC')->utc(),
                readAt: $read === null ? null : CarbonImmutable::parse($string($read), 'UTC')->utc(),
            );
        } catch (\InvalidArgumentException) {
            // A row written by hand that no message could be: shown as
            // nothing rather than as markup or a link off the site.
            return null;
        }
    }
}
