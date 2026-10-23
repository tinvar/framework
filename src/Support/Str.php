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

    /**
     * The int a string spells, or null if it spells none: digits with an
     * optional minus sign and nothing else -- no padding, no plus, no
     * exponent -- and within PHP's int range.
     *
     * The range is the part a shape check misses. (int) clamps, so an
     * over-long number became PHP_INT_MAX or PHP_INT_MIN: a value nobody
     * sent, and one that clears any Min or Max on the side it lands on.
     * FILTER_VALIDATE_INT refuses it instead. It also refuses leading zeros,
     * which are stripped first, because '007' has always meant 7 here.
     */
    public static function wholeNumber(string $value): ?int
    {
        if (preg_match('/^-?\d+\z/', $value) !== 1) {
            return null;
        }

        $int = filter_var(preg_replace('/^(-?)0+(?=\d)/', '$1', $value), FILTER_VALIDATE_INT);

        return \is_int($int) ? $int : null;
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
