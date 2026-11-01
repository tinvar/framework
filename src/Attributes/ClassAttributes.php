<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Class-level declarations, read the way an author reads a class hierarchy.
 *
 * PHP does not inherit class attributes: ReflectionClass::getAttributes() sees
 * only what the class itself carries. A page or action holder extending
 *
 *   #[Authorize('admin', AdminArea::class), Middleware(AdminGuard::class)]
 *   abstract class AdminActions {}
 *
 * registered with no check and no middleware, and the build rules -- reading
 * the same reflection -- reported nothing. "Everything extending this is
 * admin-only" failed open, on exactly the subclasses nobody re-reads.
 *
 * Ancestors come first, so a base class's checks run before a subclass's, the
 * way a class's own run before its method's. A trait's class-level
 * declarations count as the using class's, ahead of the class's own: a trait
 * is copied into the class that uses it, and PHP drops its class attributes
 * the same way it drops a parent's.
 *
 * Only for declarations that ADD something -- a check, a middleware. An
 * opt-out such as #[PublicAccess] is read off the class itself: inheriting a
 * way out of a control is the fail-open direction.
 */
final class ClassAttributes
{
    /**
     * @template T of object
     * @param class-string|\ReflectionClass<object> $class
     * @param class-string<T>                      $attribute
     * @param int                                  $flags \ReflectionAttribute::IS_INSTANCEOF to take
     *                                                    every attribute that is an $attribute, an interface's included
     * @return list<\ReflectionAttribute<T>>
     */
    public static function of(string|\ReflectionClass $class, string $attribute, int $flags = 0): array
    {
        $reflection = $class instanceof \ReflectionClass ? $class : new \ReflectionClass($class);

        /** @var list<\ReflectionClass<object>> $lineage outermost ancestor first */
        $lineage = [];
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            array_unshift($lineage, $current);
        }

        $seen = [];
        $out = [];
        foreach ($lineage as $level) {
            foreach (self::traitsOf($level, $seen) as $trait) {
                $out = [...$out, ...$trait->getAttributes($attribute, $flags)];
            }
            $out = [...$out, ...$level->getAttributes($attribute, $flags)];
        }

        return $out;
    }

    /**
     * The traits a class uses, a trait's own traits before it, each once
     * across the whole hierarchy -- a trait used by a parent and again by a
     * child would otherwise run its checks twice.
     *
     * @param \ReflectionClass<object> $class
     * @param array<string, true>      $seen
     * @return list<\ReflectionClass<object>>
     */
    private static function traitsOf(\ReflectionClass $class, array &$seen): array
    {
        $out = [];
        foreach ($class->getTraits() as $name => $trait) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out = [...$out, ...self::traitsOf($trait, $seen), $trait];
        }

        return $out;
    }
}
