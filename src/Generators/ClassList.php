<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * One `const NAME = [A::class, B::class]` list in a PHP file -- a Kernel's
 * ROUTES, QUERIES, ACTIONS or MODELS, or a console's APP_MODELS -- and the
 * one edit a generator makes to it: a class added at its end.
 *
 * The edit is made only where the file reads exactly as such a list does,
 * and refused otherwise, saying why:
 *
 *   - the constant is declared once in the file, as `const [array] NAME = [`;
 *   - between its brackets there is nothing but `Name::class` entries,
 *     commas, whitespace and comments -- no spread, no key, no call, no
 *     string, nothing evaluated;
 *   - the file declares no namespace or imports this cannot read: a grouped
 *     `use A\{B, C}` is refused, since an entry named through it could not
 *     be told apart from the class being added.
 *
 * So the edit cannot change what any other entry means, or land anywhere
 * but in the list. A class already there -- under any name the file's
 * namespace and imports give it -- is left, so running a generator twice
 * adds it once. The class is named the way the file names its others: by an
 * import, which is added in order among the file's own where its short name
 * is free -- neither imported nor used bare anywhere in the file -- or else
 * in full. The new source is parsed before it is written,
 * and the list read again to hold one more entry, naming the class, and
 * every other entry naming what it named before; then it
 * is written to a file beside the old one and renamed over it.
 *
 * A symbolic link is never written through.
 */
final class ClassList
{
    /** The tokens a class name in an entry may be. */
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** @var list<\PhpToken> */
    private array $tokens;

    private string $namespace = '';

    /** @var array<string, array{string, string}> lowercased alias => the alias as written, and its class: the file's class imports */
    private array $imports = [];

    /** The index of the last top-level use statement's `;`, for placing a new one after it. */
    private ?int $lastImport = null;

    /** @var list<array{int, string}> top-level imports of one class each: index of `use`, and the class */
    private array $importStatements = [];

    /** @param int $flags TOKEN_PARSE to refuse a source that does not parse */
    private function __construct(private readonly string $source, int $flags = 0)
    {
        $this->tokens = array_values(\PhpToken::tokenize($source, $flags));
        $this->readHeader();
    }

    /**
     * Adds $class to the list $constant in $file, unless it is there.
     *
     * @return bool true where it was added, false where it was there already
     * @throws RegistrationRefused naming why the file was left as it was
     */
    public static function add(string $file, string $constant, string $class): bool
    {
        $class = ltrim($class, '\\');
        $source = self::read($file);
        $list = new self($source);

        [$open, $close, $entries] = $list->find($constant);
        foreach ($entries as $entry) {
            if (strcasecmp($entry['class'], $class) === 0) {
                return false;
            }
        }

        [$name, $import] = $list->nameFor($class);
        $edited = $list->insert($open, $close, $entries, "{$name}::class");
        if ($import !== null) {
            $edited = self::withImport($edited, $import);
        }

        // Read again, as written: it parses, and the list holds one more
        // entry, which names the class.
        try {
            $after = new self($edited, TOKEN_PARSE)->find($constant)[2];
        } catch (\ParseError $e) {
            throw new RegistrationRefused("the edit would not parse ({$e->getMessage()})");
        }
        if (\count($after) !== \count($entries) + 1 || strcasecmp(end($after)['class'], $class) !== 0) {
            throw new RegistrationRefused("the edit did not read back as {$constant} with {$class} added");
        }
        // And every entry that was there still names the class it named.
        foreach ($entries as $i => $entry) {
            if (strcasecmp($after[$i]['class'], $entry['class']) !== 0) {
                throw new RegistrationRefused("the edit would have changed what {$constant} names: {$entry['class']} would read as {$after[$i]['class']}");
            }
        }

        self::write($file, $edited);

        return true;
    }

    /** The source of $file, which must be a plain file. */
    public static function read(string $file): string
    {
        if (is_link($file) || !is_file($file)) {
            throw new RegistrationRefused(is_link($file) ? 'it is a symbolic link' : 'there is no such file');
        }
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RegistrationRefused('it could not be read');
        }

