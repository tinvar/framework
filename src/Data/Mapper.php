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
 *   - a list of Data (`list<UnitData>` in the docblock): each element mapped;
 *   - `Lazy<T>`: the READ is deferred, not just the conversion, so a lazy
 *     relation is not loaded by mapping the object that holds it;
 *   - anything else: passed through as it is.
 *
 * #[MapsFrom(Rack::class)] on the Data class makes from() refuse any other
 * source, so a Data class cannot be built from the wrong model by accident.
 *
 * The plan for each class is compiled once.
 */
final class Mapper
{
    /** @var list<object|null> the sources being mapped, outermost first */
    private static array $mapping = [];

    /** @var array<class-string<Data>, array{maps: class-string|null, fields: list<array{name: string, source: string, kind: string, target: class-string<Data>|null, default: bool, nullable: bool}>}> */
    private static array $plans = [];

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

            $arguments[$field['name']] = $field['kind'] === 'lazy'
                ? self::deferred($class, $field, $source)
                : self::convert($class, $field, self::read($source, $field['source']));
        }

        return new $class(...$arguments);
    }

    /**
     * @param array{name: string, source: string, kind: string, target: class-string<Data>|null, default: bool, nullable: bool} $field
     * @param object|array<array-key, mixed> $source
     */
    private static function deferred(string $class, array $field, object|array $source): Lazy
    {
        // Nothing is read now -- reading a relation can load it, which is
        // what Lazy exists to put off. When resolved: read, unwrap a Lazy
        // the source already held, and convert. A Lazy<T> may hold one Data
        // object or a list of them; the value decides which.
        // Under the scope the mapping ran in, not whichever is in force when
        // the encoder gets to it: a relation mapped inside actAs('acme') and
        // read later under another tenant read that tenant's rows.
        $scope = $source instanceof Model ? $source->orm()?->captureScope() : null;

        return Lazy::of(static function () use ($class, $field, $source, $scope): mixed {
            if ($scope !== null) {
                return $scope(static fn (): mixed => self::resolveDeferred($class, $field, $source));
            }

            return self::resolveDeferred($class, $field, $source);
        });
    }

    /**
     * @param array{name: string, source: string, kind: string, target: class-string<Data>|null, default: bool, nullable: bool} $field
     * @param object|array<array-key, mixed> $source
     */
    private static function resolveDeferred(string $class, array $field, object|array $source): mixed
    {
        $value = self::read($source, $field['source']);

        return self::convert(
            $class,
            ['kind' => 'either'] + $field,
            $value instanceof Lazy ? $value->resolve() : $value,
        );
    }

    /**
     * @param array{name: string, source: string, kind: string, target: class-string<Data>|null, default: bool, nullable: bool} $field
     */
    private static function convert(string $class, array $field, mixed $value): mixed
    {
        if ($value === null || $field['target'] === null) {
            return $value;
        }

        $target = $field['target'];

        return match ($field['kind']) {
            'one' => $value instanceof $target ? $value : self::map($target, self::source($class, $field, $value)),
            'list' => self::mapList($class, $field, $target, $value),
            // Inside a Lazy: a list of Data, or one.
            default => \is_iterable($value) && !(\is_array($value) && !array_is_list($value))
                ? self::mapList($class, $field, $target, $value)
                : ($value instanceof $target ? $value : self::map($target, self::source($class, $field, $value))),
        };
    }

    /**
     * @param array{name: string} $field
     * @param class-string<Data> $target
     * @return list<Data>
     */
    private static function mapList(string $class, array $field, string $target, mixed $value): array
    {
        if (!is_iterable($value)) {
            throw DataMappingException::notIterable($class, $field['name'], get_debug_type($value));
        }

        $out = [];
        foreach ($value as $item) {
            $out[] = $item instanceof $target ? $item : self::map($target, self::source($class, $field, $item));
        }

        return $out;
    }

    /**
     * @param array{name: string} $field
     * @return object|array<array-key, mixed>
     */
    private static function source(string $class, array $field, mixed $value): object|array
    {
        if (\is_object($value) || \is_array($value)) {
            return $value;
        }

        throw DataMappingException::missing($class, $field['name'], $field['name'], get_debug_type($value));
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
     * @return array{maps: class-string|null, fields: list<array{name: string, source: string, kind: string, target: class-string<Data>|null, default: bool, nullable: bool}>}
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

            [$kind, $target] = match (true) {
                $typeName === Lazy::class => ['lazy', self::dataIn(self::lazyInner($hint), $reflection)],
                $typeName === 'array' => ['list', self::dataIn(self::elementOf($hint), $reflection)],
                $typeName !== null && is_subclass_of($typeName, Data::class) => ['one', $typeName],
                default => ['value', null],
            };

            $fields[] = [
                'name' => $name,
                'source' => $from ?? $name,
                'kind' => $kind,
                'target' => $target,
                'default' => $parameter->isDefaultValueAvailable(),
                'nullable' => $type?->allowsNull() ?? true,
            ];
        }

        return self::$plans[$class] = ['maps' => $maps, 'fields' => $fields];
    }

    /** The `@param T $name` type, or the sole `@var T`. */
    private static function hintFor(string $doc, ?string $name): ?string
    {
        if ($doc === '') {
            return null;
        }
        if ($name !== null && preg_match('/@param\s+(.+?)\s+\$' . preg_quote($name, '/') . '\b/', $doc, $m) === 1) {
            return trim($m[1]);
        }
        if ($name === null && preg_match('/@var\s+(.+?)(?:\s+\$|\s*\*\/|\R)/', $doc, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private static function lazyInner(?string $hint): ?string
    {
        return $hint !== null && preg_match('/^Lazy<(.+)>(?:\|null)?$/s', $hint, $m) === 1 ? $m[1] : null;
    }

    /** `list<X>`, `array<X>`, `array<K, X>`, `X[]` -> X; a bare `X` -> X. */
    private static function elementOf(?string $hint): ?string
    {
        if ($hint === null) {
            return null;
        }
        $hint = preg_replace('/\|null$|^null\|/', '', trim($hint)) ?? $hint;

        if (preg_match('/^(?:non-empty-)?(?:list|array)<(?:[^,<>]+,\s*)?(.+)>$/s', $hint, $m) === 1) {
            return trim($m[1]);
        }

        return str_ends_with($hint, '[]') ? substr($hint, 0, -2) : $hint;
    }

    /**
     * The Data class a hint names -- a list of one, or one -- resolved
     * against the declaring class's namespace, or null if it names none.
     *
     * @param \ReflectionClass<Data> $declaring
     * @return class-string<Data>|null
     */
    private static function dataIn(?string $hint, \ReflectionClass $declaring): ?string
    {
        $name = self::elementOf($hint);
        if ($name === null || !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', $name)) {
            return null;
        }

        foreach ([ltrim($name, '\\'), $declaring->getNamespaceName() . '\\' . $name] as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Data::class)) {
                return $candidate;
            }
        }

        return null;
    }
}
