<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Support\Signer;

/**
 * PKCE (RFC 7636, S256) for a handoff, with the verifier kept by no one.
 *
 * The target host that starts a handoff commits to the browser asking: the
 * challenge travels to the platform host and back inside the code, and only
 * a request carrying the same session can produce the verifier it hashes.
 * A code lifted from a URL, a log or a Referer -- or one an attacker minted
 * for their own account and planted in a victim's browser -- does not
 * redeem anywhere but the session that asked for it.
 *
 * The verifier is derived, not stored: a MAC of the target session's id and
 * the handoff's state, under the handoff's key, as CSRF tokens are derived
 * from the session. No table, and nothing to sweep; no script can compute
 * one, since it takes the application key; and a session that rotates can
 * no longer redeem what it started, which is the point of rotating.
 */
final class Pkce
{
    /** The verifier the session $sessionId has for $state: 43 characters, base64url. */
    public static function verifier(Signer $signer, #[\SensitiveParameter] string $sessionId, string $state): string
    {
        // Each part with its length, so no two pairs read as the same string.
        return Signer::encode($signer->sign(\sprintf('pkce:%d:%s|%d:%s', \strlen($sessionId), $sessionId, \strlen($state), $state)));
    }

    /** S256: BASE64URL(SHA256(verifier)), as RFC 7636 spells it. */
    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return Signer::encode(hash('sha256', $verifier, binary: true));
    }

    /** Whether the session $sessionId is the one that made $challenge for $state. */
    public static function matches(Signer $signer, #[\SensitiveParameter] string $sessionId, string $state, string $challenge): bool
    {
        return $sessionId !== '' && hash_equals(self::challenge(self::verifier($signer, $sessionId, $state)), $challenge);
    }
}
