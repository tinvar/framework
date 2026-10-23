<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Auth\AuthContext;

/**
 * Rate limiting, as `throttle:<name>` on the endpoints table.
 *
 * tinvar-spec.md, "Resource limits": "Rate limits on actions, queries and
 * fragments, keyed by user, or by IP when signed out."
 *
 * That keying rule is the whole design. A limit keyed only on IP punishes
 * everyone behind one NAT for one account's behaviour, and lets an attacker
 * with a pool of addresses past it. A limit keyed only on the user does
 * nothing for the signed-out requests -- login, password reset, and every
 * public fragment -- which are the ones worth limiting most. So: the user
 * when there is one, the address when there is not, and the two namespaces
 * never collide, because a user id and an address are not interchangeable
 * identifiers and treating them as one would let a caller choose which bucket
 * to spend.
 *
 * Fixed windows rather than a token bucket or a sliding log. A window is one
 * counter and one expiry per key, which is what a shared store can do
 * cheaply and atomically; the cost is that a caller can spend two windows'
 * worth across a boundary, which for "stop hammering this endpoint" is an
 * acceptable shape and for anything finer is the wrong tool anyway.
 */
final class Throttle
{
    /** @param \Closure(Request): string $addressOf how to identify a signed-out caller */
    public function __construct(
        private readonly ThrottleStore $store,
        private readonly AuthContext $auth,
        private readonly \Closure $addressOf,
    ) {}

    /**
     * Records a hit and says whether it is over the limit.
     *
     * @param string $name   the bucket, e.g. 'actions'
     * @param int    $limit  hits allowed per window
     * @param int    $window seconds
     */
    public function hit(string $name, Request $request, int $limit, int $window): ThrottleResult
    {
        $key = $this->keyFor($name, $request);
        [$used, $resetsAt] = $this->store->increment($key, $window);

        return new ThrottleResult(
            allowed: $used <= $limit,
            limit: $limit,
            remaining: max(0, $limit - $used),
            resetsAt: $resetsAt,
        );
    }

    /**
     * The bucket this caller spends from.
     *
     * Namespaced by who, so a user id can never be spelled to look like an
     * address or the reverse.
     */
    private function keyFor(string $name, Request $request): string
    {
        $user = $this->auth->id();

        return $user === null
            ? "{$name}|ip|" . ($this->addressOf)($request)
            : "{$name}|user|{$user}";
    }
}
