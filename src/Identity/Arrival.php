<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;

/**
 * A handoff that has proven itself: who arrives, at the auth epoch the code
 * was minted on (still theirs), in which realm, where they were going, and
 * the tenant the new session runs as. What the application starts a new
 * session from (HandoffSessions).
 *
 * The tenant is the realm's, when the user holds a membership of its panel
 * there; otherwise the first tenant below it where they do, in the order
 * the application lists them (TenantMemberships) -- a reseller's customer
 * arriving on the reseller's domain arrives as the customer. Never one
 * outside the realm's subtree, and never empty for a realm in a panel.
 *
 * The redemption names the code that brought them, hashed: the
 * application keeps it with the session it starts, so a replay of that
 * code can end the session (HandoffSessions::revoke()).
 */
final readonly class Arrival
{
    public function __construct(
        public Authenticatable $user,
        public string $epoch,
        public Realm $realm,
        public string $returnTo,
        public string $tenant = '',
        public string $redemption = '',
    ) {}

    /** The handle a code's redemption is known by: its id, hashed, so no store holds the id itself. */
    public static function redemptionOf(HandoffCode $code): string
    {
        return hash('sha256', 'handoff-redemption:' . $code->id);
    }
}
