<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * An endpoint reached with no session: an API a client authenticates to
 * with a token of its own, which the endpoint's #[Middleware] reads.
 *
 * A session is what makes CSRF a risk -- a browser sends its cookie with a
 * request another site makes it send -- so an endpoint that runs with none
 * needs no CSRF token. The two go together and only together: the
 * application's global middleware, which starts the session and signs in
 * whoever it names, does not run for it, so no cookie authenticates a
 * request that has not proved itself the way the endpoint asks. Skipping
 * the token while the session still signed the visitor in would open the
 * hole the token exists to close.
 *
 * Authentication still applies, as it does to every endpoint: the
 * endpoint's own middleware signs someone in, or the request is a 401 --
 * unless it is #[PublicAccess] too. The reason is required, as
 * #[NoAuthorization]'s is, for whoever reviews the route.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class WithoutSession
{
    public function __construct(public string $reason)
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('#[WithoutSession] needs a reason: say how the endpoint authenticates its caller instead.');
        }
    }

    /**
     * Whether an endpoint -- its class, or its method -- declares one. Off
     * the class itself, never an ancestor, as #[PublicAccess] is read:
     * inheriting a way out of a control is the fail-open direction.
     */
    public static function declaredOn(string $class, string $method): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->getAttributes(self::class) !== []) {
            return true;
        }

        return $reflection->hasMethod($method) && $reflection->getMethod($method)->getAttributes(self::class) !== [];
    }
}
