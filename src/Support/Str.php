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
            (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value),
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

    /**
     * An ASCII identifier: what a TypeScript property or function name can
     * be written as without quoting or escaping.
     *
     * Names from attributes reach generated TypeScript, so they are checked
     * when they are registered rather than trusted to be well-formed by the
     * time codegen writes them into source.
     */
    public static function isIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $value) === 1;
    }

    /** Identifiers joined by dots: `racks.show`, `RackActions.moveUnit`. */
    public static function isDottedIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/', $value) === 1;
    }

    /** A view path: segments of letters, digits, `_` and `-`, joined by `/`. */
    public static function isViewPath(string $value): bool
    {
        return preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z#', $value) === 1;
    }
}
