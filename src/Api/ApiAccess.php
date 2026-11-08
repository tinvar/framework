<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * What an application gives its Kernel to take API tokens (ADR 0159):
 * where tokens are kept, and how a token's user is found again with the
 * epoch a token is held to and the auth epoch its request runs on -- a
 * TokenHolderLookup, never a plain UserLookup, which says only one epoch
 * and not which. Handed one, the Kernel is not built: the type is checked
 * here, so an app cannot take tokens on a lookup that never said.
 *
 *     apiAccess: new ApiAccess(new DatabaseApiTokens($connection), $customers),
 *
 * Given it, the Kernel mounts `POST /_api/{operation}` for every action and
 * query marked #[RequiresScope], lets every endpoint marked so take a
 * token, and puts ApiTokens -- which issues, lists and revokes them -- in
 * the container. Without it, a #[RequiresScope] anywhere is refused when
 * the Kernel is built, rather than an API that answers nobody.
 */
final readonly class ApiAccess
{
    /**
     * @param int $maxPerUser the most tokens one user may hold at once in a tenant and panel,
     *        revoked and expired ones apart
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param int|null $maxLifetimeDays the longest a token may last, in days, so an expiry is
     *        required; null lets a user choose one that never expires
     * @param TenantTokenAccess|null $tenantTokens where tokens a tenant owns are kept, and how a
     *        tenant is known to exist (ADR 0193); none, and a tenant's token is taken nowhere
     */
    public function __construct(
        public ApiTokenStore $store,
        public TokenHolderLookup $users,
        public int $maxPerUser = ApiTokens::MAX_PER_USER,
        public ?\Closure $clock = null,
        public ?int $maxLifetimeDays = ApiTokens::MAX_LIFETIME_DAYS,
        public ?TenantTokenAccess $tenantTokens = null,
    ) {
        if ($maxPerUser < 1) {
            throw new \InvalidArgumentException("ApiAccess lets a user hold {$maxPerUser} tokens. Allow at least one, or give the Kernel no ApiAccess.");
        }
        if ($maxLifetimeDays !== null && $maxLifetimeDays < 1) {
            throw new \InvalidArgumentException("ApiAccess lets a token last {$maxLifetimeDays} days. Allow at least one, or null for any lifetime.");
        }
    }
}
