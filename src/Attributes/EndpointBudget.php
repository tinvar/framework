<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Support\Str;

/**
 * A shared budget for a set of endpoints, in place of the one every other
 * endpoint spends (ADR 0187).
 *
 *     #[WithoutSession(reason: '...'), Domain('api.{root}'), EndpointBudget('api', perMinute: 1200)]
 *     final class Cells { ... }
 *
 * Every #[Get], #[Post] and #[Endpoint] route spends `throttle:endpoints`,
 * sized for a browser's fetches among its pages. An API whose callers are
 * scripts wants another number. Endpoints that declare a budget spend it
 * instead, with everything the shared one has: its flood guard at ten
 * times, its passes by address and then by account -- for a
 * #[WithoutSession] endpoint, after its own middleware signed the caller in
 * -- and its `X-RateLimit-*` headers. An endpoint's own #[Throttle] bucket
 * still applies inside it.
 *
 * Every endpoint naming one budget shares it, whichever class it is on, and
 * all must agree on its size, as a #[Throttle] bucket's must. On the class
 * -- or a class it extends, or a trait it uses -- it covers each endpoint
 * in the class; on the method it replaces the class's. Only endpoints read
 * it: an action holder carrying it is refused when it registers.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class EndpointBudget
{
    /** The prefix each budget is counted under, so none shares a counter with the framework's or a #[Throttle] bucket. */
    public const string PREFIX = 'endpoints.';

    public function __construct(
        public string $name,
        public int $perMinute,
    ) {
        if (!Str::isDottedIdentifier($name)) {
            throw new \InvalidArgumentException("'{$name}' is not an endpoint budget: dot-separated identifiers, as in api or partners.orders.");
        }
        if ($perMinute < 1) {
            throw new \InvalidArgumentException("The endpoint budget '{$name}' allows {$perMinute} a minute. Allow at least one, or take the endpoints away.");
        }
    }

    /** The name its hits are counted under in the throttle store. */
    public function counter(): string
    {
        return self::PREFIX . $this->name;
    }

    /**
     * The budget an endpoint spends: its method's, else its class's -- the
     * class's own, else the nearest a trait or an ancestor declares
     * (ClassAttributes) -- null for the shared one.
     */
    public static function declaredOn(string $class, string $method): ?self
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        $onClass = ClassAttributes::of($reflection, self::class);
        $attribute = ($reflection->hasMethod($method) ? $reflection->getMethod($method)->getAttributes(self::class)[0] ?? null : null)
            ?? ($onClass === [] ? null : $onClass[array_key_last($onClass)]);

        return $attribute?->newInstance();
    }
}
