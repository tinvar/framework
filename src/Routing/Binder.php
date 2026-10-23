<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Data\Data;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * Route model binding, resolved through the identity map.
 *
 * "Resolve model arguments, and model-typed fields inside Data and form
 * requests, scoped to the tenant. A missing or foreign ID is a 404."
 *
 * Every model named by the chain is resolved once and shared, so a layout and
 * its page see the same instance and the row is read once.
 */
final class Binder
{
    /** @var array<string, Model> route parameter name => model */
    private array $models = [];

    public function __construct(private readonly Orm $orm) {}

    /**
     * Resolves every model-typed parameter the chain's loaders declare.
     *
     * Done before authorization, because a check needs its subject and a row
     * that is absent must 404 rather than reach a policy.
     *
     * @param array<string, string> $parameters
     * @return array<string, Model>
     */
    public function resolveChain(RouteChain $chain, array $parameters): array
    {
        foreach ($chain->all() as $route) {
            foreach (self::loaderParameters($route->class) as $parameter) {
                $type = self::classTypeOf($parameter);
                if ($type === null || !is_subclass_of($type, Model::class)) {
                    continue;
                }

                $name = $parameter->getName();
                if (isset($this->models[$name])) {
                    continue;
                }

                if (!\array_key_exists($name, $parameters)) {
                    // A layout shared across a route family may declare a model
                    // the current path does not carry. Optional means optional.
                    if ($parameter->allowsNull() || $parameter->isDefaultValueAvailable()) {
                        continue;
                    }

                    throw RoutingException::missingParameter($route->template, $name);
                }

                $this->models[$name] = $this->orm->find($type, $parameters[$name])
                    ?? throw NotFoundException::model($type, $parameters[$name]);
            }
        }

        return $this->models;
    }

    /**
     * Model inputs the chain's loaders declare optional.
     *
     * An #[Authorize] naming one of these does not apply when the path does
     * not carry it -- see Tinvar\Auth\Checks.
     *
     * @return list<string>
     */
    public static function optionalModelInputs(RouteChain $chain): array
    {
        $optional = [];

        foreach ($chain->all() as $route) {
            foreach (self::loaderParameters($route->class) as $parameter) {
                $type = self::classTypeOf($parameter);
                if ($type === null || !is_subclass_of($type, Model::class)) {
                    continue;
                }
                if ($parameter->allowsNull() || $parameter->isDefaultValueAvailable()) {
                    $optional[] = $parameter->getName();
                }
            }
        }

        return array_values(array_unique($optional));
    }

    /** @return array<string, Model> */
    public function models(): array
    {
        return $this->models;
    }

    /**
     * Builds the argument list for one loader.
     *
     * @param array<string, string> $parameters
     * @param array<class-string, Data> $parentData data from layouts already run
     * @return array<string, mixed>
     */
    public function argumentsFor(
        CompiledRoute $route,
        array $parameters,
        array $parentData,
        Request $request,
    ): array {
        $arguments = [];

        foreach (self::loaderParameters($route->class) as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $class = self::classTypeOf($parameter);

            if ($class !== null && is_subclass_of($class, Model::class)) {
                if (isset($this->models[$name])) {
                    $arguments[$name] = $this->models[$name];
                    continue;
                }
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[$name] = $parameter->getDefaultValue();
                    continue;
                }
                if ($parameter->allowsNull()) {
                    $arguments[$name] = null;
                    continue;
                }

                throw RoutingException::missingParameter($route->template, $name);
            }

            // "A child page or layout can receive a parent's data by type."
            // By type means instanceof: a parameter declared as a base class
            // or interface resolves to whatever the parent actually returned.
            if ($class !== null && (is_subclass_of($class, Data::class) || $class === Data::class)) {
                $arguments[$name] = self::parentDataOf($class, $parentData, $route);
                continue;
            }

            if ($class === Request::class) {
                $arguments[$name] = $request;
                continue;
            }

            if (\array_key_exists($name, $parameters)) {
                $arguments[$name] = self::cast($parameters[$name], $type);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
                continue;
            }

            throw new RoutingException(
                "Cannot bind \${$name} on {$route->class}: it is neither a route parameter, "
                . 'a model, a parent layout Data object, nor the Request.',
            );
        }

