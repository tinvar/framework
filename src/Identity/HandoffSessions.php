<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Http\Request;

/**
 * The application's sessions, as a handoff arrives into one.
 *
 * Sessions are the application's (the framework has no session layer), so
 * the last step of a handoff is too. What it owes:
 *
 * - **A new session id, always.** Whatever session the browser arrived
 *   with -- one an attacker planted, one signed in as somebody else -- is
 *   replaced, never promoted. The endpoint holds the application to it: the
 *   id returned must be non-empty and not the one the request carried, or
 *   the handoff fails rather than complete as a fixation.
 * - **The session belongs to the realm.** It is the arrival realm's, and is
 *   refused on any other host: "a session created on it can only hold
 *   memberships within that domain's tenant subtree". A browser never sends
 *   a `__Host-` cookie elsewhere, so this costs nothing but a replay -- a
 *   session lifted from a domain a tenant controls, presented to the
 *   platform host.
 * - **Set on the response.** The cookie for the new session goes on the
 *   redirect the endpoint answers with -- through the application's own
 *   global middleware, which runs around the endpoint.
 * - **Ended when its code comes back.** A code is redeemed once; one shown
 *   again was copied -- from a log, a history, a referrer -- and whoever
 *   holds the copy may have been the first to redeem it. So the session the
 *   first redemption started is ended (revoke()), as RFC 6749, 4.1.2 asks
 *   of an authorization code used twice. The application keeps each
 *   arrival's redemption with the session it starts, to find it by.
 */
interface HandoffSessions
{
    /**
     * Starts a session for $arrival->user in $arrival->realm, in place of
     * the one $request carries.
     *
     * @return string the new session's id
     */
    public function arrive(Request $request, Arrival $arrival): string;

    /**
     * Ends the session the arrival with this redemption
     * (Arrival::$redemption) started, wherever it is now, if it is still
     * there; nothing if it is not. Durable: a revocation a power cut took
     * back is a session that works again.
     */
    public function revoke(string $redemption): void;
}
