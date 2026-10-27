<?php

declare(strict_types=1);

namespace Tinvar\Http;

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
 *
 * Durable or not. On a SQLite connection syncing NORMAL (ADR 0050) a claim
 * an OS crash or power cut takes back is a nonce that can be spent again
 * for as long as it is valid. A webhook's claim is left to the next
 * checkpoint: it is lost only with the handler's writes after it, so a
 * replay re-applies what was lost, where a claim kept without them would
 * turn the sender's retry into a refused replay and lose the delivery. A
 * one-time code's is not: a handoff code, a consent ticket -- what a
 * security decision rests on, and nothing after it here to sync it -- is
 * claimed `durable`, its commit on disk before claim() returns.
 */
final readonly class DatabaseNonceStore implements NonceStore
{
    /** The longest nonce the table holds whole, in bytes. */
    public const int MAX_NONCE_BYTES = 128;

    private string $insert;

    /**
     * @param bool $durable whether each claim's commit is synced in full
     *        (Connection::durableTransaction()) however the connection syncs
     *        the rest. Inside a transaction of the caller's, a claim is part
     *        of it, and as durable as its commit.
     */
    public function __construct(private Connection $connection, private string $table = 'webhook_nonces', public bool $durable = false)
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

        $claim = function () use ($scope, $nonce, $expiresAt, $now): bool {
            $this->connection->execute("DELETE FROM {$this->table} WHERE expires_at < ?", [$now]);

            return $this->connection->execute($this->insert, [hash('sha256', $scope), $nonce, $expiresAt]) === 1;
        };

        return $this->durable && !$this->connection->inTransaction() ? $this->connection->durableTransaction($claim) : $claim();
    }

    public function release(string $scope, string $nonce): void
    {
        $this->connection->execute("DELETE FROM {$this->table} WHERE scope = ? AND nonce = ?", [hash('sha256', $scope), $nonce]);
    }
}
