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
        return iterator_to_array($this->confirmed($user, $panel, $root), false);
    }

    /**
     * The first tenant tenants() would list, or null for none -- asking
     * Memberships no further than it: a handoff lands in one tenant, and
     * confirming every other one listed was a lookup each for nothing.
     */
    public function first(Authenticatable $user, string $panel, ?string $root): ?string
    {
        foreach ($this->confirmed($user, $panel, $root) as $tenant) {
            return $tenant;
        }

        return null;
    }

    /**
     * The tenants tenants() lists, each confirmed only as it is reached.
     *
     * @return \Generator<int, string>
     */
    private function confirmed(Authenticatable $user, string $panel, ?string $root): \Generator
    {
        if (!$this->memberships instanceof TenantMemberships) {
            return;
        }

        $seen = [];
        foreach ($this->memberships->tenantsOf($user, $panel) as $tenant) {
            if ($tenant === '' || isset($seen[$tenant])) {
                continue;
            }
            $seen[$tenant] = true;
            if (($root === null || $this->within($root, $tenant)) && $this->holds($user, $panel, $tenant)) {
                yield $tenant;
            }
        }
    }
}
