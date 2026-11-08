<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Lets a tenant's API token call an operation or an endpoint marked
 * #[RequiresScope], as well as a user's (ADR 0193).
 *
 *     #[Query(name: 'orders.recent'), Authorize('viewAny', Order::class),
 *       RequiresScope(ApiScope::OrdersRead), AcceptsTenantTokens]
 *     final class RecentOrders { ... }
 *
 * A tenant's token (`tvt_...`) is the tenant's own, not anyone's: its call
 * runs as a TenantTokenActor, which no policy written for people expects.
 * So nothing takes one unless it says so here, and the Kernel refuses to be
 * built while a check on what says so would be decided by a policy ability
 * -- or a before() -- whose first parameter does not name TenantTokenActor:
 * the policy says what a token may do, as it says what a person may.
 *
 *   - On an #[Action] method or a #[Query] class, `POST /_api/{name}`
 *     takes a tenant's token holding the scopes. On an action holder's
 *     class it is refused, as #[RequiresScope] is there.
 *   - On a #[Get], #[Post] or #[Endpoint] method, or its class, the
 *     endpoint does.
 *
 * Without #[RequiresScope] it is refused: nothing would be exposed. It is
 * read off the class itself and the method, never an ancestor: inheriting a
 * way in is the fail-open direction. The scopes a tenant's token may be
 * issued with are those of what accepts one.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class AcceptsTenantTokens
{
    /**
     * Whether an entry point -- its class, or its method -- declares one,
     * off the class itself and never an ancestor. $method null reads the
     * class alone, as a query is declared.
     */
    public static function declaredOn(string $class, ?string $method = null): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->getAttributes(self::class) !== []) {
            return true;
        }

        return $method !== null && $reflection->hasMethod($method) && $reflection->getMethod($method)->getAttributes(self::class) !== [];
    }
}
