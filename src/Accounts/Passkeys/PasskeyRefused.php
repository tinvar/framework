<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * A passkey ceremony the server would not accept: a registration or a
 * sign-in whose answer from the browser failed one of WebAuthn's checks.
 *
 * The reason is a short code for the log and for tests -- `challenge`,
 * `origin`, `rp_id`, `user_verification`, `signature`, `counter`,
 * `format`, `malformed`, `algorithm` -- and the message is one a visitor
 * may be shown: it says what to do, never which check failed in a way that
 * helps someone probing the server. Every refusal leaves nothing changed.
 */
final class PasskeyRefused extends \RuntimeException
{
    /** What most refusals say: nothing about which check failed. */
    public const string TRY_AGAIN = 'The passkey could not be checked. Try again, or use another way to sign in.';

    public function __construct(public readonly string $reason, string $message = self::TRY_AGAIN)
    {
        parent::__construct($message);
    }

    /** The browser's answer was not shaped as WebAuthn's: refused before anything in it is trusted. */
    public static function malformed(): self
    {
        return new self('malformed', 'The passkey answer was incomplete or damaged. Try again; if it keeps failing, use another way to sign in.');
    }
}
