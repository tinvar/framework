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
    /**
     * The attributes declarative authorization is made of, by the last
     * segment of their name, lower-cased: the checks and their exemptions,
     * and what decides who reaches an entry point or which policy asks --
     * its panel and permission, its middleware, its tenant, its host, its
     * session, the tokens and scopes it takes, a model's policy and an
     * admin resource's panel (ADR 0216).
     */
    private const array NAMES = [
        'authorize', 'publicaccess', 'public', 'noauthorization',
        'panel', 'middleware', 'policy', 'tenantfrom', 'domain', 'withoutsession',
        'acceptstenanttokens', 'requiresscope', 'adminresource',
    ];

    private const array NAME = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE];

    private const array OPENING = ['(', '[', '{', \T_ATTRIBUTE, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES];

    /** The functions that can declare a global constant, called by name or handed it. */
    private const array DEFINING = ['define', 'call_user_func', 'call_user_func_array'];

    /**
     * @param list<array{int, int}> $ranges the first and last line of each attribute block, `use`, namespace declaration and constant
     * @param array<string, array{line: int, label: string, parts: list<array{string, string}>}> $entries
     *        by lower-cased class, or class::method; each part a [comparison key, what a reviewer reads]
     * @param list<array{line: int, label: string, refs: list<string>}> $references what each
     *        class's or method's authorization attributes name in their arguments (references())
     * @param array<string, list<string>> $constants what each class constant and enum case declared
     *        here names in its value, by lower-cased class and its own name: `app\abilities::REFUND`
     * @param list<string> $classes the classes, interfaces, traits and enums declared here, lower-cased
     * @param bool $definesConstants whether the file names define() or call_user_func() -- as a
     *        call, a callable or a string -- with which any code can declare a global constant
     */
    private function __construct(
        public array $ranges,
        public array $entries,
        public array $references = [],
        public array $constants = [],
        public array $classes = [],
        public bool $definesConstants = false,
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
        /** @var list<string> $pendingRefs what their arguments name */
        $pendingRefs = [];
        $ranges = [];
        $entries = [];
        $references = [];
        $constants = [];
        $declared = [];
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
                [$end, $attributes, $refs] = self::attributes($tokens, $i, $namespace, $imports);
                $ranges[] = [$token->line, $tokens[$end]->line];
                $pending = [...$pending, ...$attributes];
                $pendingRefs = [...$pendingRefs, ...$refs];
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
                $refs = $pendingRefs;
                $pending = $pendingRefs = [];
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
                if ($refs !== []) {
                    $references[] = ['line' => $entries[$key]['line'], 'label' => $label, 'refs' => self::placed($refs, $label)];
                }
                if ($named) {
                    $declared[] = strtolower($label);
                }
                $classes[] = ['depth' => ++$depth, 'key' => $key, 'label' => $label];
                $i = $end;
            } elseif ($token->is(\T_FUNCTION)) {
                if ($class !== null && $class['depth'] === $depth) {
                    $name = $tokens[$i + 1]->is('&') ? $tokens[$i + 2] : $tokens[$i + 1];
                    $key = self::unique($class['key'] . '::' . strtolower($name->text), $entries);
                    $entries[$key] = ['line' => $name->line, 'label' => $class['label'] . '::' . $name->text . '()', 'parts' => $pending];
                    if ($pendingRefs !== []) {
                        $references[] = ['line' => $name->line, 'label' => $entries[$key]['label'], 'refs' => self::placed($pendingRefs, $class['label'])];
                    }
                }
                $pending = $pendingRefs = [];
            } elseif ($token->is(\T_CONST) || ($token->is(\T_CASE) && $class !== null && $class['depth'] === $depth)) {
                // A constant or an enum case: no entry point, but what an
                // attribute's argument can name from anywhere, so every line
                // of its declaration counts.
                $end = self::statementEnd($tokens, $i);
                $ranges[] = [$token->line, $tokens[$end]->line];
                if ($class !== null && $class['depth'] === $depth) {
                    self::declared($tokens, $i, $end, $class['label'], $namespace, $imports, $constants);
                }
                $pending = $pendingRefs = [];
                $i = $end;
            } elseif ($token->is([\T_FN, \T_VARIABLE, \T_CASE])) {
                // An attribute on a closure, property or parameter: none of
                // them is an entry point.
                $pending = $pendingRefs = [];
            } elseif (self::defines($tokens, $i)) {
                // define(): a constant too, declared by a call.
                $end = self::closing($tokens, $i + 1);
                $ranges[] = [$token->line, $tokens[$end]->line];
                $i = $end;
            }
        }

        return new self($ranges, $entries, $references, $constants, $declared, self::namesDefine($tokens));
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
     * authorization ones, each as a part, and what their arguments name.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     * @return array{int, list<array{string, string}>, list<string>}
     */
    private static function attributes(array $tokens, int $start, string $namespace, array $imports): array
    {
        $found = [];
        $refs = [];
        $i = $start + 1;
        while (!$tokens[$i]->is(']')) {
            if (!$tokens[$i]->is(self::NAME)) {
                $i++;

                continue;
            }
            $name = self::resolve($tokens[$i]->text, $namespace, $imports);
            $last = strtolower(substr((string) strrchr('\\' . $name, '\\'), 1));
            $arguments = '';
            if ($tokens[$i + 1]->is('(')) {
                $close = self::closing($tokens, $i + 1);
                $arguments = self::text($tokens, $i + 1, $close);
                if (\in_array($last, self::NAMES, true)) {
                    $refs = [...$refs, ...self::references($tokens, $i + 2, $close, $namespace, $imports)];
                }
                $i = $close;
            }
            $i++;
            if (\in_array($last, self::NAMES, true)) {
                $found[] = [strtolower($name) . $arguments, '#[\\' . $name . $arguments . ']'];
            }
        }

        return [$i, $found, $refs];
    }

    /**
     * What the constant expression from $from up to $to names that decides
     * its value, each as `global:NAME`, `class:Class::NAME` (`self` left for
     * the caller to place) or `unverifiable:what` -- a `new`, a closure, a
     * call, `parent::` or `static::`, which nothing here can follow.
     *
     * A global constant is named as it is written, and not resolved: any
     * code can declare one with define(), under a name no string spells and
     * before the file that seems to declare it, so whichever it is, nothing
     * that reads the code can say what it holds. Only `true`, `false`,
     * `null`, and a constant PHP itself declares where no namespace can
     * stand in for it, are known.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     * @return list<string>
     */
    private static function references(array $tokens, int $from, int $to, string $namespace, array $imports): array
    {
        $refs = [];
        for ($i = $from; $i < $to; $i++) {
            $token = $tokens[$i];
            if ($token->is([\T_NEW, \T_FN, \T_FUNCTION, \T_VARIABLE])) {
                $refs[] = 'unverifiable:' . strtolower($token->text);

                continue;
            }
            if (!$token->is([...self::NAME, \T_STATIC])) {
                continue;
            }
            $previous = $tokens[$i - 1];
            $next = $tokens[$i + 1];
            // A named argument's name, or an enum case's `->value`.
            if (($next->is(':') && $previous->is(['(', ','])) || $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }
            if ($next->is(\T_DOUBLE_COLON)) {
                $member = $tokens[$i + 2];
                $i += 2;
                if (strtolower($member->text) === 'class') {
                    continue;
                }
                $scope = strtolower($token->text);
                $refs[] = match ($scope) {
                    'self' => "class:self::{$member->text}",
                    'parent', 'static' => "unverifiable:{$scope}::{$member->text}",
                    default => 'class:' . self::resolve($token->text, $namespace, $imports) . "::{$member->text}",
                };

                continue;
            }
            if ($next->is('(')) {
                $refs[] = "unverifiable:{$token->text}()";

                continue;
            }

            $bare = ltrim($token->text, '\\');
            $fixed = str_starts_with($token->text, '\\') || ($namespace === '' && !str_contains($bare, '\\'));
            if (\in_array(strtolower($bare), ['true', 'false', 'null'], true) || ($fixed && self::builtIn($bare))) {
                continue;
            }
            $refs[] = "global:{$token->text}";
        }

        return $refs;
    }

    /** Whether PHP itself, or an extension, declares the constant $name -- which no define() can change. */
    private static function builtIn(string $name): bool
    {
        static $internal = null;
        if ($internal === null) {
            $internal = [];
            foreach (get_defined_constants(true) as $category => $constants) {
                if ($category !== 'user') {
                    $internal += $constants;
                }
            }
        }

        return \array_key_exists($name, $internal);
    }

    /**
     * Each class constant or enum case declared by the statement from $start
     * to $end, and what its value names.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     * @param array<string, list<string>> $constants
     */
    private static function declared(array $tokens, int $start, int $end, string $class, string $namespace, array $imports, array &$constants): void
    {
        $from = $start + 1;
        for ($i = $from; $i <= $end; $i++) {
            if ($tokens[$i]->is(self::OPENING)) {
                $i = self::closing($tokens, $i);

                continue;
            }
            if (!$tokens[$i]->is([',', ';'])) {
                continue;
            }
            // One declaration, $from up to the comma or the end: its name is
            // the word before its `=`, and an enum case may have neither.
            $equals = $from;
            while ($equals < $i && !$tokens[$equals]->is('=')) {
                $equals++;
            }
            $name = $equals < $i ? $tokens[$equals - 1] : $tokens[$from];
            if ($name->is(\T_STRING)) {
                $refs = $equals < $i ? self::references($tokens, $equals + 1, $i, $namespace, $imports) : [];
                $constants[strtolower($class) . '::' . $name->text] = self::placed($refs, $class);
            }
            $from = $i + 1;
        }
    }

    /**
     * $refs with `self` placed in $class.
     *
     * @param list<string> $refs
     * @return list<string>
     */
    private static function placed(array $refs, string $class): array
    {
        return array_map(
            static fn (string $ref): string => str_starts_with($ref, 'class:self::') ? 'class:' . $class . substr($ref, 10) : $ref,
            $refs,
        );
    }

    /**
     * Whether the file names a function that can declare a global constant:
     * called, handed as a callable, or in a string a variable function or
     * call_user_func() could take it from.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function namesDefine(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token->is(self::NAME) && \in_array(strtolower(substr((string) strrchr('\\' . $token->text, '\\'), 1)), self::DEFINING, true)) {
                return true;
            }
            if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])
                && preg_match('/\b(?:define|call_user_func(?:_array)?)\b/i', $token->text) === 1) {
                return true;
            }
        }

        return false;
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
