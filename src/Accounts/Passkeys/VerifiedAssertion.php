<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * A sign-in WebAuthn verified: the counter to store in place of the old
 * one, and what the authenticator said of the person and the credential.
 */
final readonly class VerifiedAssertion
{
    public function __construct(
        public int $signCount,
        public bool $userVerified,
        public bool $backedUp,
    ) {}
}
