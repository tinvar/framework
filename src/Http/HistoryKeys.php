<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Support\Signer;

/**
 * The key a session's client encrypts its history with: a MAC of the
 * session's id, tenant and panel, under a key of its own.
 *
 * tinvar-spec.md, "Security": "History encryption: on by default for
 * authenticated routes, including remembered form state, using
 * `crypto.subtle` and a session-scoped key." What the router keeps in the
 * tab's storage -- what useRemember() and useForm() remember -- is sealed
 * with it, AES-256-GCM, by a key the browser imports as one it cannot
 * export. See ADR 0019.
 *
 * Derived, not stored, as CsrfTokens are: the server needs no state to issue
 * one, a reload is handed the same key again for as long as the session
 * lasts, and a session gets a new key exactly when it gets a new id -- on
 * sign-in and sign-out -- or moves to another tenant or panel, the same three
 * things the session binding of a route token names. No script can work one
 * out: it takes the application key, and it is handed only to a request
 * carrying the session's cookie. After a sign-out, or under another session,
 * what was sealed before cannot be opened by anything the server will hand
 * over.
 */
final readonly class HistoryKeys
{
    /** The Signer purpose; a history key is never any other MAC this key makes. */
    public const string PURPOSE = 'history-key';

    /**
     * The response header a client navigation's payload carries its page's
     * key in. A document carries it in its page metadata instead, since a
     * document load cannot read headers.
     */
    public const string HEADER = 'x-history-key';

    /**
     * @param Signer $signer built for PURPOSE from the application key
     * @param \Closure(Request): string $sessionId the session the request
     *        belongs to, or '' without one -- the same resolver the CSRF,
     *        fragment and route tokens are bound with
     */
    public function __construct(
        private Signer $signer,
        private \Closure $sessionId,
    ) {}

    /**
     * The key for the request's session in a tenant and panel -- 32 bytes,
     * base64url -- or null when it has no session: one key for every
     * session-less visitor would be a key anyone has.
     */
    public function for(Request $request, string $tenant = '', string $panel = ''): ?string
    {
        $session = ($this->sessionId)($request);
        if ($session === '') {
            return null;
        }

        // Each part with its length, so no two triples ever read as the same
        // string, whatever they contain.
        return Signer::encode($this->signer->sign(\sprintf(
            'history:%d:%s|%d:%s|%d:%s',
            \strlen($session),
            $session,
            \strlen($tenant),
            $tenant,
            \strlen($panel),
            $panel,
        )));
    }
}
