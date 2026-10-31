<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * The suppression list in a table of the application's database, so every
 * worker and server reads what any webhook wrote.
 *
 * One row an address, its primary key. A suppression replaces the row only
 * when it stops as much or more (SuppressionReason::weight()), inside one
 * transaction, so a complaint arriving beside a bounce never leaves the
 * weaker of the two.
 */
final readonly class DatabaseSuppressions implements Suppressions
{
    public const string TABLE = 'mail_suppressions';

    /** Addresses looked up by one query; a message has at most Message::MAX_RECIPIENTS. */
    private const int CHUNK = 100;

    public function __construct(private Connection $connection, private string $table = self::TABLE)
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this list will write.");
        }
    }

    /** The list's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);
        $schema->create($table, static function (Table $rows): void {
            $rows->string('email', 320);
            $rows->string('reason', 16);
            $rows->string('source', 32);
            $rows->string('detail', Suppression::MAX_DETAIL)->nullable();
            $rows->bigInteger('suppressed_at');
            $rows->primary(['email']);
        }, $ifNotExists);
    }

    public function find(array $emails): array
    {
        $keys = array_values(array_unique(array_map(static fn (string $email): string => strtolower(trim($email)), $emails)));
        $found = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $marks = implode(', ', array_fill(0, \count($chunk), '?'));
            foreach ($this->connection->select("SELECT email, reason, source, detail, suppressed_at FROM {$this->table} WHERE email IN ({$marks})", $chunk) as $row) {
                $suppression = self::row($row);
                if ($suppression !== null) {
                    $found[$suppression->email] = $suppression;
                }
            }
        }

        return $found;
    }

    public function suppress(Suppression $suppression): void
    {
        $this->connection->transaction(function () use ($suppression): void {
            $held = $this->connection->select("SELECT email, reason, source, detail, suppressed_at FROM {$this->table} WHERE email = ?", [$suppression->email]);
            $current = $held === [] ? null : self::row($held[0]);
            if ($current !== null && $suppression->reason->weight() < $current->reason->weight()) {
                return;
            }
            $this->connection->execute("DELETE FROM {$this->table} WHERE email = ?", [$suppression->email]);
            $this->connection->execute(
                "INSERT INTO {$this->table} (email, reason, source, detail, suppressed_at) VALUES (?, ?, ?, ?, ?)",
                [$suppression->email, $suppression->reason->value, $suppression->source, $suppression->detail, $suppression->at],
            );
        });
    }

    public function lift(string $email): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE email = ?", [strtolower(trim($email))]);
    }

    /** @param array<array-key, mixed> $row */
    private static function row(array $row): ?Suppression
    {
        $reason = SuppressionReason::tryFrom((string) ($row['reason'] ?? ''));
        if ($reason === null) {
            return null;
        }

        return new Suppression(
            (string) $row['email'],
            $reason,
            (string) $row['source'],
            (int) $row['suppressed_at'],
            isset($row['detail']) ? (string) $row['detail'] : null,
        );
    }
}
