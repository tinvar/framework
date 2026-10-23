<?php

declare(strict_types=1);

namespace Tinvar\Orm;

use Tinvar\Attributes\BelongsTo;
use Tinvar\Attributes\Column;
use Tinvar\Attributes\HasMany;
use Tinvar\Attributes\Id;
use Tinvar\Attributes\Table;
use Tinvar\Attributes\TenantScoped;
use Tinvar\Support\Str;

/**
 * The metadata compiler. Reflection runs once per model and the result is cached
 * for the life of the worker; nothing here runs per request
 * (tinvar-spec.md, "Engine layers").
 */
final class Metadata
{
    /** @var array<class-string<Model>, ModelMeta> */
    private static array $compiled = [];

    /** @param class-string<Model> $class */
    public static function for(string $class): ModelMeta
    {
        return self::$compiled[$class] ??= self::compile($class);
    }

    /**
     * Every compiled relation whose target is $class: the relations a write
     * to one of its rows can change. Only compiled models are searched, which
     * is enough -- a relation of a model never compiled has loaded nothing.
     *
     * @param class-string<Model> $class
     * @return list<RelationMeta>
     */
    public static function relationsTo(string $class): array
    {
        $found = [];
        foreach (self::$compiled as $meta) {
            foreach ($meta->relations as $relation) {
                if ($relation->target === $class) {
                    $found[] = $relation;
                }
            }
        }

        return $found;
    }

    /** @param class-string<Model> $class */
    private static function compile(string $class): ModelMeta
    {
        $reflection = new \ReflectionClass($class);

        $table = $reflection->getAttributes(Table::class)[0] ?? null;
        if ($table === null) {
            throw MappingException::noTable($class);
        }
        $tenant = $reflection->getAttributes(TenantScoped::class)[0] ?? null;

        $columns = [];
        $relations = [];
        $idProperty = null;
        /** @var list<array{string, string, (\Closure(mixed): mixed)|null}> $plan */
        $plan = [];

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();

            if ($relation = self::relationFor($class, $property)) {
                $relations[$name] = $relation;
                continue;
            }

            $isId = $property->getAttributes(Id::class) !== [];
            $columnAttribute = $property->getAttributes(Column::class)[0] ?? null;
            if (!$isId && $columnAttribute === null) {
                continue;   // virtual properties, computed hooks and plain state are not columns
            }

            $column = $columnAttribute?->newInstance()->name ?? Str::snake($name);
            $columns[$name] = $column;
            $plan[] = [$name, $column, self::castFor($class, $property)];

            if ($isId) {
                $idProperty = $name;
            }
        }

        if ($idProperty === null) {
            throw MappingException::noId($class);
        }

