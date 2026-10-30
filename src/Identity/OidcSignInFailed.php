<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * A sign-in through an OpenID provider that did not complete: the person
 * cancelled or the provider refused (`denied`), the answer came back to
 * another session, late, twice or for another provider (`state`), the code
 * was not exchanged (`token`), or the ID token was not believed (`id_token`,
 * with the IdTokenRefused as its previous).
 *
 * Every one is the same answer to the browser -- sign in again -- and the
 * reason is for the log alone, as a handoff's refusals are.
 */
final class OidcSignInFailed extends \RuntimeException
{
    public const string DENIED = 'denied';
    public const string STATE = 'state';
    public const string TOKEN = 'token';
    public const string ID_TOKEN = 'id_token';
    public const string PROVIDER = 'provider';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
