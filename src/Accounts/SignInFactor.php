<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * One thing a sign-in proved (SessionFactors, ADR 0184). The first three
 * sign a session in: a password, an emailed link -- a sign-in link, a
 * sign-up's or a reset's, which prove the inbox -- or a passkey, whose
 * ceremony the kit always asks to verify its user, so it is something held
 * and something known or been at once. The last two are second factors: a
 * code from the account's authenticator app, or one of its recovery codes.
 *
 * In the order a session's factors are written in, which is never read
 * back as meaning anything.
 */
enum SignInFactor: string
{
    case Password = 'password';
    case EmailLink = 'email-link';
    case Passkey = 'passkey';
    case AuthenticatorCode = 'authenticator-code';
    case RecoveryCode = 'recovery-code';

    /** Whether this factor is one a sign-in waits on after its first: a code. */
    public function isSecond(): bool
    {
        return $this === self::AuthenticatorCode || $this === self::RecoveryCode;
    }
}
