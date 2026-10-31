<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Support\Str;

/**
 * A budget of its own for an endpoint, beside the one every endpoint spends.
 *
 *     #[Post('/api/scanner/racks/{rack}'), Throttle('scanner', perMinute: 30)]
 *
 * `throttle:endpoints` is one bucket for every #[Get], #[Post] and
 * #[Endpoint] route. One that is costlier than the rest -- an export, a
 * search, a write a partner's script calls in a loop -- names a bucket of
 * its own, and is held to both: the global bucket still applies, so naming
 * a generous bucket never buys anything past it. Keyed as every throttle
 * is: by the caller's address, and by their account once they are signed
 * in (Tinvar\Http\Throttle), never by anything the request says about
 * itself.
 *
 * Endpoints naming one bucket share it, and must agree on its size. On the
 * class -- or a class it extends, or a trait it uses -- it covers each
 * endpoint in the class; on the method it replaces the class's. Only
 * endpoints read it (ADR 0074): an action holder carrying it, on itself or
 * by inheritance, is refused when it registers, not ignored.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Throttle
{
    /** The prefix every endpoint bucket is counted under, so none shares a counter with the framework's. */
    public const string PREFIX = 'endpoint.';

    public function __construct(
        public string $bucket,
        public int $perMinute,
    ) {
        if (!Str::isDottedIdentifier($bucket)) {
            throw new \InvalidArgumentException("'{$bucket}' is not a throttle bucket: dot-separated identifiers, as in scanner or orders.export.");
        }
        if ($perMinute < 1) {
            throw new \InvalidArgumentException("The throttle bucket '{$bucket}' allows {$perMinute} a minute. Allow at least one, or take the endpoint away.");
        }
    }

    /** The name its hits are counted under in the throttle store. */
    public function counter(): string
    {
        return self::PREFIX . $this->bucket;
    }

    /**
     * The bucket an endpoint is held to: its method's, else its class's --
     * the class's own, else the nearest a trait or an ancestor declares
     * (ClassAttributes) -- null for none. A limit adds something, so it is
     * inherited as a check is: PHP drops a parent's and a trait's class
     * attributes, and a bucket on a base class limited nothing.
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
