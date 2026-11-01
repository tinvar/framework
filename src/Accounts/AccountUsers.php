<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * The application's users, as the accounts kit reads and changes them: the
 * contract that lets an application bring its own users table.
 *
 * The skeleton's is App\Auth\Account\Users, over its User model. Every
 * address handed in is already normalized (Emails::normalize()), and the
 * application's table keeps one account per address -- a unique index the
 * kit relies on: create() and changeEmail() throw EmailTaken when the
 * address is another account's, which the kit turns into an answer that
 * says nothing more than it already would.
 */
interface AccountUsers
{
    public function find(string $id): ?AccountUser;

    public function findByEmail(string $email): ?AccountUser;

    /**
     * A new account, its address verified -- the kit makes one only from a
     * link that reached that address.
     *
     * @throws EmailTaken
     */
    public function create(string $email, string $name, string $passwordHash): AccountUser;

    public function changePassword(AccountUser $user, string $passwordHash): void;

    /** @throws EmailTaken */
    public function changeEmail(AccountUser $user, string $email): void;

    /**
     * Up to $limit stored password hashes, any of them: what Passwords
     * samples to cost its decoy as the accounts cost.
     *
     * @param int<1, max> $limit
     * @return list<string>
     */
    public function passwordHashes(int $limit): array;
}
