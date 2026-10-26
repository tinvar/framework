<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Tinvar\Support\Str;

/**
 * What a generator accepts as a name, a route or a view, and what it derives
 * from them.
 *
 * Everything typed after `make:` becomes a file path, a class name or text
 * inside generated source, so each is held to a shape with nothing left to
 * escape: a class path is capitalised identifiers, which can hold no `.`,
 * no `/` of its own and no quote; a view is what Str::isViewPath() allows,
 * which the route registry holds views to as well. Nothing is cleaned up
 * and used anyway -- `../Evil` is refused, not turned into `Evil`.
 */
final class Names
{
    /** The deepest a class path goes: Admin/Racks/Units/Show. */
    private const int MAX_SEGMENTS = 4;

    /** The longest one name may be: anything longer is a typo, or not a name. */
    private const int MAX_LENGTH = 64;

    /**
     * Words no generated name may be, in any case: PHP's reserved words,
     * which no class can be named (`final class List` does not parse), and
     * TypeScript's, which codegen refuses as a query or action name -- so a
     * generator refuses them before writing a class it would then refuse.
     */
    private const array RESERVED = [
        // PHP: keywords, and the names reserved for types.
        '__halt_compiler', 'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit',
        'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
        'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'int', 'interface', 'isset',
        'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'numeric', 'object', 'or',
        'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'resource',
        'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var',
        'void', 'while', 'xor', 'yield',
        // TypeScript, as Tinvar\Codegen\TypeScriptGenerator refuses them.
        'arguments', 'await', 'debugger', 'delete', 'export', 'import', 'in', 'let', 'package', 'super', 'this',
        'typeof', 'with',
    ];

    /**
     * A class path as typed -- `Racks/Show`, or `Racks\Show` -- as its
     * segments, each a class name: a capital letter, then letters and digits.
     *
     * @return non-empty-list<string>
     */
    public static function classPath(string $value, string $what, string $example): array
    {
        $segments = preg_split('#[/\\\\]#', $value) ?: [];
        $bad = $segments === [] || \count($segments) > self::MAX_SEGMENTS;
        foreach ($segments as $segment) {
            $bad = $bad || preg_match('/^[A-Z][A-Za-z0-9]*\z/', $segment) !== 1
                || \strlen($segment) > self::MAX_LENGTH || self::isReserved($segment);
        }

        if ($bad || $segments === []) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a {$what}: up to " . self::MAX_SEGMENTS . " class names joined by '/', each a capital "
                . "letter then letters and digits, and none a reserved word, as in {$example}.",
            );
        }

        return $segments;
    }

    /** A method name: lower camel case, as in moveUnit. */
    public static function method(string $value): string
    {
        if (preg_match('/^[a-z][A-Za-z0-9]*\z/', $value) !== 1 || \strlen($value) > self::MAX_LENGTH || self::isReserved($value)) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a method name: a lower-case letter then letters and digits, and not a reserved word, "
                . 'as in moveUnit.',
            );
        }

        return $value;
    }

    /**
     * A route template's parameters, in order, once the template is one the
     * route registry would compile: `/`, or `/`-separated segments, each a
     * literal or a `{name}`, with no empty, `.` or `..` segment.
     *
     * The template is written into the #[Page] attribute and never becomes
     * a path on disk, but it is held to this anyway: what it may contain
     * cannot close the quotes it is written between.
     *
     * @return list<string>
     */
    public static function routeParameters(string $path): array
    {
        $usage = "--path is a route template: '/' and segments, each a literal or a {parameter}, as in /racks/{rack}.";
        if ($path === '/') {
            return [];
        }
        if (!str_starts_with($path, '/') || str_ends_with($path, '/') || \strlen($path) > 255) {
            throw new \InvalidArgumentException("'{$path}' is not a route. {$usage}");
        }

        $parameters = [];
        foreach (explode('/', substr($path, 1)) as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}\z/', $segment, $m) === 1) {
                // $this is the one name PHP will not take for a parameter.
                if ($m[1] === 'this' || \in_array($m[1], $parameters, true)) {
                    throw new \InvalidArgumentException("'{$path}' names {{$m[1]}} " . ($m[1] === 'this' ? 'which PHP cannot bind.' : 'twice.') . " {$usage}");
                }
                $parameters[] = $m[1];
                continue;
            }
            if ($segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9._~-]+\z/', $segment) !== 1) {
                throw new \InvalidArgumentException("'{$path}' has a segment '{$segment}' that is neither a literal nor a {parameter}. {$usage}");
            }
        }

        return $parameters;
    }

    /**
     * A view: segments of letters, digits, `_` and `-` joined by `/`, as the
     * route registry holds a #[Page]'s view to. It names a file under the
     * pages directory, and that shape is why it cannot name one anywhere
     * else: no `..`, no leading `/`, no `\`.
     */
    public static function view(string $value): string
    {
        if (!Str::isViewPath($value) || substr_count($value, '/') >= self::MAX_SEGMENTS || \strlen($value) > 128) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a view: up to " . self::MAX_SEGMENTS . " names of letters, digits, '_' and '-' joined "
                . "by '/', as in racks/show.",
            );
        }

        return $value;
    }

    /** A table name the schema builder will take (Tinvar\Orm\Schema\Identifier), unqualified. */
    public static function table(string $value): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $value) !== 1) {
            throw new \InvalidArgumentException("'{$value}' is not a table name: letters, digits and '_', as in racks.");
        }

        return $value;
    }

    /** A property name for a column: lower camel case, whose snake_case form is a column name. */
    public static function property(string $value): string
    {
        if (preg_match('/^[a-z][A-Za-z0-9]*\z/', $value) !== 1 || \strlen(Str::snake($value)) > 63 || $value === 'this') {
            throw new \InvalidArgumentException("'{$value}' is not a column's property name: lower camel case, as in heightU.");
        }

        return $value;
    }

    /**
     * A class's short name. Str::shortName() takes a class that exists; a
     * generator names classes before they do.
     */
    public static function short(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }

    /**
     * The wire name the query registry will give a query class: Str::queryName()'s
     * rule, applied before the class exists. App\Queries\Racks\Stats is racks.stats.
     */
    public static function queryName(string $class): string
    {
        $parts = explode('\\', $class);
        $at = array_search('Queries', $parts, true);

        return implode('.', array_map(lcfirst(...), $at === false ? [end($parts)] : \array_slice($parts, $at + 1)));
    }

    public static function isReserved(string $word): bool
    {
        return \in_array(strtolower($word), self::RESERVED, true);
    }

    /** `SignIn` as a view segment: `sign-in`. */
    public static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }

    /** A view segment as an identifier: `sign-in` and `sign_in` are `signIn`. */
    public static function camel(string $segment): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $segment))));
    }

    /**
     * An English plural, for a table named after its model: racks,
     * addresses, categories, order_lines. A rule, not a dictionary, so
     * --table names one it gets wrong.
     */
    public static function plural(string $word): string
    {
        return match (true) {
            preg_match('/[^aeiou]y\z/', $word) === 1 => substr($word, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)\z/', $word) === 1 => "{$word}es",
            default => "{$word}s",
        };
    }
}
