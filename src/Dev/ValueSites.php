<?php

declare(strict_types=1);

namespace Tinvar\Dev;

use Tinvar\Data\Data;

/**
 * Where a loader made each field of the Data it returns: the line of the
 * argument that fills the field in a `new CatalogPageData(...)` the loader's
 * class writes -- the dev toolbar's "made at" badge beside the field's
 * declaration (ADR 0132).
 *
 * FieldOrigins says where a field is declared, which is the same for every
 * request. A value a loader worked out -- a count, a total, a list it
 * filtered -- is made somewhere else, and that line is what a developer
 * chasing a wrong number wants. Finding it at run time would mean tracing
 * every constructor call; reading the loader's own source finds it for the
 * way loaders are written, cheaply, in dev:
 *
 *   - every `new` in the loader's class whose class resolves -- through the
 *     file's namespace and its `use` imports -- to the Data returned, or to
 *     the Data of one of its fields (`customer: new CustomerData(...)`,
 *     under `customer.`);
 *   - each of its arguments: a named one is its field, a positional one is
 *     the constructor's parameter at that place, up to a spread;
 *   - the line the argument starts on.
 *
 * What it cannot see it does not guess: Data made in another class, by a
 * static constructor, from an array, or through a variable class name gets
 * no "made at", only its declaration. A field set in more than one place --
 * two branches each building the page -- lists each line, since which one
 * ran is not something the source says. Read from the source, never from a
 * value, as FieldOrigins is.
 */
final class ValueSites
{
    /** Sites kept for one field: past it, the branches are too many to be a pointer. */
    public const int MAX_SITES = 4;

    /** @var array<string, array{0: list<\PhpToken>, 1: string, 2: array<string, string>}|false> a file's tokens, namespace and imports */
    private static array $files = [];

    /**
     * The sites in $loader's class that fill each field of $data -- and of
     * the Data its fields are, under their names -- keyed by the field's
     * name as FieldOrigins gives it.
     *
     * @param class-string $loader
     * @param class-string $data
     * @param list<array{name: string, type: string, site: string, declaredIn: string}> $fields $data's, from FieldOrigins
     * @return array<string, list<string>>
     */
    public static function of(string $loader, string $data, array $fields): array
    {
        if (!class_exists($loader)) {
            return [];
        }
        $reflection = new \ReflectionClass($loader);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return [];
        }
        $parsed = self::$files[$file] ??= self::parse($file);
        if ($parsed === false) {
            return [];
        }
        [$tokens, $namespace, $imports] = $parsed;

        // Each Data class a construction may be of, and the prefixes its
        // fields go under: the returned Data at the top, each field that is
        // Data under its name.
        $prefixes = [strtolower($data) => ['']];
        foreach ($fields as $field) {
            $type = ltrim($field['type'], '?');
            if (class_exists($type) && is_subclass_of($type, Data::class)) {
                $prefixes[strtolower($type)][] = $field['name'] . '.';
            }
        }

