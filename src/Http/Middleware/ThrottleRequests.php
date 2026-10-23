<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;

/**
 * `throttle:<name>` from the endpoints table, one half at a time.
 *
 * The halves cannot run in the same place, which is the thing that was
 * wrong before. Charging the ACCOUNT cannot be outermost, because the auth
 * context is populated by middleware -- a throttle ahead of it always sees
 * an anonymous caller, so the per-user limit silently never applies. The
 * Kernel charges both after CSRF, so a request a hostile page forged spends
 * neither, and puts a silent flood guard outermost so a caller hammering an
 * endpoint is still refused before it costs a session lookup.
 *
 * Counts the hit BEFORE calling the next middleware, so a request that is
 * over the limit costs a counter increment and nothing else -- no model
 * binding, no authorization, no render. Counting afterwards would mean the
 * work an attacker is trying to cause has already happened by the time they
 * are refused.
 *
 * A refused request is 429 with Retry-After, and an allowed one still carries
 * the remaining count: a client that can see what it has left can slow down
 * on its own, while one that only finds out by being refused cannot.
 */
final class ThrottleRequests implements Middleware
{
    /**
     * @param bool $byAddress true for the outermost pass, which charges the
     *        caller's address and needs nothing to have run first; false for
     *        the pass that charges their ACCOUNT, which must run after the
     *        session middleware has populated the auth context
     * @param bool $advertise whether an allowed response carries the
     *        X-RateLimit headers. Off for an outer guard, whose generous
     *        numbers would otherwise overwrite the budget a client should
     *        pace itself by.
     */
    public function __construct(
        private readonly Throttle $throttle,
        private readonly string $name,
        private readonly int $limit,
        private readonly int $window = 60,
        private readonly bool $byAddress = true,
        private readonly bool $advertise = true,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $result = $this->byAddress
            ? $this->throttle->hitAddress($this->name, $request, $this->limit, $this->window)
            : $this->throttle->hitUser($this->name, $this->limit, $this->window);

        // No user to charge. The address pass outside this one already
        // counted, so an anonymous caller is bounded either way.
        if ($result === null) {
            return $next($request);
        }

        if (!$result->allowed) {
            // The headers go WITH the refusal. They were computed correctly
            // and then dropped, because HttpException had nowhere to carry
            // them -- so retryAfter() was dead code on the only path that
            // would ever have used it.
            throw new HttpException(
                429,
                "Too many {$this->name} requests.",
                'too_many_requests',
                $result->headers(),
            );
        }

        $response = $next($request);
        if (!$this->advertise) {
            return $response;
        }

        // A pass inside this one -- the account's, inside the address's --
        // has already said what is left, and the caller should pace itself
        // by whichever budget runs out first. Overwriting it told a customer
        // "3 left" one request before their account's budget refused them.
        $advertised = $response->header('x-ratelimit-remaining');
        if ($advertised !== null && (int) $advertised <= $result->remaining) {
            return $response;
        }

        foreach ($result->headers() as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }
}
