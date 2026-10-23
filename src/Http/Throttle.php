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
    /**
     * The bucket for a caller the application could not identify.
     *
     * Shared on purpose; see addressFor(). It is a reserved name rather than
     * a plausible address so it cannot collide with a real one.
     */
    public const string UNIDENTIFIED = "\0unidentified";


    /** @param \Closure(Request): string $addressOf how to identify the caller's origin */
    public function __construct(
        private readonly ThrottleStore $store,
        private readonly AuthContext $auth,
        private readonly \Closure $addressOf,
    ) {}

    /**
     * Records a hit against every bucket that applies.
     *
     * @param string $name   the bucket, e.g. 'actions'
     * @param int    $limit  hits allowed per window
     * @param int    $window seconds
     */
    public function hit(string $name, Request $request, int $limit, int $window): ThrottleResult
    {
        $results = [$this->hitAddress($name, $request, $limit, $window)];

        $user = $this->auth->id();
        if ($user !== null) {
            $results[] = $this->hitUser($name, $limit, $window);
        }

        return self::strictest($results);
    }

    /**
     * The address half, which needs nothing to have run first.
     *
     * Split out so it can go OUTERMOST, before session and tenant
     * resolution: a caller hammering an endpoint should not cost a session
     * lookup on the way to being refused.
     */
    public function hitAddress(string $name, Request $request, int $limit, int $window): ThrottleResult
    {
        return $this->count("{$name}|ip|" . $this->addressFor($request), $limit, $window);
    }

    /**
     * The per-account half, which needs the session to have been resolved.
     *
     * Separate because the two cannot run in the same place. Counting both
     * outermost looked right and silently did nothing: the auth context is
     * populated by middleware, so a throttle ahead of it always saw an
     * anonymous caller and the per-user limit never applied at all.
     */
    public function hitUser(string $name, int $limit, int $window): ?ThrottleResult
    {
        $user = $this->auth->id();

        return $user === null ? null : $this->count("{$name}|user|{$user}", $limit, $window);
    }

    /**
     * The strictest of several answers.
     *
     * The caller is told about whichever budget is closest to running out,
     * and refused if any is spent.
     *
     * @param list<ThrottleResult|null> $results
     */
    public static function strictest(array $results): ThrottleResult
    {
        $present = array_values(array_filter($results));
        if ($present === []) {
            throw new \InvalidArgumentException('Nothing was counted, so there is no answer to give.');
        }

        usort($present, static fn (ThrottleResult $a, ThrottleResult $b): int
            => [$a->allowed, $a->remaining] <=> [$b->allowed, $b->remaining]);

        return $present[0];
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
     * The address to charge when the application could not name one.
     *
     * There is a real tension here and it is worth stating rather than
     * picking quietly. One shared bucket means a single attacker can exhaust
     * the anonymous budget for every unidentifiable caller -- a cheaper
     * denial of service than the flood being prevented. A unique key per
     * request means no limit at all.
     *
     * A rate limit is a security control, and a security control that cannot
     * identify its subject fails CLOSED. So: one shared bucket, which is
     * deliberately conspicuous -- an application in this state will see the
     * refusals immediately, which is the correct amount of noise for a
     * misconfigured proxy. A per-request random key would have hidden it
     * forever.
     *
     * A note for whoever writes the closure: do not trust X-Forwarded-For
     * unless the proxy count is pinned. An attacker-chosen address is an
     * infinite supply of buckets, which is the same fail-open by another
     * route.
     */
    private function addressFor(Request $request): string
    {
        $address = ($this->addressOf)($request);
        if ($address === '') {
            return self::UNIDENTIFIED;
        }

        // One IPv6 subscriber is handed a whole /64, so counting per address
        // gave each of them 2^64 budgets. The prefix is the unit a network
        // actually assigns.
        $packed = @inet_pton($address);
        if ($packed !== false && \strlen($packed) === 16 && !str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return bin2hex(substr($packed, 0, 8)) . '::/64';
        }

        return $address;
    }
}
