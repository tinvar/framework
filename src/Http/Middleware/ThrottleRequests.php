<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;

/**
 * `throttle:<name>` from the endpoints table.
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
    public function __construct(
        private readonly Throttle $throttle,
        private readonly string $name,
        private readonly int $limit,
        private readonly int $window = 60,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $result = $this->throttle->hit($this->name, $request, $this->limit, $this->window);

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
