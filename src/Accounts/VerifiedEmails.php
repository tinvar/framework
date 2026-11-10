<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * An account that reads more than one address: what an AccountUser model
 * implements when the application keeps further addresses for it, each
 * proved by a link that reached it (ADR 0201).
 *
 * Whatever is listed here is trusted as the account's own: the invites sent
 * to any of them are offered to it, and accepted as it, with no emailed
 * link. List an address only once it is proved, and never one the person
 * merely typed. An account that implements none of this reads one address,
 * accountEmail(), which the accounts kit takes only from a link that
 * reached it.
 */
interface VerifiedEmails
{
    /** @return list<string> every address the account is proved to read, accountEmail() among them */
    public function verifiedEmails(): array;
}
