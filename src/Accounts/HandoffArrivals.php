<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Identity\Arrival;
use Tinvar\Identity\HandoffFactors;
use Tinvar\Identity\HandoffSessions;

/**
 * The kit's sessions, as a handoff arrives into one (ADR 0039): the user a
 * handoff brought to another of the app's hosts -- staff, to the admin host
 * -- signed in there under a new session id, bound to that host
 * (Sessions::arrive()), and ended when the code that brought them is shown
 * again (Sessions::revokeArrival()). StartSession, around the handoff
 * endpoint, sets the new cookie on its redirect.
 *
 *     handoff: new HandoffEndpoint(..., users: $accounts->lookup, sessions: $accounts->arrivals, ...),
 *
 * The session keeps the realm and not the arrival's tenant: on the host it
 * arrived at, each request runs as the tenant that host resolves to, and an
 * arrival into a tenant below it is the app's to keep.
 *
 * An arrival whose user's auth epoch moved after the endpoint checked the
 * code's -- a sign-out everywhere, a new password, a sign-in elsewhere,
 * landing in between -- is refused as the endpoint refuses a code, and
 * makes no session (ADR 0165).
 *
 * The session it makes was signed in with what the one the handoff came
 * from proved (ADR 0184): the code carries that session's factors, under its
 * MAC, and no more -- a password's session on the platform host arrives as
 * a password's, a passkey's as a passkey's.
 */
final readonly class HandoffArrivals implements HandoffSessions, HandoffFactors
{
    public function __construct(private Sessions $sessions) {}

    public function arrive(Request $request, Arrival $arrival): string
    {
        if (!$arrival->user instanceof AccountUser) {
            throw new \LogicException('A handoff arrives as one of the app\'s accounts; its user lookup found ' . get_debug_type($arrival->user) . '.');
        }

        return $this->sessions->arrive(SessionState::of($request), $arrival->user, $arrival->realm->host, $arrival->epoch, $arrival->redemption, SessionFactors::decode($arrival->factors))
            ?? throw new HttpException(403, "The handoff code is refused: its user's auth epoch moved while it was being redeemed.", 'handoff_refused');
    }

    public function factorsOf(Request $request, Authenticatable $user): string
    {
        $state = $request->attribute(SessionState::ATTRIBUTE);

        return $state instanceof SessionState && $state->user()?->authIdentifier() === $user->authIdentifier()
            ? $state->factors()->encode()
            : '';
    }

    public function revoke(string $redemption): void
    {
        $this->sessions->revokeArrival($redemption);
    }
}
