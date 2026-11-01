<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * What a Queueable's arguments become in a job's payload, and back.
 *
 * Data only, and typed: JSON scalars as they are, and every other value as
 * an object with a single key saying what it is -- an array as its pairs, a
 * backed enum as its class and value, a date as its ISO 8601 text, a model
 * as its class and id, a nested Queueable as its class and arguments. So
 * nothing in a payload is ambiguous, and nothing is decoded by
 * unserialize(): the renderer boundary's rule ("decodes to arrays and
 * scalars only, never PHP objects") held for the queue too, since a row in
 * a jobs table is as much outside input as a socket frame.
 *
 * Refused at dispatch rather than at run: a closure, a resource, a mutable
 * date or an arbitrary object cannot travel, and finding that out on a
 * worker an hour later -- after the request that dispatched it has long
 * answered success -- is the expensive way to learn it.
 *
 * A model is read again when the job runs, inside the job's tenant, so it
 * is the row as it is then, scoped as the dispatcher's read was; one that
 * has gone refuses the job.
 */
final class Payload
{
    /** How deep a value may nest; a payload is a record, not a tree. */
    public const int MAX_DEPTH = 16;

    /**
     * A Queueable's constructor arguments, encoded, by parameter name.
     *
     * @return array<string, mixed>
     */
    public static function encodeArguments(Queueable $object): array
    {
        return self::arguments($object, 0);
    }

    /**
     * A Queueable rebuilt from its class and encoded arguments.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function construct(string $class, array $arguments, Orm $orm): Queueable
    {
        return self::build($class, $arguments, $orm, 0);
    }

    /** @return array<string, mixed> */
    private static function arguments(Queueable $object, int $depth): array
    {
        $reflection = new \ReflectionClass($object);
        $encoded = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if ($parameter->isVariadic()) {
                throw self::refuse($object::class, $name, 'is variadic, and only named arguments travel');
            }
            if (!$reflection->hasProperty($name)) {
                throw self::refuse($object::class, $name, 'has no property of the same name to be read back from');
            }

            $property = $reflection->getProperty($name);
            if ($property->isStatic() || !$property->isInitialized($object)) {
                throw self::refuse($object::class, $name, 'has no value on the object to send');
            }

            $encoded[$name] = self::encode($property->getValue($object), $object::class . "::\${$name}", $depth + 1);
        }