        return $source;
    }

    /** Writes $source over $file through a file beside it, keeping its mode. */
    public static function write(string $file, string $source): void
    {
        $temporary = \dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new RegistrationRefused('a file beside it could not be made to write through');
        }
        $ok = fwrite($handle, $source) === \strlen($source);
        $ok = fclose($handle) && $ok;
        $mode = fileperms($file);
        if ($ok && $mode !== false) {
            $ok = chmod($temporary, $mode & 0o7777);
        }
        if (!$ok || is_link($file) || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new RegistrationRefused('it could not be written');
        }
    }

    /**
     * The namespace and the top-level class imports, read the way PHP reads
     * them for a name in an expression.
     */
    private function readHeader(): void
    {
        $depth = 0;
        $count = \count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->id === T_NAMESPACE && $depth === 0) {
                $next = $this->next($i);
                if ($next !== null && \in_array($this->tokens[$next]->id, [T_STRING, T_NAME_QUALIFIED], true)) {
                    if ($this->namespace !== '') {
                        throw new RegistrationRefused('it declares more than one namespace');
                    }
                    $this->namespace = $this->tokens[$next]->text;
                }
            } elseif ($token->id === T_USE && $depth === 0) {
                $i = $this->readImport($i);
            }
        }
    }

    /** @return int the index of the statement's `;` */
    private function readImport(int $use): int
    {
        $next = $this->next($use);
        $kind = $next === null ? null : $this->tokens[$next];
        // A closure's `use (...)`, written outside any function.
        if ($kind !== null && $kind->text === '(') {
            return $use;
        }
        // A function or constant import names no class.
        if ($kind !== null && $kind->id === T_STRING && \in_array(strtolower($kind->text), ['function', 'const'], true)) {
            return $this->endOfStatement($use);
        }

        $end = $this->endOfStatement($use);
        $parts = [];
        for ($i = $use + 1; $i < $end; $i++) {
            if (!$this->tokens[$i]->isIgnorable()) {
                $parts[] = $this->tokens[$i];
            }
        }
        $clauses = [[]];
        foreach ($parts as $part) {
            if ($part->text === '{' || $part->text === '}') {
                throw new RegistrationRefused('it has a grouped use statement, whose names this does not read');
            }
            if ($part->text === ',') {
                $clauses[] = [];

                continue;
            }
            $clauses[\count($clauses) - 1][] = $part;
        }
        foreach ($clauses as $clause) {
            $class = isset($clause[0]) && \in_array($clause[0]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? ltrim($clause[0]->text, '\\') : null;
            $alias = match (\count($clause)) {
                1 => $class === null ? null : self::short($class),
                3 => $clause[1]->id === T_AS && $clause[2]->id === T_STRING ? $clause[2]->text : null,
                default => null,
            };
            if ($class === null || $alias === null) {
                throw new RegistrationRefused('it has a use statement this does not read');
            }
            $this->imports[strtolower($alias)] = [$alias, $class];
        }
        if (\count($clauses) === 1) {
            $this->importStatements[] = [$use, ltrim($clauses[0][0]->text, '\\')];
        }
        $this->lastImport = $end;

        return $end;
    }

    /**
     * The list: the index of its `[`, of its `]`, and its entries.
     *
     * @return array{int, int, list<array{start: int, end: int, class: string}>}
     */
    private function find(string $constant): array
    {
        $found = [];
        $count = \count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($this->tokens[$i]->id !== T_CONST) {
                continue;
            }
            $at = $this->next($i);
            // A typed constant: `const array NAME`.
            if ($at !== null && $this->tokens[$at]->id === T_ARRAY) {
                $at = $this->next($at);
            }
            if ($at === null || $this->tokens[$at]->id !== T_STRING || $this->tokens[$at]->text !== $constant) {
                continue;
            }
            $equals = $this->next($at);
            $open = $equals === null ? null : $this->next($equals);
            if ($equals === null || $this->tokens[$equals]->text !== '=' || $open === null || $this->tokens[$open]->text !== '[') {
                throw new RegistrationRefused("{$constant} is not a list written [ ... ]");
            }
            $found[] = $open;
        }
        if (\count($found) !== 1) {
            throw new RegistrationRefused($found === [] ? "it declares no {$constant}" : "it declares {$constant} more than once");
        }

        $open = $found[0];
        $entries = [];
        $expect = 'entry';
        for ($i = $open + 1; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token->isIgnorable()) {
                continue;
            }
            if ($token->text === ']') {
                return [$open, $i, $entries];
            }
            if ($token->text === ',' && $expect === 'comma') {
                $expect = 'entry';

                continue;
            }
            // `Name::class`: `class` after `::` is T_CLASS, or T_STRING
            // where the source is read as a parse reads it.
            $colons = $this->next($i);
            $keyword = $colons === null ? null : $this->next($colons);
            if ($expect !== 'entry' || !\in_array($token->id, self::NAME_TOKENS, true) || $colons === null || $keyword === null
                || $this->tokens[$colons]->id !== T_DOUBLE_COLON || strtolower($this->tokens[$keyword]->text) !== 'class'
                || !\in_array($this->tokens[$keyword]->id, [T_CLASS, T_STRING], true)) {
                throw new RegistrationRefused("{$constant} holds something other than Name::class entries");
            }
            $entries[] = ['start' => $i, 'end' => $keyword, 'class' => $this->resolve($token)];
            $i = $keyword;
            $expect = 'comma';
        }

        throw new RegistrationRefused("{$constant} has no closing ]");
    }

    /** The class an entry's name means, as PHP resolves it. */
    private function resolve(\PhpToken $name): string
    {
        return match ($name->id) {
            T_NAME_FULLY_QUALIFIED => ltrim($name->text, '\\'),
            T_NAME_RELATIVE => $this->qualify(substr($name->text, \strlen('namespace\\'))),
            default => $this->viaImport($name->text) ?? $this->qualify($name->text),
        };
    }

    private function viaImport(string $name): ?string
    {
        $first = explode('\\', $name, 2);
        $imported = $this->imports[strtolower($first[0])][1] ?? null;

        return $imported === null ? null : $imported . (isset($first[1]) ? '\\' . $first[1] : '');
    }

    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : "{$this->namespace}\\{$name}";
    }

    /**
     * How the file is to name $class, and the import to add for it, if any:
     * an import it has; its short name, imported where that is free; its
     * name relative to the file's namespace; or its full name.
     *
     * @return array{string, string|null}
     */
    private function nameFor(string $class): array
    {
        foreach ($this->imports as [$alias, $imported]) {
            if (strcasecmp($imported, $class) === 0) {
                return [$alias, null];
            }
        }

        $short = self::short($class);
        $imported = isset($this->imports[strtolower($short)]);
        // An import takes the short name for the whole file: any bare use of
        // it -- an entry here, a type or a `new` elsewhere, the first segment
        // of a qualified name -- means a class of the file's namespace now,
        // and would mean the imported one after.
        $taken = $imported || $this->usesBare($short);

        if ($this->namespace === '') {
            // No namespace to import into: the name in full, as the file's
            // other entries are; with a leading \ where an import would
            // otherwise take its first segment.
            return [isset($this->imports[strtolower(explode('\\', $class)[0])]) ? "\\{$class}" : $class, null];
        }
        // In the file's own namespace, a bare use of the short name already
        // means this class: only an import can take it.
        if (!$imported && strcasecmp(self::namespaceOf($class), $this->namespace) === 0) {
            return [$short, null];
        }
        if (!$taken && $this->lastImport !== null) {
            return [$short, $class];
        }

        return ["\\{$class}", null];
    }

    /**
     * Whether $short appears anywhere in the file as a bare name, or as the
     * first segment of a qualified one. Read wide -- a method or a constant
     * of that name counts too -- since a name taken for nothing costs only
     * a class written in full.
     */
    private function usesBare(string $short): bool
    {
        foreach ($this->tokens as $token) {
            $name = match ($token->id) {
                T_STRING => $token->text,
                T_NAME_QUALIFIED => explode('\\', $token->text, 2)[0],
                default => null,
            };
            if ($name !== null && strcasecmp($name, $short) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source with $entry added at the end of the list, in its style:
     * on a line of its own, indented as the last entry is, in a list
     * written one entry to a line; after the last, in one written on one.
     *
     * @param list<array{start: int, end: int, class: string}> $entries
     */
    private function insert(int $open, int $close, array $entries, string $entry): string
    {
        $offsets = $this->offsets();

        $multiline = false;
        for ($i = $open + 1; $i < $close; $i++) {
            if ($this->tokens[$i]->id === T_WHITESPACE && str_contains($this->tokens[$i]->text, "\n")) {
                $multiline = true;

                break;
            }
        }

        if ($entries === []) {
            if (!$multiline) {
                return substr($this->source, 0, $offsets[$open] + 1) . $entry . substr($this->source, $offsets[$close]);
            }
            // `[` and `]` on lines of their own: the entry on one between,
            // indented a level past the bracket's.
            $lineStart = strrpos(substr($this->source, 0, $offsets[$close]), "\n");
            $indent = $lineStart === false ? '' : substr($this->source, $lineStart + 1, $offsets[$close] - $lineStart - 1);
            $indent = trim($indent) === '' ? $indent : '';
            $at = $lineStart === false ? $offsets[$close] : $lineStart;

            return substr($this->source, 0, $at) . "\n{$indent}    {$entry}," . substr($this->source, $at);
        }

        $last = end($entries);
        $comma = $this->next($last['end']);
        $hasComma = $comma !== null && $this->tokens[$comma]->text === ',';

        // Where the new entry goes: after the last entry's comma -- and a
        // comment on that same line, which is the last entry's -- or after
        // the entry itself.
        $after = $hasComma ? $comma : $last['end'];
        $next = $after + 1;
        if ($multiline && isset($this->tokens[$next])) {
            $gap = $this->tokens[$next];
            $comment = $gap->id === T_WHITESPACE && !str_contains($gap->text, "\n") ? $next + 1 : $next;
            if (isset($this->tokens[$comment]) && $this->tokens[$comment]->id === T_COMMENT && !str_contains(rtrim($this->tokens[$comment]->text), "\n")) {
                $after = $comment;
            }
        }
        $at = $offsets[$after] + \strlen(rtrim($this->tokens[$after]->text, "\n"));

        if ($multiline) {
            $lineStart = strrpos(substr($this->source, 0, $offsets[$last['start']]), "\n");
            $indent = $lineStart === false ? '' : substr($this->source, $lineStart + 1, $offsets[$last['start']] - $lineStart - 1);
            $indent = trim($indent) === '' ? $indent : '    ';
            $text = ($hasComma ? '' : ',') . "\n{$indent}{$entry},";
            if (!$hasComma) {
                $at = $offsets[$last['end']] + \strlen($this->tokens[$last['end']]->text);
            }
        } else {
            $text = $hasComma ? " {$entry}," : ", {$entry}";
        }

        return substr($this->source, 0, $at) . $text . substr($this->source, $at);
    }

    /**
     * $source with `use $class;` among its imports, in order: before the
     * first single-class import that sorts after it, or after the last.
     */
    private static function withImport(string $source, string $class): string
    {
        $list = new self($source);
        $offsets = $list->offsets();
        foreach ($list->importStatements as [$use, $imported]) {
            if (strcasecmp(str_replace('\\', ' ', $imported), str_replace('\\', ' ', $class)) > 0) {
                return substr($source, 0, $offsets[$use]) . "use {$class};\n" . substr($source, $offsets[$use]);
            }
        }
        if ($list->lastImport === null) {
            throw new RegistrationRefused('it has no imports to add one beside');
        }
        $end = $offsets[$list->lastImport] + 1;

        return substr($source, 0, $end) . "\nuse {$class};" . substr($source, $end);
    }

    /** @return array<int, int> each token's byte offset in the source */
    private function offsets(): array
    {
        $offsets = [];
        $offset = 0;
        foreach ($this->tokens as $index => $token) {
            $offsets[$index] = $offset;
            $offset += \strlen($token->text);
        }

        return $offsets;
    }

    /** The index of the next token that is not whitespace or a comment. */
    private function next(int $index): ?int
    {
        $count = \count($this->tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            if (!$this->tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    private function endOfStatement(int $index): int
    {
        $count = \count($this->tokens);
        for ($i = $index; $i < $count; $i++) {
            if ($this->tokens[$i]->text === ';') {
                return $i;
            }
        }

        throw new RegistrationRefused('it has a use statement with no end');
    }

    private static function short(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    private static function namespaceOf(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? '' : substr($class, 0, $slash);
    }
}
