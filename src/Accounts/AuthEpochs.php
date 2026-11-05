<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Each user's auth epoch, in a table of its own: the number the framework
 * binds client-held data to beside the session and the user (AuthContext),
 * moved whenever what the user may see, or who may act as them, changes.
 *
 * "Client-held data ... is cleared ... on logout, ... role change,
 * impersonation start and stop" (tinvar-spec.md). The kit moves it on every
 * sign-in and sign-out, a password change or reset, a change of address
 * and "sign out everywhere": every route token, #[Once] binding, history
 * key, queued job running as the user and emailed link minted before stops
 * counting, in every tab and on every device.
 *
 * Keyed by user rather than a column on the users table: a role change is
 * the user's, not a session's, and an application's own users table needs
 * nothing added to it. A user with no row is at epoch 0.
 */
final readonly class AuthEpochs
{
    public const string TABLE = 'auth_epochs';

    public function __construct(private Connection $connection) {}

    /**
     * The table, its user column a foreign key to $userModel, so a user who
     * is deleted takes their epoch with them.
     *
     * @param class-string<Model> $userModel
     */
    public static function define(Schema $schema, string $userModel, bool $ifNotExists = false): void
    {
        $schema->create(self::TABLE, static function (Table $epochs) use ($userModel): void {
            $epochs->foreign($userModel, 'user_id')->primary()->cascadeOnDelete();
            $epochs->integer('epoch');
        }, $ifNotExists);
    }

    /** The user's epoch, as AuthContext takes it. */
    public function of(string $user): string
    {
        $epoch = $this->connection->select(
            'SELECT epoch FROM ' . self::TABLE . ' WHERE user_id = ?',
            [$user],
        )[0]['epoch'] ?? 0;

        return (string) (\is_int($epoch) || \is_string($epoch) ? $epoch : 0);
    }

    /**
     * Moves the user's epoch on. Inside a transaction, it is part of it: a
     * sign-in's, a reset's. Alone, it is a durable transaction of its own:
     * an epoch a power cut moved back would make what it cleared count again.
     */
    public function bump(string $user): void
    {
        $sql = self::bumping($this->connection, self::TABLE);
        $connection = $this->connection;
        if ($connection->inTransaction()) {
            $connection->execute($sql, [$user]);

            return;
        }

        $connection->durableTransaction(static fn (): int => $connection->execute($sql, [$user]));
    }

    /**
     * The statement that moves a user's row of $table -- this one, or the
     * credential epochs' -- on by one, made at 1 where there is none: an
     * upsert, which MySQL spells its own way.
     *
     * @internal for the kit's epoch tables
     */
    public static function bumping(Connection $connection, string $table): string
    {
        return $connection instanceof NamesDriver && $connection->driver() === 'mysql'
            ? "INSERT INTO {$table} (user_id, epoch) VALUES (?, 1) ON DUPLICATE KEY UPDATE epoch = epoch + 1"
            : "INSERT INTO {$table} (user_id, epoch) VALUES (?, 1) ON CONFLICT (user_id) DO UPDATE SET epoch = {$table}.epoch + 1";
    }
}
