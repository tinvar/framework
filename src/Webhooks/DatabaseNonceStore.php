<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Nonces in a table of the application's database, so every worker and
 * server refuses a delivery any of them has seen. The primary key does the
 * refusing: of two workers inserting one nonce, one insert counts -- by
 * `ON CONFLICT DO NOTHING` on SQLite and Postgres, `INSERT IGNORE` on
 * MySQL.
 */
final readonly class DatabaseNonceStore implements NonceStore
{
    /** The longest nonce the table holds whole, in bytes. */
    public const int MAX_NONCE_BYTES = 128;

    private string $insert;

    public function __construct(private Connection $connection, private string $table = 'webhook_nonces')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
        $this->insert = Dialect::for($connection)->insertUnlessTaken($table, ['scope', 'nonce', 'expires_at']);
    }

    /**
     * The store's table, for the application's migration to make. A scope
     * is kept as its hash: a webhook's class and a tenant can be long, and
     * a key only has to tell them apart.
     */
    public static function define(Schema $schema, string $table = 'webhook_nonces', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $nonces): void {
            $nonces->string('scope', 64);
            $nonces->string('nonce', self::MAX_NONCE_BYTES);
            $nonces->bigInteger('expires_at');
            $nonces->primary(['scope', 'nonce']);
        }, $ifNotExists);
    }

    /**
     * A nonce longer than its column is refused, never claimed: MySQL's
     * INSERT IGNORE would cut it to the column's length, and two nonces
     * sharing their first 128 bytes would be taken for one.
     */
    public function claim(string $scope, string $nonce, int $expiresAt, int $now): bool
    {
        if (\strlen($nonce) > self::MAX_NONCE_BYTES) {
            return false;
        }

        $this->connection->execute("DELETE FROM {$this->table} WHERE expires_at < ?", [$now]);

        return $this->connection->execute($this->insert, [hash('sha256', $scope), $nonce, $expiresAt]) === 1;
    }

    public function release(string $scope, string $nonce): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE scope = ? AND nonce = ?", [hash('sha256', $scope), $nonce]);
    }
}
