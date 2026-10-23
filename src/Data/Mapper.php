<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Attributes\From;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Orm\Model;

/**
 * Builds a Data object from a model, another object or an array.
 *
 * tinvar-spec.md, "Data boundary": `RackData::from($rack); // same-named
 * properties map automatically`, with `#[From('units')]` naming a source
 * property that differs. Each constructor parameter is filled from the
 * source property of the same name, converted by its declared type:
 *
 *   - a Data class: mapped from the source value, recursively;
 *   - a list of Data (`list<UnitData>` in the docblock): each element mapped,
 *     and an `array<string, UnitData>` keeps its keys;
 *   - `Lazy<T>`: the READ is deferred, not just the conversion, so a lazy
 *     relation is not loaded by mapping the object that holds it, and it is
 *     read later under the scope the mapping ran in;
 *   - anything else: passed through as it is.
 *
 * Docblock names resolve as PHP would: fully qualified, through the file's
 * `use` imports, or in the class's own namespace. A name that resolves to
 * nothing is an error when the plan is built, not a model passed through
 * to fail at the encoder far from its cause.
 *
 * #[MapsFrom(Rack::class)] on the Data class makes from() refuse an object
 * of any other class. An array source is always accepted: it is data the
 * caller assembled, and only its declared keys are read.
 *
 * The plan for each class is compiled once.
 *
 * @phpstan-type Field array{
 *     name: string,
 *     source: string,
 *     kind: 'one'|'list'|'lazy-one'|'lazy-list'|'value',
 *     target: class-string<Data>|null,
 *     keyed: bool,
 *     default: bool,
 *     nullable: bool,
 * }
 * @phpstan-type Plan array{maps: class-string|null, fields: list<Field>}
 */
final class Mapper
{
    /** @var list<object|null> the sources being mapped, outermost first */
    private static array $mapping = [];

    /** @var array<class-string<Data>, Plan> */
    private static array $plans = [];

    /** @var array<string, array<string, array<string, string>>> file => lowercased namespace => lowercased alias => imported name */
    private static array $imports = [];

    /** PHPDoc type names that are not classes. */
    private const array PSEUDO_TYPES = [
        'string', 'int', 'integer', 'float', 'double', 'bool', 'boolean', 'true', 'false', 'null', 'mixed',
        'array', 'list', 'iterable', 'object', 'callable', 'scalar', 'numeric', 'array-key', 'non-empty-string',
        'positive-int', 'negative-int', 'non-negative-int', 'class-string', 'resource', 'void', 'never',
    ];

    /**
     * @template T of Data
     * @param class-string<T> $class
     * @param object|array<array-key, mixed> $source
     * @return T
     */
    public static function map(string $class, object|array $source): Data
    {
        // A cyclic source -- a parent and child relation pair, which the
        // identity map makes one object graph -- recursed until PHP ran out
        // of memory: a fatal error, uncatchable, taking the worker down with
        // every request it held. A source already being mapped, or a graph
        // deeper than the encoder would ever write, is refused instead.
        if (\count(self::$mapping) >= Encoder::MAX_DEPTH) {
            throw DataMappingException::tooDeep($class, Encoder::MAX_DEPTH);
        }
        if (\is_object($source)) {
            foreach (self::$mapping as $active) {
                if ($active === $source) {
                    throw DataMappingException::cycle($class, $source::class);
                }
            }
        }

        self::$mapping[] = \is_object($source) ? $source : null;
        try {
            return self::mapOne($class, $source);
        } finally {
            array_pop(self::$mapping);
        }
    }

    /**
     * @template T of Data
     * @param class-string<T> $class
     * @param object|array<array-key, mixed> $source
     * @return T
     */
    private static function mapOne(string $class, object|array $source): Data
    {
        $plan = self::planFor($class);

        if ($plan['maps'] !== null && \is_object($source) && !$source instanceof $plan['maps']) {
            throw DataMappingException::wrongSource($class, $plan['maps'], $source::class);
        }

        $arguments = [];
        foreach ($plan['fields'] as $field) {
            if (!self::has($source, $field['source'])) {
                if ($field['default']) {
                    continue;
                }
                if ($field['nullable']) {
                    $arguments[$field['name']] = null;
                    continue;
                }
                throw DataMappingException::missing($class, $field['name'], $field['source'], get_debug_type($source));
            }

            $arguments[$field['name']] = str_starts_with($field['kind'], 'lazy-')
                ? self::deferred($class, $field, $source)
                : self::convert($class, $field, self::read($source, $field['source']));
        }

        return new $class(...$arguments);
    }

