<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Support\Signer;

/**
 * The CSRF token for a session: a MAC of its id, under a key of its own.
 *
 * tinvar-spec.md, "HTTP": "Requests from JavaScript use the CSRF cookie and
 * header." The cookie and the header carrying the same value prove only
 * that the request came from something able to read the cookie. Bound to
 * nothing, any value that matches itself passes -- an empty cookie and an
 * empty header did, and so does a pair an attacker planted, whenever a
 * cookie can be set from outside this origin. A token that is this
 * session's MAC cannot be made up without the key, and a token lifted from
 * one session is refused in every other.
 *
 * Derived, not stored: the server needs no state to issue or check one, and
 * a session gets a new token exactly when it gets a new id -- on rotation.
 */
final readonly class CsrfTokens
{
    /** The Signer purpose; a CSRF token never verifies as anything else. */
    public const string PURPOSE = 'csrf-token';

    /**
     * @param Signer $signer built for PURPOSE from the application key
     * @param \Closure(Request): string $sessionId the session the request
     *        belongs to, or '' without one -- the same resolver fragment and
     *        route tokens are bound with
     */
    public function __construct(
        private Signer $signer,
        private \Closure $sessionId,
    ) {}

    /**
     * The token for the request's session, or null when it has none: one
     * token for every session-less visitor would be a token anyone has.
     */
    public function for(Request $request): ?string
    {
        $session = ($this->sessionId)($request);

        return $session === '' ? null : Signer::encode($this->signer->sign('csrf:' . $session));
    }

    /**
     * Whether a token is this request's session's.
     *
     * hash_equals, not ===: a timing difference here leaks the token one
     * byte at a time.
     */
    public function verify(Request $request, #[\SensitiveParameter] string $token): bool
    {
        $expected = $this->for($request);

        return $expected !== null && hash_equals($expected, $token);
    }
}
