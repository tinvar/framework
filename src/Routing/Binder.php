<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Data\Data;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Support\Str;

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

    /**
     * Builds the argument list for one loader.
     *
     * @param array<string, string> $parameters
     * @param array<class-string, Data> $parentData data from layouts already run
     * @param array<class-string, object> $provided objects to hand over by type
     * @return array<string, mixed>
     */
    public function argumentsFor(
        CompiledRoute $route,
        array $parameters,
        array $parentData,
        Request $request,
        array $provided = [],
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
                $data = self::parentDataOf($class, $parentData, $route, $parameter);
                $arguments[$name] = $data ?? ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null);
                continue;
            }

            if ($class === Request::class) {
                $arguments[$name] = $request;
                continue;
            }

            // What the caller hands over by type -- an error page's HttpError.
            if ($class !== null && isset($provided[$class])) {
                $arguments[$name] = $provided[$class];
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
     * The parent data a loader asks for by type, or null when none was
     * returned and the parameter says it can do without.
     *
     * "Loaders are optional" and a layout loader may return null -- a banner
     * only while one is scheduled -- so a child declaring `?BannerData
     * $banner = null` is saying exactly that. Refusing it turned the layout's
     * quiet day into a 500 on every page under it.
     *
     * @param array<class-string, Data> $parentData
     */
    private static function parentDataOf(
        string $class,
        array $parentData,
        CompiledRoute $route,
        \ReflectionParameter $parameter,
    ): ?Data {
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

        if ($parameter->allowsNull() || $parameter->isDefaultValueAvailable()) {
            return null;
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
     *
     * That includes a parameter of a bindable type that only the path can
     * fill and the route's template does not carry: `/racks` taking `Rack
     * $rack`, or `/units` taking `int $limit`. Judged against the route's
     * OWN template, for a layout too: a layout runs under every page below
     * it, so a model only some of them carry is optional -- `?Site $site =
     * null`, which the binder already hands null -- not required.
     */
    public static function assertBindable(CompiledRoute $route): void
    {
        foreach (self::loaderParameters($route->class) as $parameter) {
            $type = $parameter->getType();

            if ($parameter->isDefaultValueAvailable()) {
                continue;   // defaulted: the binder has an answer
            }

            if ($type === null || ($type instanceof \ReflectionNamedType && $type->getName() === 'mixed')) {
                self::assertCarried($route, $parameter, $type === null ? 'untyped' : 'mixed');
                continue;
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

                // A nullable model is handed null when the path lacks it; an
                // enum, like a scalar, has nothing but the path to come from.
                if (is_subclass_of($name, Model::class) && !$type->allowsNull()) {
                    self::assertCarried($route, $parameter, $name);
                }
                if (is_subclass_of($name, \BackedEnum::class)) {
                    self::assertCarried($route, $parameter, $name);
                }
                continue;
            }

            if (!\in_array($name, ['string', 'int', 'float', 'bool'], true)) {
                throw RoutingException::unbindableParameter($route->class, $parameter->getName(), $name);
            }

            self::assertCarried($route, $parameter, $name);
        }
    }

    /** Refuses a parameter only the path could fill, when the template has no segment for it. */
    private static function assertCarried(CompiledRoute $route, \ReflectionParameter $parameter, string $type): void
    {
        if (!\in_array($parameter->getName(), $route->parameters, true)) {
            throw RoutingException::unfillableParameter(
                $route->class,
                $route->template,
                $parameter->getName(),
                $type,
            );
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

    /**
     * A path segment as the parameter it fills is typed: a page's loader's,
     * or an endpoint's (Tinvar\Endpoints). A segment that is not one is a
     * path that does not exist, a 404.
     */
    public static function cast(string $value, ?\ReflectionType $type): string|int|float|bool|\BackedEnum
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();

        if (!$type->isBuiltin() && is_subclass_of($name, \BackedEnum::class)) {
            // A path segment outside the enum is a path that does not exist.
            // A segment is always a string, and under strict types an
            // int-backed enum's tryFrom() throws a TypeError on one, so it is
            // converted first -- or found not to be an integer at all.
            $intBacked = (new \ReflectionEnum($name))->getBackingType()?->getName() === 'int';

            return $name::tryFrom($intBacked ? self::toInt($value) : $value)
                ?? throw new NotFoundException('Unrecognised path segment.');
        }

        return match ($name) {
            'int' => self::toInt($value),
            'float' => self::toFloat($value),
            'bool' => self::toBool($value),
            default => $value,
        };
    }

    private static function toFloat(string $value): float
    {
        // '1e400' is numeric and comes out as INF, which no loader expects.
        $float = is_numeric($value) ? (float) $value : NAN;
        if (!is_finite($float)) {
            throw new NotFoundException('Path segment is not a number.');
        }

        return $float;
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
        // not a server error. So is one too long for an int: a cast clamped it
        // to PHP_INT_MAX or PHP_INT_MIN, a value the path never named.
        return Str::wholeNumber($value) ?? throw new NotFoundException('Path segment is not an integer.');
    }
}
