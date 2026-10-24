<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The tenants a user holds memberships in: what a tenant switcher lists,
 * and where a handoff to a domain lands a member of a tenant below the
 * domain's own.
 *
 * Memberships answers for one tenant at a time; this lists them. The
 * framework confirms each tenant listed against Memberships, asked as that
 * tenant, before it acts on one -- so a listing that names a tenant the
 * user is not a member of is dropped, not trusted. An application keeping
 * memberships in a table implements both over it.
 */
interface TenantMemberships
{
    /**
     * Every tenant $user holds a membership of $panel in, in the order a
     * switcher lists them.
     *
     * @return list<string>
     */
    public function tenantsOf(Authenticatable $user, string $panel): array;
}
