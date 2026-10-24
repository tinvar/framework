<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\Request;

/**
 * The application's sessions, as one switches tenant.
 *
 * Sessions are the application's (the framework has no session layer), and
 * the tenant a session runs as is what its global middleware hands
 * Orm::actAs(); so the last step of a switch is the application's. What it
 * owes, as HandoffSessions owes on arrival:
 *
 * - **A new session id, always.** Every token a page holds is bound to the
 *   session: the CSRF token, route and fragment tokens, history keys, live
 *   grants, upload tickets, file URLs. Most name the tenant too, and are
 *   refused in another; the CSRF token and a file URL do not. A tab left
 *   open on the old tenant would otherwise post its forms into the new
 *   one -- a write landing in a tenant nobody chose for it. A new id ends
 *   every one of them at once. TenantSwitcher holds the application to it:
 *   the id returned must be non-empty and not the one the request carried.
 * - **The same realm.** The new session belongs to the host the old one
 *   did, and is refused on any other.
 * - **Set on the response**, through the application's own global
 *   middleware, as a new session is on sign-in.
 *
 * The user's auth epoch is left alone: it is the user's across every
 * session they hold, and a switch in one browser has no business purging
 * another's.
 */
interface TenantSessions
{
    /**
     * Starts a session for $user running as $tenant, in place of the one
     * $request carries.
     *
     * @return string the new session's id
     */
    public function switchTo(Request $request, Authenticatable $user, string $tenant): string;
}
