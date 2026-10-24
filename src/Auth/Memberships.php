<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The application's membership model: whether a user belongs to a panel in
 * a tenant, and as what.
 *
 * tinvar-spec.md, "Panels and scope": "Widening (a reseller's customers, all
 * tenants for staff) comes from the session's membership: its tenant kind
 * plus a panel permission", and "the tenant resolved from a host only
 * constrains: ... the user must hold a membership there."
 *
 * The framework asks; the application answers from whatever it keeps -- a
 * memberships table, a role column, a directory. Asked once per request into
 * a panel, after the panel's guard has said who the user is, with the tenant
 * the request resolved ('' for none). Null is no membership, and the request
 * is refused.
 */
interface Memberships
{
    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership;
}
