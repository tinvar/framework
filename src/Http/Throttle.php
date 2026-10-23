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
    /** @param \Closure(Request): string $addressOf how to identify the caller's origin */
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
        // BOTH buckets when there is a user, not one or the other.
        //
        // Choosing a bucket by whether a credential was sent let the caller
        // choose which budget to spend: send none and spend the address's,
        // send one and spend that account's, and with N accounts from one
        // address get N times the limit -- the same "pool of addresses"
        // evasion the keying rule argues against, with accounts substituted
        // for addresses. Counting both keeps the stated benefit (one
        // account's behaviour cannot exhaust a NAT's budget, because the
        // per-user limit bites first) and restores a ceiling on what one
        // address can do.
        $user = $this->auth->id();
        $results = [$this->count("{$name}|ip|" . $this->addressFor($request), $limit, $window)];

        if ($user !== null) {
            $results[] = $this->count("{$name}|user|{$user}", $limit, $window);
        }

        // The strictest answer wins, and the caller is told about whichever
        // budget is closest to running out.
        usort($results, static fn (ThrottleResult $a, ThrottleResult $b): int
            => [$a->allowed, $a->remaining] <=> [$b->allowed, $b->remaining]);

        return $results[0];
    }

    private function count(string $key, int $limit, int $window): ThrottleResult
    {
        [$used, $resetsAt] = $this->store->increment($key, $window);

        return new ThrottleResult(
            allowed: $used <= $limit,
            limit: $limit,
            remaining: max(0, $limit - $used),
            resetsAt: $resetsAt,
        );
    }

    /**
     * The address to charge, never an empty one.
     *
     * An empty address collapses every signed-out caller into one bucket, so
     * one attacker exhausts the anonymous budget for the whole site -- a
     * cheaper denial of service than the flood the throttle exists to stop.
     * The framework already refuses an empty session id in two places for
     * the same reason: one constant means everyone shares one binding.
     *
     * An unidentifiable caller gets its own bucket rather than everyone's.
     * That means it is effectively unlimited, which is the honest failure:
     * the alternative is limiting every anonymous visitor together, and a
     * misconfigured proxy would then look exactly like an attack.
     *
     * A note for whoever writes the closure: do not trust X-Forwarded-For
     * unless the proxy count is pinned. An attacker-chosen address is an
     * infinite supply of buckets.
     */
    private function addressFor(Request $request): string
    {
        $address = ($this->addressOf)($request);

        return $address === '' ? 'unidentified:' . bin2hex(random_bytes(8)) : $address;
    }
}