    /**
     * @param Field $field
     * @param object|array<array-key, mixed> $source
     */
    private static function deferred(string $class, array $field, object|array $source): Lazy
    {
        // Nothing is read now -- reading a relation can load it, which is
        // what Lazy exists to put off. And it is read under the scope the
        // mapping ran in, not whichever is in force when the encoder gets
        // to it: a relation mapped inside actAs('acme') and read later under
        // another tenant read that tenant's rows.
        $orm = $source instanceof Model ? $source->orm() : null;
        $resolve = static function () use ($class, $field, $source): mixed {
            $value = self::read($source, $field['source']);

            return self::convert(
                $class,
                ['kind' => $field['kind'] === 'lazy-list' ? 'list' : 'one'] + $field,
                $value instanceof Lazy ? $value->resolve() : $value,
            );
        };

        if ($orm === null) {
            return Lazy::of($resolve);
        }

        // Mapped inside acrossTenants(): read now, inside the escape hatch.
        // Deferred, the read would lift tenant scoping again wherever the
        // encoder happened to resolve it -- outside the block that opened it.
        if ($orm->isUnscoped()) {
            return Lazy::value($resolve());
        }

        $scope = $orm->captureScope();

        return Lazy::of(static fn (): mixed => $scope($resolve));
    }

    /** @param Field $field */
    private static function convert(string $class, array $field, mixed $value): mixed
    {
        if ($value === null || $field['target'] === null) {
            return $value;
        }

        $target = $field['target'];

        return $field['kind'] === 'list'
            ? self::mapList($class, $field, $target, $value)
            : ($value instanceof $target ? $value : self::map($target, self::source($class, $field, $value)));
    }

