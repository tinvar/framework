<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * Every class or namespace one PHP file names, resolved the way PHP resolves
 * it, for the layer rule (Layers).
 *
 * Read from the file's tokens, never by loading it, so a file that names a
 * class it cannot autoload is still read whole. Four places count:
 *
 *   use        each import at the top of the file, grouped ones included,
 *              and `use function` and `use const`.
 *   code       every qualified name: `\Tinvar\Http\Request`, `Http\Request`
 *              through an imported alias or relative to the namespace, and
 *              `namespace\Request` -- a new, a static call, an instanceof, a
 *              type, an attribute or a `::class` alike. An unqualified name
 *              is an import, counted where it is made, or a class in the
 *              file's own namespace: counted when it is one of $classes,
 *              since a namespace can be shared between layers, and
 *              `ClassAttributes::of()` written in Attributes\Table reaches
 *              the framework with no import at all.
 *   string     a class name in a string literal, as class_exists() or a
 *              container key takes one: always absolute, so only those
 *              starting `Tinvar\`. A namespace written with its trailing
 *              `\`, as a prefix names are compared with, names no class
 *              and loads nothing, so it is not one.
 *   comment    a qualified name in a comment or docblock. A docblock type is
 *              as much a reference as one in code -- PHPStan resolves it --
 *              and a mention in prose of a class from a layer above says the
 *              code knows what calls it. Resolved as code is, and also by
 *              the convention these comments write in, the `Tinvar\` left
 *              off: `Cache\TagNamespace` is that namespace when Cache is one
 *              of $namespaces and neither an import nor below the file's own.
 *
 * Not resolved: a name built at runtime, as a string concatenated or read
 * from config. A layer that needed one would be hiding a dependency, which
 * is for review to catch.
 */
final readonly class SourceReferences
{
    /**
     * @param string      $namespace the file's namespace; '' for none
     * @param string|null $declared  the first class, interface, trait or enum it declares
     * @param list<array{name: string, line: int, kind: 'use'|'code'|'string'|'comment'}> $references
     *        every name it uses, the line it is on, and where
     */
    private function __construct(
        public string $namespace,
        public ?string $declared,
        public array $references,
    ) {}

    /**
     * @param list<string> $namespaces every namespace there is, `Tinvar\Cache`
     *        and `Tinvar\Orm\Schema` alike: what a comment's shortened name is
     *        read against
     * @param list<string> $classes every class there is, by its full name:
     *        what an unqualified name in the file's own namespace is read
     *        against
     */
    public static function of(string $source, array $namespaces = [], array $classes = []): self
    {
        $known = [];
        foreach ($namespaces as $namespace) {
            $known[strtolower(trim($namespace, '\\'))] = true;
        }
        $siblings = array_fill_keys(array_map(static fn (string $class): string => ltrim($class, '\\'), $classes), true);

        $tokens = \PhpToken::tokenize($source);
        $count = \count($tokens);
        $namespace = '';
        $declared = null;
        // Lowercased, as PHP compares them: alias => the name it imports.
        $imports = [];
        $references = [];
        $depth = 0;
        // Where imports are made: the top of the file, or the top of a
        // braced `namespace X { ... }` block.
        $importDepth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '{' || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($token->is(\T_NAMESPACE)) {
                $next = self::next($tokens, $i);
                $namespace = '';
                $imports = [];
                if ($next !== null && $tokens[$next]->is([\T_NAME_QUALIFIED, \T_STRING])) {
                    $namespace = $tokens[$next]->text;
                    $i = $next;
                    $next = self::next($tokens, $i);
                }
                $importDepth = $next !== null && $tokens[$next]->text === '{' ? $depth + 1 : $depth;
            } elseif ($token->is(\T_USE) && $depth === $importDepth && $tokens[self::next($tokens, $i) ?? $i]->text !== '(') {
                // An import. Inside a class body `use` takes a trait, whose
                // name is counted below as any other name in code; after a
                // closure's parameters it takes variables.
                [$i, $imported] = self::imports($tokens, $i);
                foreach ($imported as $import) {
                    $references[] = ['name' => $import['name'], 'line' => $import['line'], 'kind' => 'use'];
                    if ($import['classes']) {
                        $imports[strtolower($import['alias'])] = $import['name'];
                    }
                }
            } elseif ($token->is(\T_NAME_FULLY_QUALIFIED)) {
                $references[] = ['name' => ltrim($token->text, '\\'), 'line' => $token->line, 'kind' => 'code'];
            } elseif ($token->is(\T_NAME_QUALIFIED)) {
                $references[] = ['name' => self::resolve($token->text, $namespace, $imports), 'line' => $token->line, 'kind' => 'code'];
            } elseif ($token->is(\T_NAME_RELATIVE)) {
                $relative = substr($token->text, \strlen('namespace\\'));
                $references[] = ['name' => ltrim($namespace . '\\' . $relative, '\\'), 'line' => $token->line, 'kind' => 'code'];
            } elseif ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && $declared === null) {
                // A declaration has its name next; `::class` and an
                // anonymous `new class` do not.
                $next = self::next($tokens, $i);
                if ($next !== null && $tokens[$next]->is(\T_STRING)) {
                    $declared = ltrim($namespace . '\\' . $tokens[$next]->text, '\\');
                }
            } elseif ($token->is(\T_STRING) && self::namesClass($tokens, $i)) {
                $sibling = ltrim($namespace . '\\' . $token->text, '\\');
                if (isset($siblings[$sibling]) && !isset($imports[strtolower($token->text)])) {
                    $references[] = ['name' => $sibling, 'line' => $token->line, 'kind' => 'code'];
                }
            } elseif ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])) {
                foreach (self::named($token->text, '/(?<![A-Za-z0-9_\\\\])\\\\?Tinvar(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+(?![A-Za-z0-9_\\\\])/') as [$name, $offset]) {
                    $references[] = ['name' => ltrim($name, '\\'), 'line' => $token->line + $offset, 'kind' => 'string'];
                }
            } elseif ($token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                foreach (self::named($token->text, '/(?<![A-Za-z0-9_\\\\$])\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/') as [$name, $offset]) {
                    $resolved = self::prose($name, $namespace, $imports, $known);
                    if ($resolved !== null) {
                        $references[] = ['name' => $resolved, 'line' => $token->line + $offset, 'kind' => 'comment'];
                    }
                }
            }
        }

        return new self($namespace, $declared, $references);
    }

    /**
     * Reads the `use` statement at $at: where it ends, and each name it
     * imports, with the alias it is known by and whether it is a class's.
     *
     * @param array<int, \PhpToken> $tokens
     * @return array{int, list<array{name: string, alias: string, classes: bool, line: int}>}
     */
    private static function imports(array $tokens, int $at): array
    {
        // `use function` and `use const` import no class: what they name
        // still counts, and no class name resolves through them. In a group
        // each name can say so for itself: `use A\{B, function c}`.
        $statementImportsClasses = true;
        $classes = true;
        $prefix = '';
        $name = null;
        $alias = null;
        $line = $tokens[$at]->line;
        $imported = [];
        $count = \count($tokens);

        for ($i = $at + 1; $i <= $count; $i++) {
            $token = $tokens[$i] ?? null;
            if ($token !== null && $token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            $ends = $token === null || $token->text === ';';
            if ($ends || $token->text === ',' || $token->text === '}') {
                if ($name !== null) {
                    $full = ltrim($prefix . $name, '\\');
                    $short = substr($full, (int) strrpos('\\' . $full, '\\'));
                    $imported[] = ['name' => $full, 'alias' => $alias ?? $short, 'classes' => $classes, 'line' => $line];
                }
                if ($ends) {
                    return [$i, $imported];
                }
                $name = null;
                $alias = null;
                $classes = $statementImportsClasses;
                if ($token->text === '}') {
                    $prefix = '';
                }
            } elseif ($token->is([\T_FUNCTION, \T_CONST])) {
                $classes = false;
                if ($prefix === '') {
                    $statementImportsClasses = false;
                }
            } elseif ($token->is(\T_AS)) {
                $next = self::next($tokens, $i);
                if ($next !== null) {
                    $alias = $tokens[$next]->text;
                    $i = $next;
                }
            } elseif ($token->is(\T_NS_SEPARATOR) && $name !== null) {
                // A group: `use Tinvar\Attributes\{Table, Column};`.
                $prefix = $name . '\\';
                $name = null;
            } elseif ($token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_STRING])) {
                $name = $token->text;
                $line = $token->line;
            }
        }

        return [$count, $imported];
    }

    /** @param array<string, string> $imports */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        $first = strstr($name, '\\', true);
        $first = $first === false ? $name : $first;
        $imported = $imports[strtolower($first)] ?? null;

        if ($imported !== null) {
            return $imported . substr($name, \strlen($first));
        }

        return ltrim($namespace . '\\' . $name, '\\');
    }

    /**
     * A qualified name in prose: absolute with `\` or `Tinvar\`, through an
     * import, below the file's namespace, or -- the convention -- under
     * `Tinvar\`. Null for anything else: `App\Models\User` in an example.
     *
     * @param array<string, string> $imports
     * @param array<string, true>   $known
     */
    private static function prose(string $name, string $namespace, array $imports, array $known): ?string
    {
        if (str_starts_with($name, '\\') || str_starts_with($name, 'Tinvar\\')) {
            return ltrim($name, '\\');
        }

        $first = (string) strstr($name, '\\', true);
        if (isset($imports[strtolower($first)])) {
            return self::resolve($name, $namespace, $imports);
        }

        $below = ltrim($namespace . '\\' . $first, '\\');
        if (isset($known[strtolower($below)])) {
            return $below . substr($name, \strlen($first));
        }

        if (isset($known[strtolower('Tinvar\\' . $first)])) {
            return 'Tinvar\\' . $name;
        }

        return null;
    }

    /**
     * Each match of $pattern in $text, with a doubled backslash read as one,
     * and the line it is on relative to the text's first.
     *
     * @return list<array{string, int}>
     */
    private static function named(string $text, string $pattern): array
    {
        $found = [];
        foreach (explode("\n", $text) as $offset => $line) {
            if (preg_match_all($pattern, str_replace('\\\\', '\\', $line), $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $found[] = [$match, $offset];
                }
            }
        }

        return $found;
    }

    /**
     * Whether the bare name at $at can be a class's: not a member after
     * `->` or `::`; a class before `::` wherever it is, `case Panel::Admin:`
     * included; otherwise not the name a function, constant, case or class
     * is declared with, not an alias or a label, and not a named argument.
     * A class's name is matched as written, so a method or argument named
     * like a class in lower case is not taken for it.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function namesClass(array $tokens, int $at): bool
    {
        $previous = null;
        for ($i = $at - 1; $i >= 0; $i--) {
            if (!$tokens[$i]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                $previous = $tokens[$i];

                break;
            }
        }
        $next = self::next($tokens, $at);

        if ($previous !== null && $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON])) {
            return false;
        }
        if ($next !== null && $tokens[$next]->is(\T_DOUBLE_COLON)) {
            return true;
        }
        if ($previous !== null && $previous->is([\T_FUNCTION, \T_CONST, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_CASE, \T_GOTO, \T_AS])) {
            return false;
        }

        return $next === null || $tokens[$next]->text !== ':';
    }

    /** @param array<int, \PhpToken> $tokens */
    private static function next(array $tokens, int $at): ?int
    {
        $count = \count($tokens);
        for ($i = $at + 1; $i < $count; $i++) {
            if (!$tokens[$i]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    }
}
