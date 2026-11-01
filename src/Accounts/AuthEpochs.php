<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
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
    /**
     * @param string $table where the epochs are kept
     * @param string $column the user's id in it
     */
    public function __construct(
        private Connection $connection,
        private string $table = 'auth_epochs',
        private string $column = 'user_id',
    ) {
        foreach ([$table, $column] as $name) {
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $name) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a table or column name the auth epochs can be kept under: lowercase letters, digits and underscores.");
            }
        }
    }

    /**
     * The table, its user column a foreign key to $userModel, so a user who
     * is deleted takes their epoch with them.
     *
     * @param class-string<Model> $userModel
     */
    public static function define(Schema $schema, string $userModel, string $table = 'auth_epochs', bool $ifNotExists = false): void
    {
        $schema->create($table, static function (Table $epochs) use ($userModel): void {
            $epochs->foreign($userModel, 'user_id')->primary()->cascadeOnDelete();
            $epochs->integer('epoch');
        }, $ifNotExists);
    }

    /** The user's epoch, as AuthContext takes it. */
    public function of(string $user): string
    {
        $epoch = $this->connection->select(
            "SELECT epoch FROM {$this->table} WHERE {$this->column} = ?",
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
        $sql = "INSERT INTO {$this->table} ({$this->column}, epoch) VALUES (?, 1) "
            . "ON CONFLICT ({$this->column}) DO UPDATE SET epoch = {$this->table}.epoch + 1";
        $connection = $this->connection;
        if ($connection->inTransaction()) {
            $connection->execute($sql, [$user]);

            return;
        }

        $connection->durableTransaction(static fn (): int => $connection->execute($sql, [$user]));
    }
}
