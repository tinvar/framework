<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * What one PHP file says about authorization, read from its tokens, for
 * Codemods to compare before against after.
 *
 * A scan of changed lines sees an authorization attribute only where its
 * name is spelled out on a line that changed, and a codemod has many ways
 * round that: an alias (`use Tinvar\Attributes\PublicAccess as Page;`), the
 * prefix of a group import on a line of its own, one argument of an
 * attribute written over several lines, a comment opened and closed on
 * lines that name nothing, two method names swapped under attributes that
 * stay put. So names here resolve the way PHP resolves them -- namespace,
 * imports, aliases, in any case -- and each class and method keeps what
 * decides its authorization:
 *
 *   - the authorization attributes on it, by resolved name and arguments;
 *   - for a class, the parent and traits as well, which ClassAttributes
 *     reads a class's checks off.
 *
 * The lines that decide what a name resolves to or what an attribute says
 * -- every attribute block, every `use` (an import or a trait), every
 * namespace declaration, and every constant an argument could name: a
 * `const`, an enum case or a define() -- are kept as line ranges, so a
 * change to any line of one is flagged whatever the line spells.
 */
final readonly class AuthorizationMap
{
    /** The attributes declarative authorization is made of, by the last segment of their name, lower-cased. */
    private const array NAMES = ['authorize', 'publicaccess', 'public', 'noauthorization'];

    private const array NAME = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE];

    private const array OPENING = ['(', '[', '{', \T_ATTRIBUTE, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES];

    /**
     * @param list<array{int, int}> $ranges the first and last line of each attribute block, `use`, namespace declaration and constant
     * @param array<string, array{line: int, label: string, parts: list<array{string, string}>}> $entries
     *        by lower-cased class, or class::method; each part a [comparison key, what a reviewer reads]
     */
    private function __construct(
        public array $ranges,
        public array $entries,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    /** @throws \CompileError when $code is not valid PHP */
    public static function of(string $code): self
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code, \TOKEN_PARSE),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $namespace = '';
        /** @var array<string, string> $imports lower-cased alias => class */
        $imports = [];
        $importDepth = 0;
        $depth = 0;
        /** @var list<array{depth: int, key: string, label: string}> $classes the class bodies the cursor is in */
        $classes = [];
        /** @var list<array{string, string}> $pending authorization attributes not yet attached to a declaration */
        $pending = [];
        $ranges = [];
        $entries = [];
        $anonymous = 0;

        for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $class = $classes === [] ? null : $classes[array_key_last($classes)];

            if ($token->is(['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is('}')) {
                $depth--;
                if ($class !== null && $class['depth'] > $depth) {
                    array_pop($classes);
                }
                if ($importDepth > $depth) {
                    [$namespace, $imports, $importDepth] = ['', [], 0];
                }
            } elseif ($token->is(\T_ATTRIBUTE)) {
                [$end, $attributes] = self::attributes($tokens, $i, $namespace, $imports);
                $ranges[] = [$token->line, $tokens[$end]->line];
                $pending = [...$pending, ...$attributes];
                $i = $end;
            } elseif ($token->is(\T_NAMESPACE)) {
                $end = $i + 1;
                $namespace = '';
                if ($tokens[$end]->is([\T_STRING, \T_NAME_QUALIFIED])) {
                    $namespace = $tokens[$end++]->text;
                }
                $imports = [];
                if ($tokens[$end]->is('{')) {
                    $importDepth = ++$depth;
                }
                $ranges[] = [$token->line, $tokens[$end]->line];
                $i = $end;
            } elseif ($token->is(\T_USE) && !($i > 0 && $tokens[$i - 1]->is(')'))) {
                // `function () use ($x)` is neither an import nor a trait.
                if ($class !== null && $class['depth'] === $depth && isset($entries[$class['key']])) {
                    $end = $i + 1;
                    for (; !$tokens[$end]->is([';', '{']); $end++) {
                        if ($tokens[$end]->is(self::NAME)) {
                            $trait = self::resolve($tokens[$end]->text, $namespace, $imports);
                            $entries[$class['key']]['parts'][] = ['use ' . strtolower($trait), 'use \\' . $trait];
                        }
                    }
                    if ($tokens[$end]->is('{')) {
                        $close = self::closing($tokens, $end);
                        $adaptations = self::text($tokens, $end, $close);
                        $entries[$class['key']]['parts'][] = [$adaptations, $adaptations];
                        $end = $close;
                    }
                    $ranges[] = [$token->line, $tokens[$end]->line];
                    $i = $end;
                } elseif ($depth === $importDepth) {
                    $end = self::imports($tokens, $i, $imports);
                    $ranges[] = [$token->line, $tokens[$end]->line];
                    $i = $end;
                }
            } elseif ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM])) {
                $named = $tokens[$i + 1]->is(\T_STRING);
                $label = $named
                    ? ($namespace === '' ? '' : $namespace . '\\') . $tokens[$i + 1]->text
                    : ($class['label'] ?? $namespace) . '@anonymous' . ++$anonymous;
                $parts = $pending;
                $pending = [];
                $extends = false;
                $end = $i + 1;
                for (; !$tokens[$end]->is('{'); $end++) {
                    if ($tokens[$end]->is('(')) {
                        // An anonymous class's constructor arguments.
                        $end = self::closing($tokens, $end);
                    } elseif ($tokens[$end]->is([\T_EXTENDS, \T_IMPLEMENTS])) {
                        $extends = $tokens[$end]->is(\T_EXTENDS);
                    } elseif ($extends && $tokens[$end]->is(self::NAME)) {
                        $parent = self::resolve($tokens[$end]->text, $namespace, $imports);
                        $parts[] = ['extends ' . strtolower($parent), 'extends \\' . $parent];
                    }
                }
                $key = self::unique(strtolower($label), $entries);
                $entries[$key] = ['line' => $tokens[$named ? $i + 1 : $i]->line, 'label' => $label, 'parts' => $parts];
                $classes[] = ['depth' => ++$depth, 'key' => $key, 'label' => $label];
                $i = $end;
            } elseif ($token->is(\T_FUNCTION)) {
                if ($class !== null && $class['depth'] === $depth) {
                    $name = $tokens[$i + 1]->is('&') ? $tokens[$i + 2] : $tokens[$i + 1];
                    $key = self::unique($class['key'] . '::' . strtolower($name->text), $entries);
                    $entries[$key] = ['line' => $name->line, 'label' => $class['label'] . '::' . $name->text . '()', 'parts' => $pending];
                }
                $pending = [];
            } elseif ($token->is(\T_CONST) || ($token->is(\T_CASE) && $class !== null && $class['depth'] === $depth)) {
                // A constant or an enum case: no entry point, but what an
                // attribute's argument can name from anywhere, so every line
                // of its declaration counts.
                $end = self::statementEnd($tokens, $i);
                $ranges[] = [$token->line, $tokens[$end]->line];
                $pending = [];
                $i = $end;
            } elseif ($token->is([\T_FN, \T_VARIABLE, \T_CASE])) {
                // An attribute on a closure, property or parameter: none of
                // them is an entry point.
                $pending = [];
            } elseif (self::defines($tokens, $i)) {
                // define(): a constant too, declared by a call.
                $end = self::closing($tokens, $i + 1);
                $ranges[] = [$token->line, $tokens[$end]->line];
                $i = $end;
            }
        }

        return new self($ranges, $entries);
    }

    /** Whether $line is part of an attribute block, a `use`, a namespace declaration or a constant's. */
    public function covers(int $line): bool
    {
        foreach ($this->ranges as [$first, $last]) {
            if ($line >= $first && $line <= $last) {
                return true;
            }
        }

        return false;
    }

    /** What decides $key's authorization, compared. */
    public function signature(string $key): string
    {
        return implode("\n", array_column($this->entries[$key]['parts'] ?? [], 0));
    }

    /** What decides $key's authorization, as a reviewer reads it. */
    public function describe(string $key): string
    {
        $parts = $this->entries[$key]['parts'] ?? [];

        return $parts === [] ? 'none' : implode(' ', array_column($parts, 1));
    }

    /**
     * The attributes of the group that opens at $start, up to its `]`: the
     * authorization ones, each as a part.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     * @return array{int, list<array{string, string}>}
     */
    private static function attributes(array $tokens, int $start, string $namespace, array $imports): array
    {
        $found = [];
        $i = $start + 1;
        while (!$tokens[$i]->is(']')) {
            if (!$tokens[$i]->is(self::NAME)) {
                $i++;

                continue;
            }
            $name = self::resolve($tokens[$i]->text, $namespace, $imports);
            $arguments = '';
            if ($tokens[$i + 1]->is('(')) {
                $close = self::closing($tokens, $i + 1);
                $arguments = self::text($tokens, $i + 1, $close);
                $i = $close;
            }
            $i++;
            $last = strtolower(substr((string) strrchr('\\' . $name, '\\'), 1));
            if (\in_array($last, self::NAMES, true)) {
                $found[] = [strtolower($name) . $arguments, '#[\\' . $name . $arguments . ']'];
            }
        }

        return [$i, $found];
    }

    /**
     * Reads the import that starts at $use into $imports -- classes only, as
     * attributes name nothing else -- and returns where it ends.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     */
    private static function imports(array $tokens, int $use, array &$imports): int
    {
        $i = $use + 1;
        $classes = !$tokens[$i]->is([\T_FUNCTION, \T_CONST]);
        $item = $classes;
        $prefix = '';
        for (; !$tokens[$i]->is(';'); $i++) {
            $token = $tokens[$i];
            if ($token->is([\T_FUNCTION, \T_CONST])) {
                $item = false;
            } elseif ($token->is(',')) {
                $item = $classes;
            } elseif ($token->is('}')) {
                $prefix = '';
            } elseif ($token->is(self::NAME)) {
                if ($tokens[$i + 1]->is(\T_NS_SEPARATOR)) {
                    // `use Prefix\{A, B as C};`: past the separator and the brace.
                    $prefix = ltrim($token->text, '\\') . '\\';
                    $i += 2;

                    continue;
                }
                $name = $prefix . ltrim($token->text, '\\');
                $alias = substr((string) strrchr('\\' . $name, '\\'), 1);
                if ($tokens[$i + 1]->is(\T_AS)) {
                    $alias = $tokens[$i + 2]->text;
                    $i += 2;
                }
                if ($item) {
                    $imports[strtolower($alias)] = $name;
                }
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
        if (strncasecmp($name, 'namespace\\', 10) === 0) {
            $name = substr($name, 10);
        } else {
            $first = strtolower(explode('\\', $name, 2)[0]);
            if (isset($imports[$first])) {
                return $imports[$first] . substr($name, \strlen($first));
            }
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * The `;` that ends the statement at $start, past any brackets in it --
     * a constant's value can hold an array, or a closure with statements of
     * its own.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function statementEnd(array $tokens, int $start): int
    {
        for ($i = $start, $count = \count($tokens); $i < $count; $i++) {
            if ($tokens[$i]->is(self::OPENING)) {
                $i = self::closing($tokens, $i);
            } elseif ($tokens[$i]->is(';')) {
                return $i;
            }
        }

        throw new \CompileError('A statement with no end.');
    }

    /**
     * Whether a call to the global define() starts at $i: not a method or a
     * function of that name, nor one being declared.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function defines(array $tokens, int $i): bool
    {
        return $tokens[$i]->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])
            && strcasecmp(ltrim($tokens[$i]->text, '\\'), 'define') === 0
            && ($tokens[$i + 1] ?? null)?->is('(') === true
            && !($i > 0 && $tokens[$i - 1]->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION]));
    }

    /**
     * The bracket that closes the one at $open.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function closing(array $tokens, int $open): int
    {
        $nesting = 0;
        for ($i = $open, $count = \count($tokens); $i < $count; $i++) {
            if ($tokens[$i]->is(self::OPENING)) {
                $nesting++;
            } elseif ($tokens[$i]->is([')', ']', '}']) && --$nesting === 0) {
                return $i;
            }
        }

        throw new \CompileError('Unbalanced brackets.');
    }

    /**
     * Tokens $from to $to as one line: a space after each comma, none
     * before a closing bracket, and one between two words.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function text(array $tokens, int $from, int $to): string
    {
        $text = '';
        for ($i = $from; $i <= $to; $i++) {
            $token = $tokens[$i]->text;
            if (str_ends_with($text, ', ') && \in_array($token, [')', ']', '}'], true)) {
                $text = substr($text, 0, -2);
            } elseif (preg_match('/\w$/', $text) === 1 && preg_match('/^\w/', $token) === 1) {
                $text .= ' ';
            }
            $text .= $token . ($token === ',' ? ' ' : '');
        }

        return $text;
    }

    /** @param array<string, mixed> $entries */
    private static function unique(string $key, array $entries): string
    {
        $unique = $key;
        for ($n = 2; isset($entries[$unique]); $n++) {
            $unique = "{$key}#{$n}";
        }

        return $unique;
    }
}
