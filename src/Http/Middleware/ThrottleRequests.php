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
 * The two halves cannot run in the same place, which is the thing that was
 * wrong before. Charging the ADDRESS wants to be outermost, so a caller
 * hammering an endpoint does not cost a session lookup on the way to being
 * refused. Charging the ACCOUNT cannot be outermost, because the auth
 * context is populated by middleware -- a throttle ahead of it always sees
 * an anonymous caller, so the per-user limit silently never applies.
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
     */
    public function __construct(
        private readonly Throttle $throttle,
        private readonly string $name,
        private readonly int $limit,
        private readonly int $window = 60,
        private readonly bool $byAddress = true,
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

        foreach ($result->headers() as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }
}
