<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Routing\HostPattern;
use Tinvar\Routing\HostPatternRefused;

/**
 * The host an entry point is served on (ADR 0149).
 *
 *   #[Domain('admin.{root}')]          one label in front of the configured domain
 *   #[Domain('{account}.{root}')]      any one, bound to the `account` parameter
 *   #[Domain('status.example.org')]    an exact host, another apex altogether
 *
 * On a page, a layout, an endpoint, an action or a query; see HostPattern
 * for what a pattern may say. A route that declares none is served on the
 * application's primary host (Domains), and on no other: the same path can
 * be a different page on each host. A layout wraps only the pages on its
 * own host. An action or a query that declares none is answered on any
 * host the application serves, unless its panel is held to one.
 *
 * Read off the class, its ancestors and traits, then the method -- the
 * nearest declaration wins, so a base class or trait can put a whole
 * section on a host and one page can say otherwise.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Domain
{
    public HostPattern $host;

    public function __construct(public string $pattern)
    {
        try {
            $this->host = HostPattern::compile($pattern);
        } catch (HostPatternRefused $e) {
            throw new \InvalidArgumentException("#[Domain]: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * The pattern an entry point is held to -- its method's, else its own
     * class's, else the nearest ancestor's or trait's -- or null for none.
     *
     * @param class-string $class
     * @throws \InvalidArgumentException for a pattern HostPattern refuses
     */
    public static function declaredOn(string $class, ?string $method = null): ?HostPattern
    {
        $reflection = new \ReflectionClass($class);
        if ($method !== null && $reflection->hasMethod($method)) {
            $own = $reflection->getMethod($method)->getAttributes(self::class);
            if ($own !== []) {
                return $own[\count($own) - 1]->newInstance()->host;
            }
        }

        $attributes = ClassAttributes::of($reflection, self::class);

        return $attributes === [] ? null : $attributes[\count($attributes) - 1]->newInstance()->host;
    }
}
