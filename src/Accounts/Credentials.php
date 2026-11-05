<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * What a sign-in was checked against: the account's credential epoch, read
 * before its credential -- a link, a code, a passkey, a handoff -- was
 * checked, and the password hash it had then, the one a password was
 * checked against. The session the sign-in makes is held to both
 * (Sessions): a revocation, which moves the one, or a new password, which
 * replaces the other, ends it at its next request, however the two requests
 * met -- even made just after the revocation deleted every row it saw.
 *
 * Read with Sessions::credentials(), and handed to the sign-in after the
 * check.
 */
final readonly class Credentials
{
    public function __construct(
        public string $user,
        public string $epoch,
        #[\SensitiveParameter]
        public string $passwordHash,
    ) {}
}
