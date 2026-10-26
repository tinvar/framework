<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Orm\Orm;

/**
 * A user's memberships of a panel at or below one tenant: what a tenant
 * switcher offers, and what a handoff into a tenant's domain admits.
 *
 * tinvar-spec.md, "Identity across domains": "A session created on it can
 * only hold memberships within that domain's tenant subtree, and the tenant
 * switcher is limited to that subtree." Shared by TenantSwitcher and
 * HandoffEndpoint, so the two cannot come to different answers about who
 * belongs where.
 *
 * Every answer is the application's Memberships, asked as the tenant in
 * question -- as the panel's own membership check asks it -- and a tenant
 * is in a subtree by the Orm's checked tree (TenantBounds), walked up from
 * the tenant. With no hierarchy, a tenant's subtree is the tenant.
 */
final readonly class SubtreeMemberships
{
    public function __construct(
        private Orm $orm,
        private Memberships $memberships,
    ) {}

    /** Whether $user holds a membership of $panel in $tenant. */
    public function holds(Authenticatable $user, string $panel, string $tenant): bool
    {
        $memberships = $this->memberships;
        $ask = static fn (): ?Membership => $memberships->membership($user, $panel, $tenant);

        return ($tenant === '' ? $ask() : $this->orm->actAs($tenant, $ask)) !== null;
    }

    /** Whether $tenant is $root or below it. */
    public function within(string $root, string $tenant): bool
    {
        if ($root === '' || $tenant === '') {
            return false;
        }

        return $root === $tenant || $this->orm->tenants()?->contains($root, $tenant) === true;
    }

    /** Whether the application can list a user's tenants, which a switcher needs. */
    public function lists(): bool
    {
        return $this->memberships instanceof TenantMemberships;
    }

    /**
     * The tenants $user holds a membership of $panel in, at or below $root
     * -- anywhere, for null -- in the application's order. Each one listed
     * is confirmed by Memberships before it is returned.
     *
     * @return list<string>
     */
    public function tenants(Authenticatable $user, string $panel, ?string $root): array
    {
        if (!$this->memberships instanceof TenantMemberships) {
            return [];
        }

        $tenants = [];
        foreach ($this->memberships->tenantsOf($user, $panel) as $tenant) {
            if ($tenant === '' || isset($tenants[$tenant])) {
                continue;
            }
            if (($root === null || $this->within($root, $tenant)) && $this->holds($user, $panel, $tenant)) {
                $tenants[$tenant] = true;
            }
        }

        return array_map('strval', array_keys($tenants));
    }
}
