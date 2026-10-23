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
                    throw RoutingException::missingParameter($route->template, $name);
                }

                $this->models[$name] = $this->orm->find($type, $parameters[$name])
                    ?? throw NotFoundException::model($type, $parameters[$name]);
            }
        }

        return $this->models;
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
                $arguments[$name] = $this->models[$name]
                    ?? throw RoutingException::missingParameter($route->template, $name);
                continue;
            }

            // "A child page or layout can receive a parent's data by type."
            if ($class !== null && is_subclass_of($class, Data::class)) {
                if (!isset($parentData[$class])) {
                    throw new RoutingException(
                        "{$route->class} asks for {$class}, which no layout above it returned. "
                        . 'A loader can only receive data from a layout that runs before it.'
                    );
                }
                $arguments[$name] = $parentData[$class];
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
                . 'a model, a parent layout Data object, nor the Request.'
            );
        }

        return $arguments;
    }

    /** @return list<\ReflectionParameter> */
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

    private static function cast(string $value, ?\ReflectionType $type): string|int|float
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => self::toInt($value),
            'float' => is_numeric($value) ? (float) $value : throw new NotFoundException("'{$value}' is not a number."),
            default => $value,
        };
    }

    private static function toInt(string $value): int
    {
        // A non-integer where an int is declared is a path that does not exist,
        // not a server error.
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new NotFoundException("'{$value}' is not an integer.");
        }

        return (int) $value;
    }
}
