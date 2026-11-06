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
 * says nothing more than it already would. And changePasswordFrom() writes
 * only over the hash it is told is stored, which the kit relies on too.
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

    /**
     * Sets the account's password hash, whatever it was: a reset's or a
     * change's new password, in the transaction that ends its sessions.
     */
    public function changePassword(AccountUser $user, string $passwordHash): void;

    /**
     * Replaces the account's password hash with $passwordHash only where the
     * one stored is still $current, and answers whether it did: a
     * compare-and-set, made by one conditional write -- an UPDATE whose
     * WHERE names the account and $current, or the ORM's
     * `query()->where()->update()`, which locks the row it compares --
     * never a read and then a write, a save() among them, between which
     * another request's write lands and is overwritten. The query builder
     * writes no statement past a model's #[BeforeSave] or #[AfterSave]
     * methods (ADR 0073), so a model with them is given the UPDATE in SQL.
     *
     * Called inside the kit's transaction, which holds the account's
     * sessions to the new hash in the same commit: written through the
     * connection the kit was given.
     *
     * What a sign-in stores a rehash with (ADR 0168). It knows the password
     * and the hash it checked it against, and nothing of a hash stored since:
     * a reset's or a change's new password, which must stay, or another
     * sign-in's rehash of the same password, which the account's sessions
     * have just been held to.
     */
    public function changePasswordFrom(AccountUser $user, string $current, string $passwordHash): bool;

    /**
     * The account's password hash as its table holds it now; null for an
     * account that is gone. Read from the table itself -- never a replica, a
     * cache, or a model loaded earlier in the request, which an ORM's
     * identity map hands back as it was read: the kit asks once
     * changePasswordFrom() has found the hash changed since.
     */
    public function storedPasswordHash(AccountUser $user): ?string;

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