        $sites = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->line < $start || $token->line > $end || $token->id !== T_NEW) {
                continue;
            }
            $name = self::next($tokens, $i);
            if ($name === null || !\in_array($tokens[$name]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $class = self::resolve($tokens[$name]->text, $namespace, $imports);
            $open = self::next($tokens, $name);
            if (!isset($prefixes[strtolower($class)]) || $open === null || $tokens[$open]->text !== '(') {
                continue;
            }
            foreach (self::arguments($tokens, $open, $class) as $field => $line) {
                foreach ($prefixes[strtolower($class)] as $prefix) {
                    $site = "{$file}:{$line}";
                    $known = $sites[$prefix . $field] ?? [];
                    if (!\in_array($site, $known, true) && \count($known) < self::MAX_SITES) {
                        $sites[$prefix . $field][] = $site;
                    }
                }
            }
        }

        return $sites;
    }

    /** Forgets the files read: once per request, as FieldOrigins does. */
    public static function forget(): void
    {
        self::$files = [];
    }

    /**
     * The field each argument of the call opening at $open fills, and the
     * line it starts on.
     *
     * @param list<\PhpToken> $tokens
     * @return array<string, int>
     */
    private static function arguments(array $tokens, int $open, string $class): array
    {
        $parameters = [];
        if (class_exists($class)) {
            $constructor = new \ReflectionClass($class)->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $parameters[] = $parameter->getName();
            }
        }

        $found = [];
        $position = 0;
        $positional = true;
        $depth = 0;
        $starting = true;
        $count = \count($tokens);
        for ($i = $open + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->isIgnorable()) {
                continue;
            }
            $text = $token->text;
            if ($depth === 0 && $text === ')') {
                break;
            }
            if ($depth === 0 && $text === ',') {
                $starting = true;
                $position++;
                continue;
            }
            if ($starting) {
                $starting = false;
                $after = self::next($tokens, $i);
                if ($token->id === T_ELLIPSIS) {
                    // A spread fills fields nothing here names.
                    $positional = false;
                } elseif ($after !== null && $tokens[$after]->text === ':' && preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/', $text) === 1) {
                    $found[$text] ??= $token->line;
                    $positional = false;
                } elseif ($positional && isset($parameters[$position])) {
                    $found[$parameters[$position]] ??= $token->line;
                }
            }
            if (\in_array($text, ['(', '[', '{'], true) || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif (\in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }
        }

        return $found;
    }

    /**
     * $file's tokens, its namespace and its class imports (alias => class),
     * or false where it cannot be read.
     *
     * @return array{0: list<\PhpToken>, 1: string, 2: array<string, string>}|false
     */
    private static function parse(string $file): array|false
    {
        $code = @file_get_contents($file);
        if (!\is_string($code)) {
            return false;
        }
        $tokens = array_values(\PhpToken::tokenize($code));

        $namespace = '';
        $imports = [];
        $depth = 0;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->id === T_NAMESPACE && $depth === 0) {
                $name = self::next($tokens, $i);
                if ($name !== null && \in_array($tokens[$name]->id, [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $tokens[$name]->text;
                }
            } elseif ($token->id === T_USE && $depth === 0) {
                $i = self::import($tokens, $i, $imports);
            }
        }

        return [$tokens, $namespace, $imports];
    }

    /**
     * Reads one `use` statement's class imports into $imports -- a plain
     * one, an aliased one, a group -- and returns where it ends. `use
     * function` and `use const` import no class.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     */
    private static function import(array $tokens, int $at, array &$imports): int
    {
        $count = \count($tokens);
        $first = self::next($tokens, $at);
        if ($first !== null && \in_array($tokens[$first]->id, [T_FUNCTION, T_CONST], true)) {
            for ($i = $first; $i < $count && $tokens[$i]->text !== ';'; $i++);

            return $i;
        }

        // Each name and its alias, as read; a group's names under its prefix.
        $prefix = '';
        $entries = [['', null]];
        $aliasing = false;
        for ($i = $at + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->isIgnorable()) {
                continue;
            }
            $last = \count($entries) - 1;
            if ($token->text === ';') {
                break;
            }
            if ($token->text === ',' || $token->text === '}') {
                $entries[] = ['', null];
                $aliasing = false;
            } elseif ($token->text === '{') {
                $prefix = rtrim($entries[$last][0], '\\') . '\\';
                $entries[$last] = ['', null];
            } elseif ($token->id === T_AS) {
                $aliasing = true;
            } elseif ($aliasing) {
                $entries[$last][1] = $token->text;
                $aliasing = false;
            } else {
                $entries[$last][0] .= $token->text;
            }
        }

        foreach ($entries as [$name, $alias]) {
            if ($name !== '') {
                $class = ltrim($prefix . $name, '\\');
                $imports[strtolower($alias ?? substr($class, (int) strrpos('\\' . $class, '\\')))] = $class;
            }
        }

        return $i;
    }

    /**
     * A class name as PHP resolves it where it is written.
     *
     * @param array<string, string> $imports
     */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($imports[$first])) {
            $rest = substr($name, \strlen($first));

            return $imports[$first] . $rest;
        }

        return $namespace === '' ? $name : "{$namespace}\\{$name}";
    }

    /**
     * The index of the next token after $i that is not whitespace or a
     * comment, or null at the end.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function next(array $tokens, int $i): ?int
    {
        $count = \count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }
}