        return $arguments;
    }

    /**
     * @param array<class-string, Data> $parentData
     */
    private static function parentDataOf(string $class, array $parentData, CompiledRoute $route): Data
    {
        if (isset($parentData[$class])) {
            return $parentData[$class];
        }

        $matches = array_values(array_filter(
            $parentData,
            static fn (Data $data): bool => $data instanceof $class,
        ));

        if (\count($matches) === 1) {
            return $matches[0];
        }

        if ($matches !== []) {
            throw new RoutingException(
                "{$route->class} asks for {$class}, which matches " . \count($matches)
                . ' layouts above it. Ask for the exact class instead.',
            );
        }

        throw new RoutingException(
            "{$route->class} asks for {$class}, which no layout above it returned. "
            . 'A loader can only receive data from a layout that runs before it.',
        );
    }

    /**
     * Refuses a loader parameter the router could never bind.
     *
     * Checked at registration: left to runtime this surfaces as a TypeError
     * from deep inside the loader call, on the first request to that route.
     */
    public static function assertBindable(CompiledRoute $route): void
    {
        foreach (self::loaderParameters($route->class) as $parameter) {
            $type = $parameter->getType();

            if ($type === null || $parameter->isDefaultValueAvailable()) {
                continue;   // untyped or defaulted: the binder has an answer
            }

            if (!$type instanceof \ReflectionNamedType) {
                throw RoutingException::unbindableParameter($route->class, $parameter->getName(), (string) $type);
            }

            $name = $type->getName();

            if (!$type->isBuiltin()) {
                $bindable = is_subclass_of($name, Model::class)
                    || is_subclass_of($name, Data::class)
                    || is_subclass_of($name, \BackedEnum::class)
                    || $name === Request::class;

                if (!$bindable) {
                    throw RoutingException::unbindableParameter($route->class, $parameter->getName(), $name);
                }
                continue;
            }

            if (!\in_array($name, ['string', 'int', 'float', 'bool', 'mixed'], true)) {
                throw RoutingException::unbindableParameter($route->class, $parameter->getName(), $name);
            }
        }
    }

    /**
     * @param class-string $class
     * @return list<\ReflectionParameter>
     */
    public static function loaderParameters(string $class): array
    {
        $reflection = new \ReflectionClass($class);

        return $reflection->hasMethod('__invoke')
            ? $reflection->getMethod('__invoke')->getParameters()
            : [];
    }

    /** @return class-string|null */
    private static function classTypeOf(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        /** @var class-string */
        return $type->getName();
    }

    private static function cast(string $value, ?\ReflectionType $type): string|int|float|bool|\BackedEnum
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();

        if (!$type->isBuiltin() && is_subclass_of($name, \BackedEnum::class)) {
            // A path segment outside the enum is a path that does not exist.
            return $name::tryFrom($value) ?? throw new NotFoundException('Unrecognised path segment.');
        }

        return match ($name) {
            'int' => self::toInt($value),
            'float' => is_numeric($value)
                ? (float) $value
                : throw new NotFoundException('Path segment is not a number.'),
            'bool' => self::toBool($value),
            default => $value,
        };
    }

    private static function toBool(string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new NotFoundException('Path segment is not a boolean.'),
        };
    }

    private static function toInt(string $value): int
    {
        // A non-integer where an int is declared is a path that does not exist,
        // not a server error.
        if (preg_match('/^-?\d+\z/', $value) !== 1) {
            throw new NotFoundException('Path segment is not an integer.');
        }

        return (int) $value;
    }
}
