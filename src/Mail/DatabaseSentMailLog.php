<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Orm\Connection;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * The sent-mail log in a table of the application's database: one row a
 * reader of each message a provider took, by the provider, its message id
 * and a digest of the reader's address -- never the address itself.
 *
 * Each record sweeps a batch of rows past the retention, so the table holds
 * what is still reported on and no more, and no record pays for a backlog.
 */
final readonly class DatabaseSentMailLog implements SentMailLog
{
    public const string TABLE = 'mail_sent';

    /** Expired rows deleted, at most, by each record. */
    public const int SWEEP_BATCH = 50;

    /** The longest message id kept whole; a longer one is not recorded, and so never matches. */
    public const int MAX_MESSAGE_ID = 191;

    private string $sweep;

    public function __construct(private Connection $connection, private string $table = self::TABLE)
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this log will write.");
        }
        // By the time index, a batch at a time: MySQL takes a LIMIT on the
        // DELETE itself, and none in an IN subquery.
        $limit = self::SWEEP_BATCH;
        $this->sweep = ($connection instanceof NamesDriver ? $connection->driver() : 'sqlite') === 'mysql'
            ? "DELETE FROM {$table} WHERE sent_at < ? LIMIT {$limit}"
            : "DELETE FROM {$table} WHERE (provider, message_id, recipient) IN ("
                . "SELECT provider, message_id, recipient FROM {$table} WHERE sent_at < ? LIMIT {$limit})";
    }

    /** The log's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = self::TABLE): void
    {
        new self($schema->connection(), $table);
        $schema->create($table, static function (Table $rows) use ($table): void {
            $rows->string('provider', 32);
            $rows->string('message_id', self::MAX_MESSAGE_ID);
            $rows->string('recipient', 64);
            $rows->bigInteger('sent_at');
            $rows->primary(['provider', 'message_id', 'recipient']);
            $rows->index(['sent_at'], "{$table}_sent_at");
        });
    }

    public function record(SentMessage $sent, int $now): void
    {
        if ($sent->messageId === null || $sent->skipped || \strlen($sent->messageId) > self::MAX_MESSAGE_ID) {
            return;
        }
        $id = $sent->messageId;
        $this->connection->transaction(function () use ($sent, $id, $now): void {
            $this->connection->execute($this->sweep, [$now - self::RETENTION]);
            foreach ($sent->message->envelope() as $reader) {
                $digest = hash('sha256', strtolower($reader));
                $this->connection->execute("DELETE FROM {$this->table} WHERE provider = ? AND message_id = ? AND recipient = ?", [$sent->transport, $id, $digest]);
                $this->connection->execute(
                    "INSERT INTO {$this->table} (provider, message_id, recipient, sent_at) VALUES (?, ?, ?, ?)",
                    [$sent->transport, $id, $digest, $now],
                );
            }
        });
    }

    public function wasSent(string $provider, string $messageId, string $recipient, int $now): bool
    {
        return $this->connection->select(
            "SELECT 1 AS found FROM {$this->table} WHERE provider = ? AND message_id = ? AND recipient = ? AND sent_at >= ?",
            [$provider, $messageId, hash('sha256', strtolower($recipient)), $now - self::RETENTION],
        ) !== [];
    }
}