    /**
     * @param Field $field
     * @param class-string<Data> $target
     * @return array<array-key, Data>
     */
    private static function mapList(string $class, array $field, string $target, mixed $value): array
    {
        if (!is_iterable($value)) {
            throw DataMappingException::notIterable($class, $field['name'], get_debug_type($value));
        }

        $out = [];
        foreach ($value as $key => $item) {
            $mapped = $item instanceof $target ? $item : self::map($target, self::source($class, $field, $item));
            // An array<string, T> keeps its keys; a list<T> is renumbered.
            if ($field['keyed'] && (\is_int($key) || \is_string($key))) {
                $out[$key] = $mapped;
            } else {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param Field $field
     * @return object|array<array-key, mixed>
     */
    private static function source(string $class, array $field, mixed $value): object|array
    {
        if (\is_object($value) || \is_array($value)) {
            return $value;
        }

        throw DataMappingException::notMappable($class, $field['name'], get_debug_type($value));
    }

    /** @param object|array<array-key, mixed> $source */
    private static function has(object|array $source, string $name): bool
    {
        if (\is_array($source)) {
            return \array_key_exists($name, $source);
        }

        // A declared property is a source only if it is public -- every
        // Model has private $orm and $original, which a Data field of that
        // name hit as "Cannot access private property" -- and holds a value:
        // a typed property never set threw "must not be accessed before
        // initialization" instead of letting the field take its default.
        // A hooked, virtual property computes its value and always counts.
        // None of this reads the property, so a relation is not loaded.
        if (property_exists($source, $name)) {
            $property = new \ReflectionProperty($source, $name);
            if (!$property->isPublic()) {
                return false;
            }

            return $property->isVirtual() || $property->isInitialized($source);
        }

        // Undeclared: dynamic properties, or __get/__isset magic.
        return isset($source->{$name});
    }

    /** @param object|array<array-key, mixed> $source */
    private static function read(object|array $source, string $name): mixed
    {
        return \is_array($source) ? $source[$name] : $source->{$name};
    }

    /**
     * @param class-string<Data> $class
     * @return Plan
     */
    private static function planFor(string $class): array
    {
        if (isset(self::$plans[$class])) {
            return self::$plans[$class];
        }

        $reflection = new \ReflectionClass($class);
        $maps = ($reflection->getAttributes(MapsFrom::class)[0] ?? null)?->newInstance()->model;
        $constructor = $reflection->getConstructor();
        $doc = $constructor?->getDocComment() ?: '';
        $fields = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $property = $reflection->hasProperty($name) ? $reflection->getProperty($name) : null;

            $from = ($parameter->getAttributes(From::class)[0] ?? $property?->getAttributes(From::class)[0] ?? null)
                ?->newInstance()->property;

            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            $hint = self::hintFor($doc, $name) ?? self::hintFor($property?->getDocComment() ?: '', null);
            $where = "{$class}::\${$name}";

            [$kind, $target, $keyed] = match (true) {
                $typeName === Lazy::class => self::lazyShape($hint, $reflection, $where),
                $typeName === 'array' => ['list', self::dataIn(self::elementOf($hint), $reflection, $where), self::isKeyed($hint)],
                $typeName !== null && is_subclass_of($typeName, Data::class) => ['one', $typeName, false],
                default => ['value', null, false],
            };

            $fields[] = [
                'name' => $name,
                'source' => $from ?? $name,
                'kind' => $kind,
                'target' => $target,
                'keyed' => $keyed,
                'default' => $parameter->isDefaultValueAvailable(),
                'nullable' => $type?->allowsNull() ?? true,
            ];
        }

        return self::$plans[$class] = ['maps' => $maps, 'fields' => $fields];
    }

    /**
     * Whether a Lazy holds a list or one value comes from its declared type.
     * Guessed from the value, an iterable Data source was taken for a list
     * and a keyed array of Data for one object.
     *
     * @param \ReflectionClass<Data> $declaring
     * @return array{'lazy-one'|'lazy-list', class-string<Data>|null, bool}
     */
    private static function lazyShape(?string $hint, \ReflectionClass $declaring, string $where): array
    {
        $inner = self::withoutNull(self::lazyInner($hint));
        $isList = $inner !== null && self::elementOf($inner) !== $inner;

        return [
            $isList ? 'lazy-list' : 'lazy-one',
            self::dataIn(self::elementOf($inner), $declaring, $where),
            $isList && self::isKeyed($inner),
        ];
    }

    /** The `@param T $name` type, or the sole `@var T`, read one tag at a time. */
    private static function hintFor(string $doc, ?string $name): ?string
    {
        if ($doc === '') {
            return null;
        }

        // Line by line: a pattern across the whole block let a description
        // that mentions `$name` on another line claim that line's type.
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            if ($name !== null && preg_match('/@param\s+(.+?)\s+(?:\.\.\.)?\$(\w+)\b/', $line, $m) === 1 && $m[2] === $name) {
                return trim($m[1]);
            }
            if ($name === null && preg_match('/@var\s+(.+?)(?:\s+\$\w+)?\s*(?:\*\/)?\s*$/', $line, $m) === 1) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private static function lazyInner(?string $hint): ?string
    {
        return $hint !== null && preg_match('/^Lazy<(.+)>$/s', self::withoutNull($hint) ?? '', $m) === 1 ? $m[1] : null;
    }

    private static function withoutNull(?string $hint): ?string
    {
        return $hint === null ? null : (preg_replace('/\|null$|^null\|/', '', trim($hint)) ?? $hint);
    }

    /** `array<K, X>` keeps keys; `list<X>`, `array<X>` and `X[]` do not. */
    private static function isKeyed(?string $hint): bool
    {
        return $hint !== null && preg_match('/^(?:non-empty-)?array<[^,<>]+,/', self::withoutNull($hint) ?? '') === 1;
    }

    /** `list<X>`, `array<X>`, `array<K, X>`, `X[]` -> X; a bare `X` -> X. */
    private static function elementOf(?string $hint): ?string
    {
        $hint = self::withoutNull($hint);
        if ($hint === null) {
            return null;
        }

        if (preg_match('/^(?:non-empty-)?(?:list|array)<(?:[^,<>]+,\s*)?(.+)>$/s', $hint, $m) === 1) {
            return trim($m[1]);
        }

        return str_ends_with($hint, '[]') ? substr($hint, 0, -2) : $hint;
    }

    /**
     * The Data class a hint names -- a list of one, or one -- resolved as
     * PHP resolves names, or null if it names a scalar or a non-Data class.
     * A name that resolves to nothing at all is refused.
     *
     * @param \ReflectionClass<Data> $declaring
     * @return class-string<Data>|null
     */
    private static function dataIn(?string $hint, \ReflectionClass $declaring, string $where): ?string
    {
        $name = self::elementOf($hint);
        if ($name === null || !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $name)) {
            return null;
        }
        if (\in_array(strtolower($name), self::PSEUDO_TYPES, true)) {
            return null;
        }

        $resolved = self::resolveName($name, $declaring);
        if (!class_exists($resolved) && !interface_exists($resolved) && !enum_exists($resolved)) {
            throw DataMappingException::unknownType($where, $name);
        }

        return is_subclass_of($resolved, Data::class) ? $resolved : null;
    }

    /** @param \ReflectionClass<Data> $declaring */
    private static function resolveName(string $name, \ReflectionClass $declaring): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $parts = explode('\\', $name, 2);
        $first = $parts[0];
        $rest = $parts[1] ?? null;
        $imports = self::importsOf($declaring->getFileName() ?: '', $declaring->getNamespaceName());
        if (isset($imports[strtolower($first)])) {
            return $imports[strtolower($first)] . ($rest === null ? '' : '\\' . $rest);
        }

        $namespace = $declaring->getNamespaceName();

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * The class imports in effect in one namespace of a file: `use A\B\C;`
     * and `use A\B\C as D;`, including grouped `use A\B\{C, D as E};`.
     * Function and constant imports are skipped, and so are a class's trait
     * uses and a closure's `use (...)`.
     *
     * Kept per namespace, because PHP keeps them per namespace: a file with
     * two namespace blocks has two import tables, and a braced
     * `namespace X { use ...; }` imports one level down.
     *
     * @return array<string, string> lowercased alias => imported name
     */
    private static function importsOf(string $file, string $namespace): array
    {
        if ($file === '' || !is_file($file)) {
            return [];
        }

        return (self::$imports[$file] ??= self::parseImports($file))[strtolower($namespace)] ?? [];
    }

    /** @return array<string, array<string, string>> lowercased namespace => lowercased alias => imported name */
    private static function parseImports(string $file): array
    {
        $imports = [];
        $tokens = \PhpToken::tokenize((string) file_get_contents($file));
        $count = \count($tokens);
        $namespace = '';
        $depth = 0;
        $top = 0;          // the depth of a namespace's own statements
        $previous = null;  // the last significant token

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->isIgnorable()) {
                continue;
            }

            if ($token->id === T_NAMESPACE && $depth === 0) {
                $name = '';
                for ($i++; $i < $count && $tokens[$i]->text !== ';' && $tokens[$i]->text !== '{'; $i++) {
                    $name .= $tokens[$i]->isIgnorable() ? '' : $tokens[$i]->text;
                }
                $namespace = strtolower(ltrim($name, '\\'));
                if (($tokens[$i]->text ?? ';') === '{') {
                    $depth = $top = 1;
                } else {
                    $top = 0;
                }
                $previous = $tokens[$i] ?? null;

                continue;
            }

            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
                if ($depth === 0 && $top === 1) {
                    $namespace = '';
                    $top = 0;
                }
            }

            // An import starts a statement. A closure's `use` follows its
            // parameter list's `)`, and a trait `use` sits in a class body.
            $import = $token->id === T_USE && $depth === $top && $previous?->text !== ')';
            $previous = $token;
            if (!$import) {
                continue;
            }

            $statement = '';
            for ($i++; $i < $count && $tokens[$i]->text !== ';'; $i++) {
                $statement .= $tokens[$i]->text;
            }
            $statement = trim($statement);
            if (preg_match('/^(function|const)\s/i', $statement) === 1) {
                continue;
            }

            foreach (self::expandUse($statement) as $alias => $imported) {
                $imports[$namespace][strtolower($alias)] = $imported;
            }
        }

        return $imports;
    }

    /** @return array<string, string> alias => imported name, for one use statement */
    private static function expandUse(string $statement): array
    {
        $out = [];
        if (preg_match('/^([^{]+)\{(.+)\}$/s', $statement, $group) === 1) {
            $prefix = rtrim(trim($group[1]), '\\');
            foreach (explode(',', $group[2]) as $part) {
                // A group may end in a trailing comma.
                if (trim($part) !== '') {
                    $out += self::expandUse($prefix . '\\' . trim($part));
                }
            }

            return $out;
        }

        foreach (explode(',', $statement) as $part) {
            $part = trim($part);
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $part, $m) === 1) {
                $out[$m[2]] = ltrim(trim($m[1]), '\\');
            } else {
                $name = ltrim($part, '\\');
                $out[substr($name, (int) strrpos('\\' . $name, '\\'))] = $name;
            }
        }

        return $out;
    }
}
