<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Auth\UserLookup;

/**
 * What an application gives its Kernel to take API tokens (ADR 0159):
 * where tokens are kept, and how a token's user is found again with the
 * auth epoch they are on now.
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
     */
    public function __construct(
        public ApiTokenStore $store,
        public UserLookup $users,
        public int $maxPerUser = ApiTokens::MAX_PER_USER,
        public ?\Closure $clock = null,
    ) {
        if ($maxPerUser < 1) {
            throw new \InvalidArgumentException("ApiAccess lets a user hold {$maxPerUser} tokens. Allow at least one, or give the Kernel no ApiAccess.");
        }
    }
}
