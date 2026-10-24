<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Orm\Connection;

/**
 * Nonces in a table of the application's database, so every worker and
 * server refuses a delivery any of them has seen. The primary key does the
 * refusing: of two workers inserting one nonce, one insert counts.
 */
final readonly class DatabaseNonceStore implements NonceStore
{
    public function __construct(private Connection $connection, private string $table = 'webhook_nonces')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    public static function install(Connection $connection, string $table = 'webhook_nonces'): self
    {
        $store = new self($connection, $table);
        $connection->execute(
            "CREATE TABLE IF NOT EXISTS {$table} (scope TEXT NOT NULL, nonce TEXT NOT NULL, expires_at BIGINT NOT NULL, PRIMARY KEY (scope, nonce))",
        );

        return $store;
    }

    public function claim(string $scope, string $nonce, int $expiresAt, int $now): bool
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE expires_at < ?", [$now]);

        return $this->connection->execute(
            "INSERT INTO {$this->table} (scope, nonce, expires_at) VALUES (?, ?, ?) ON CONFLICT DO NOTHING",
            [$scope, $nonce, $expiresAt],
        ) === 1;
    }

    public function release(string $scope, string $nonce): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE scope = ? AND nonce = ?", [$scope, $nonce]);
    }
}
