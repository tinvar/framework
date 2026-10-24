<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Attributes\Rescue;

/**
 * The fields of a Data class that cross the boundary.
 *
 * One definition, used by both the encoder and codegen. If these disagreed, the
 * generated TypeScript would describe a payload the encoder does not produce,
 * which is the one failure mode end-to-end types exist to prevent.
 */
final class DataShape
{
    /** @var array<class-string, list<\ReflectionProperty>> */
    private static array $cache = [];

    /** @var array<class-string, array<string, true>> */
    private static array $rescued = [];

    /**
     * Public, non-static properties in declaration order.
     *
     * Private state is not payload, which keeps the boundary narrow by
     * construction. Order matters: it is the order of the payload rows and of
     * the generated interface.
     *
     * @param class-string $class
     * @return list<\ReflectionProperty>
     */
    public static function fieldsOf(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $fields = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $fields[] = $property;
            }
        }

        return self::$cache[$class] = $fields;
    }

    /**
     * The fields marked #[Rescue], by name.
     *
     * Here for the same reason fieldsOf() is: the encoder decides which
     * fields may be sent as a Rescued marker, and codegen decides which
     * types say so. Read in two places, they could disagree, and a field
     * typed `T` would arrive as a marker.
     *
     * @param class-string $class
     * @return array<string, true>
     */
    public static function rescued(string $class): array
    {
        if (isset(self::$rescued[$class])) {
            return self::$rescued[$class];
        }

        $rescued = [];
        foreach (self::fieldsOf($class) as $property) {
            if ($property->getAttributes(Rescue::class) !== []) {
                $rescued[$property->getName()] = true;
            }
        }

        return self::$rescued[$class] = $rescued;
    }
}
