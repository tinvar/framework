<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Each user's credential epoch, in a table of its own: a counter that moves
 * when everything that speaks for the user must stop, and on nothing else
 * -- they sign out everywhere, their password is reset or changed, their
 * address changes, or they turn two-factor sign-in off. Their API tokens
 * are bound to it (ADR 0159), through AccountsKit::$tokenHolders.
 *
 * Not the auth epoch (AuthEpochs), which the kit moves at every sign-in and
 * sign-out as well, so a tab drops what it held for the session before:
 * bound to that, a token would end each time its user signed in on a phone.
 * Each of the moments above moves both, in the one durable transaction that
 * makes the change, so a token ends exactly when the sessions do.
 *
 * Keyed by user, as the auth epochs are; a user with no row is at epoch 0.
 */
final readonly class CredentialEpochs
{
    public const string TABLE = 'credential_epochs';

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

    /** The user's credential epoch now; '0' before it ever moved. */
    public function of(string $user): string
    {
        $epoch = $this->connection->select('SELECT epoch FROM ' . self::TABLE . ' WHERE user_id = ?', [$user])[0]['epoch'] ?? 0;

        return (string) (\is_int($epoch) || \is_string($epoch) ? $epoch : 0);
    }

    /**
     * Moves the user's credential epoch on, inside the caller's transaction
     * -- the one that ends their sessions or changes how they sign in -- so
     * the two are kept or lost together.
     */
    public function bump(string $user): void
    {
        if (!$this->connection->inTransaction()) {
            throw new \LogicException('A credential epoch moves inside the transaction that makes the change it is moved for.');
        }

        $this->connection->execute(
            'INSERT INTO ' . self::TABLE . ' (user_id, epoch) VALUES (?, 1) '
            . 'ON CONFLICT (user_id) DO UPDATE SET epoch = ' . self::TABLE . '.epoch + 1',
            [$user],
        );
    }
}
