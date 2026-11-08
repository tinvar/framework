<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * What an application gives its ApiAccess to take tenant API tokens as well
 * as its users' (ADR 0193): where they are kept, and how a tenant is known
 * to exist still -- a token whose tenant is gone is refused, so the answer
 * is required, never assumed.
 *
 *     apiAccess: new ApiAccess(new DatabaseApiTokens($connection), $tokenHolders,
 *         tenantTokens: new TenantTokenAccess(new DatabaseTenantApiTokens($connection), $tenants->has(...))),
 *
 * Given it, the Kernel lets every operation and endpoint marked
 * #[AcceptsTenantTokens] take one, and puts TenantApiTokens -- which
 * issues, lists, revokes and ends them -- in the container. Without it, a
 * `tvt_` token is refused everywhere as one that does not hold.
 */
final readonly class TenantTokenAccess
{
    /**
     * @param \Closure(string): bool $tenants whether a tenant, by id, exists now
     * @param int $maxPerTenant the most tokens one tenant may hold at once in a panel,
     *        revoked, ended and expired ones apart
     * @param int|null $maxLifetimeDays the longest a token may last, in days, so an expiry is
     *        required; null lets a member choose one that never expires
     */
    public function __construct(
        public TenantApiTokenStore $store,
        public \Closure $tenants,
        public int $maxPerTenant = TenantApiTokens::MAX_PER_TENANT,
        public ?int $maxLifetimeDays = ApiTokens::MAX_LIFETIME_DAYS,
    ) {
        if ($maxPerTenant < 1) {
            throw new \InvalidArgumentException("TenantTokenAccess lets a tenant hold {$maxPerTenant} tokens. Allow at least one, or give ApiAccess no TenantTokenAccess.");
        }
        if ($maxLifetimeDays !== null && $maxLifetimeDays < 1) {
            throw new \InvalidArgumentException("TenantTokenAccess lets a token last {$maxLifetimeDays} days. Allow at least one, or null for any lifetime.");
        }
    }
}