        return new ModelMeta(
            class: $class,
            table: $table->newInstance()->name,
            idProperty: $idProperty,
            idColumn: $columns[$idProperty],
            columns: $columns,
            relations: $relations,
            tenantColumn: $tenant?->newInstance()->column,
            hydrator: self::compileHydrator($class, $plan),
            readKey: self::compileKeyReader($class, $idProperty),
            write: self::compileWriter($class),
            read: self::compileReader($class),
            columnReader: self::compileColumnReader($class, $plan),
        );
    }

    /**
     * One closure per model, bound to the model's own scope so ORM-owned
     * properties stay `private(set)` to everyone else. This is what the spec
     * means by a generated hydrator: no reflection on the hot path.
     *
     * @param class-string<Model> $class
     * @param list<array{string, string, (\Closure(mixed): mixed)|null}> $plan
     * @return \Closure(Model, array<string, mixed>): void
     */
    private static function compileHydrator(string $class, array $plan): \Closure
    {
        /** @var \Closure(Model, array<string, mixed>): void $bound */
        $bound = \Closure::bind(
            static function (Model $model, array $row) use ($plan): void {
                foreach ($plan as [$property, $column, $cast]) {
                    if (!\array_key_exists($column, $row)) {
                        continue;
                    }
                    $value = $row[$column];
                    $model->{$property} = ($cast === null || $value === null) ? $value : $cast($value);
                }
            },
            null,
            $class,
        );

        return $bound;
    }

    /**
     * @param class-string<Model> $class
     * @return \Closure(Model): string
     */
    private static function compileKeyReader(string $class, string $idProperty): \Closure
    {
        /** @var \Closure(Model): string $bound */
        $bound = \Closure::bind(
            static fn (Model $model): string => (string) $model->{$idProperty},
            null,
            $class,
        );

        return $bound;
    }

    /**
     * The inverse of the hydrator: reads each mapped property back out as the
     * value the database stores.
     *
     * Bound to the model's scope like the hydrator, so private(set) fields are
     * readable here without being readable anywhere else.
     *
     * @param class-string<Model> $class
     * @param list<array{string, string, (\Closure(mixed): mixed)|null}> $plan
     * @return \Closure(Model): array<string, mixed>
     */
    private static function compileColumnReader(string $class, array $plan): \Closure
    {
        /** @var \Closure(Model): array<string, mixed> $bound */
        $bound = \Closure::bind(
            static function (Model $model) use ($plan): array {
                $values = [];
                foreach ($plan as [$property, $column, $_cast]) {
                    $reflection = new \ReflectionProperty($model, $property);
                    if (!$reflection->isInitialized($model)) {
                        continue;
                    }

                    $value = $model->{$property};
                    $values[$column] = match (true) {
                        // In UTC, to the microsecond, with the offset spelled
                        // out. 'Y-m-d H:i:s' wrote the wall-clock time in
                        // whatever zone the value carried and dropped the
                        // fraction, so a New York time came back hours off
                        // and truncated. The offset is what a timestamptz
                        // column needs to take the instant rather than the
                        // session zone's reading of it; a timestamp column
                        // ignores it, and gets the UTC time.
                        $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                            ->setTimezone(new \DateTimeZone('UTC'))
                            ->format('Y-m-d H:i:s.uP'),
                        $value instanceof \BackedEnum => $value->value,
                        \is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                        \is_bool($value) => $value ? 1 : 0,
                        default => $value,
                    };
                }

                return $values;
            },
            null,
            $class,
        );

        return $bound;
    }

    /**
     * @param class-string<Model> $class
     * @return \Closure(Model, string, mixed): void
     */
    private static function compileWriter(string $class): \Closure
    {
        /** @var \Closure(Model, string, mixed): void $bound */
        $bound = \Closure::bind(
            static function (Model $model, string $property, mixed $value): void {
                $model->{$property} = $value;
            },
            null,
            $class,
        );

        return $bound;
    }

    /**
     * @param class-string<Model> $class
     * @return \Closure(Model, string): mixed
     */
    private static function compileReader(string $class): \Closure
    {
        /** @var \Closure(Model, string): mixed $bound */
        $bound = \Closure::bind(
            static fn (Model $model, string $property): mixed => $model->{$property},
            null,
            $class,
        );

        return $bound;
    }

    /**
     * A relation's target, which the attribute declares as any class name,
     * checked to be a model when the model is compiled rather than when the
     * relation is first read.
     *
     * @param class-string<Model> $class
     * @return class-string<Model>
     */
    private static function modelTarget(string $class, \ReflectionProperty $property, string $target): string
    {
        if (!is_subclass_of($target, Model::class)) {
            throw MappingException::relationTargetNotModel($class, $property->getName(), $target);
        }

        return $target;
    }

    /** @param class-string<Model> $class */
    private static function relationFor(string $class, \ReflectionProperty $property): ?RelationMeta
    {
        if ($hasMany = ($property->getAttributes(HasMany::class)[0] ?? null)) {
            $attribute = $hasMany->newInstance();

            return new RelationMeta(
                kind: RelationKind::HasMany,
                owner: $class,
                property: $property->getName(),
                target: self::modelTarget($class, $property, $attribute->target),
                foreignKey: $attribute->foreignKey ?? Str::snake(Str::shortName($class)) . '_id',
                orderBy: $attribute->orderBy,
            );
        }

        if ($belongsTo = ($property->getAttributes(BelongsTo::class)[0] ?? null)) {
            $attribute = $belongsTo->newInstance();
            $target = $attribute->target ?? self::typeOf($property);
            if ($target === null || !is_subclass_of($target, Model::class)) {
                throw MappingException::untypedRelation($class, $property->getName());
            }

            return new RelationMeta(
                kind: RelationKind::BelongsTo,
                owner: $class,
                property: $property->getName(),
                target: $target,
                foreignKey: $attribute->foreignKey ?? Str::snake(Str::shortName($target)) . '_id',
            );
        }

        return null;
    }

    /** @return class-string|null */
    private static function typeOf(\ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return class_exists($name) || interface_exists($name) ? $name : null;
    }

    /**
     * @param class-string<Model> $class
     * @return (\Closure(mixed): mixed)|null
     */
    private static function castFor(string $class, \ReflectionProperty $property): ?\Closure
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        return match ($type->getName()) {
            'string' => static fn (mixed $v): string => (string) $v,
            'int' => static fn (mixed $v): int => (int) $v,
            'float' => static fn (mixed $v): float => (float) $v,
            'bool' => static fn (mixed $v): bool => (bool) $v,
            'array' => static fn (mixed $v): array => \is_array($v)
                ? $v
                : (json_decode((string) $v, true, flags: JSON_THROW_ON_ERROR) ?: []),
            // As UTC, the zone the writer stores. A value with no offset -- a
            // Postgres timestamp, or a row written before the offset was --
            // was read in the default zone; one with an offset is converted.
            \DateTimeImmutable::class => static fn (mixed $v): \DateTimeImmutable => $v instanceof \DateTimeImmutable
                ? $v
                : (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC')),
            'mixed' => null,
            default => throw MappingException::unsupportedColumnType($class, $property->getName(), $type->getName()),
        };
    }
}