        return $encoded;
    }

    private static function encode(mixed $value, string $where, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException("{$where} nests deeper than a job's payload may.");
        }

        return match (true) {
            $value === null, \is_bool($value), \is_int($value), \is_string($value) => $value,
            \is_float($value) => is_finite($value)
                ? $value
                : throw new \InvalidArgumentException("{$where} is not a finite number, which JSON cannot carry."),
            \is_array($value) => ['a' => array_map(
                static fn (int|string $key): array => [$key, self::encode($value[$key], "{$where}[{$key}]", $depth + 1)],
                array_keys($value),
            )],
            $value instanceof \BackedEnum => ['e' => [$value::class, $value->value]],
            $value instanceof \DateTimeImmutable => ['d' => $value->format('Y-m-d\TH:i:s.uP')],
            $value instanceof Model => ['m' => [$value::class, self::keyOf($value, $where)]],
            $value instanceof Queueable => ['o' => [$value::class, self::arguments($value, $depth)]],
            default => throw new \InvalidArgumentException(
                "{$where} is " . get_debug_type($value) . ', which cannot travel in a job: send scalars, arrays, '
                . 'backed enums, DateTimeImmutable, models or Queueable objects.',
            ),
        };
    }

    private static function keyOf(Model $model, string $where): string
    {
        // A model with no row has nothing to be read back by, and one only
        // in memory would arrive as whatever its id named by then -- or as
        // nothing.
        if (!Orm::holdsRow($model)) {
            throw new \InvalidArgumentException("{$where} is a " . $model::class . ' that has not been saved; save it before dispatching.');
        }

        return $model->primaryKey();
    }

    /** @param array<array-key, mixed> $arguments */
    private static function build(string $class, array $arguments, Orm $orm, int $depth): Queueable
    {
        if (!class_exists($class) || !is_subclass_of($class, Queueable::class)) {
            throw new JobRefused('unknown_class', "A payload names {$class}, which is not Queueable.");
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new JobRefused('unknown_class', "A payload names {$class}, which cannot be built.");
        }

        $decoded = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if (!\array_key_exists($name, $arguments)) {
                // A deploy between dispatch and run that added a parameter:
                // its default if it has one, and otherwise the job cannot be
                // rebuilt as it now is.
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                throw new JobRefused('payload_mismatch', "{$class} now takes \${$name}, which the payload does not carry.");
            }
            $decoded[$name] = self::asDeclared(self::decode($arguments[$name], $orm, $depth + 1), $parameter);
        }

        try {
            $object = $reflection->newInstanceArgs($decoded);
        } catch (\TypeError $e) {
            throw new JobRefused('payload_mismatch', "{$class} could not be rebuilt from its payload: {$e->getMessage()}", $e);
        }

        return $object;
    }

    /**
     * A date travels as its text and comes back a DateTimeImmutable, so a
     * parameter typed by a subclass of one -- CarbonImmutable, a model
     * moment's type (ADR 0145) -- gets the same instant as that class.
     */
    private static function asDeclared(mixed $value, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if (!$value instanceof \DateTimeImmutable || !$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }
        $class = $type->getName();
        if ($value instanceof $class || !is_subclass_of($class, \DateTimeImmutable::class)) {
            return $value;
        }

        return $class::createFromInterface($value);
    }

    private static function decode(mixed $value, Orm $orm, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new JobRefused('payload_mismatch', 'A payload nests deeper than a job\'s payload may.');
        }
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if (!\is_array($value) || \count($value) !== 1) {
            throw new JobRefused('payload_mismatch', 'A payload holds a value it could not have been encoded as.');
        }

        $kind = array_key_first($value);
        $body = $value[$kind];

        return match ($kind) {
            'a' => self::decodeArray($body, $orm, $depth),
            'e' => self::decodeEnum($body),
            'd' => \is_string($body) && ($date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $body)) !== false
                ? $date
                : throw new JobRefused('payload_mismatch', 'A payload holds a date that does not parse.'),
            'm' => self::decodeModel($body, $orm),
            'o' => \is_array($body) && \count($body) === 2 && \is_string($body[0] ?? null) && \is_array($body[1] ?? null)
                ? self::build($body[0], $body[1], $orm, $depth)
                : throw new JobRefused('payload_mismatch', 'A payload holds an object it could not have been encoded as.'),
            default => throw new JobRefused('payload_mismatch', 'A payload holds a value it could not have been encoded as.'),
        };
    }

    /** @return array<array-key, mixed> */
    private static function decodeArray(mixed $pairs, Orm $orm, int $depth): array
    {
        if (!\is_array($pairs)) {
            throw new JobRefused('payload_mismatch', 'A payload holds an array it could not have been encoded as.');
        }

        $out = [];
        foreach ($pairs as $pair) {
            if (!\is_array($pair) || \count($pair) !== 2 || !(\is_int($pair[0] ?? null) || \is_string($pair[0] ?? null))) {
                throw new JobRefused('payload_mismatch', 'A payload holds an array it could not have been encoded as.');
            }
            $out[$pair[0]] = self::decode($pair[1], $orm, $depth + 1);
        }

        return $out;
    }

    private static function decodeEnum(mixed $body): \BackedEnum
    {
        $class = \is_array($body) ? ($body[0] ?? null) : null;
        $case = \is_array($body) ? ($body[1] ?? null) : null;
        if (!\is_string($class) || !is_subclass_of($class, \BackedEnum::class) || !(\is_int($case) || \is_string($case))) {
            throw new JobRefused('payload_mismatch', 'A payload holds an enum it could not have been encoded as.');
        }

        return $class::tryFrom($case)
            ?? throw new JobRefused('payload_mismatch', "{$class} no longer has the case the payload names.");
    }

    private static function decodeModel(mixed $body, Orm $orm): Model
    {
        $class = \is_array($body) ? ($body[0] ?? null) : null;
        $id = \is_array($body) ? ($body[1] ?? null) : null;
        if (!\is_string($class) || !is_subclass_of($class, Model::class) || !\is_string($id)) {
            throw new JobRefused('payload_mismatch', 'A payload holds a model it could not have been encoded as.');
        }

        return $orm->find($class, $id)
            ?? throw new JobRefused('model_missing', "The {$class} the job was dispatched with is gone, or not this tenant's.");
    }

    private static function refuse(string $class, string $parameter, string $why): \InvalidArgumentException
    {
        return new \InvalidArgumentException("{$class} cannot travel in a job: constructor parameter \${$parameter} {$why}.");
    }
}
