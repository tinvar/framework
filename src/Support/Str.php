<?php

declare(strict_types=1);

namespace Tinvar\Support;

final class Str
{
    /** @var array<string, string> */
    private static array $snakeCache = [];

    public static function snake(string $value): string
    {
        return self::$snakeCache[$value] ??= strtolower(
            (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value)
        );
    }

    /** @param class-string $class */
    public static function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Wire name for a #[Query] class: the short name, lower-camel, prefixed by
     * the segment under the Queries namespace. App\Queries\Racks\UnitLoad
     * becomes "racks.unitLoad".
     *
     * @param class-string $class
     */
    public static function queryName(string $class): string
    {
        $parts = explode('\\', $class);
        $at = array_search('Queries', $parts, true);
        $tail = $at === false ? [array_pop($parts)] : \array_slice($parts, $at + 1);

        $tail = array_map(static fn (string $p): string => lcfirst($p), $tail);

        return implode('.', $tail);
    }
}
