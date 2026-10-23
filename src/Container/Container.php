<?php

declare(strict_types=1);

namespace Tinvar\Container;

use Tinvar\Attributes\Dependency;

/**
 * Builds the application's objects: pages, layouts, queries, actions,
 * policies, middleware, and the services they take.
 *
 * Constructor parameters are autowired by type. A class marked
 * #[Dependency(Lifetime::Request)] or #[Dependency(Lifetime::Worker)] is
 * shared for that long; anything else is built fresh each time it is asked
 * for. Properties marked #[Dependency] are filled after construction, for a
 * class that cannot take a dependency in its constructor.
 *
 * Deliberately small. No configuration files, no tags, no decorators:
 * instances for what the framework already built (the Orm, the auth
 * context), bindings for interfaces, and autowiring for the rest. What it
 * cannot build is named with the chain of classes that needed it.
 */
final class Container
{
    /** @var array<class-string, object> registered or worker-lived instances */
    private array $shared = [];

    /** @var array<class-string, object> request-lived instances */
    private array $perRequest = [];

    /** @var array<class-string, \Closure(self): object> */
    private array $bindings = [];

    /** @var array<class-string, Lifetime> */
    private array $lifetimes = [];

    /** @var list<string> classes being built, outermost first */
    private array $building = [];

    /**
     * Registers an object the container hands out as-is.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param T $instance
     */
    public function instance(string $class, object $instance): self
    {
        $this->shared[$class] = $instance;

        return $this;
    }

    /**
     * Binds a class or interface to a factory, or to the class that
     * implements it.
     *
     * @param class-string $abstract
     * @param class-string|(\Closure(self): object) $concrete
     */
    public function bind(string $abstract, string|\Closure $concrete, Lifetime $lifetime = Lifetime::Transient): self
    {
        $this->bindings[$abstract] = \is_string($concrete)
            ? static fn (self $c): object => $c->get($concrete)
            : $concrete;
        $this->lifetimes[$abstract] = $lifetime;

        return $this;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        $object = $this->shared[$class] ?? $this->perRequest[$class] ?? $this->resolve($class);
        if (!$object instanceof $class) {
            throw ContainerException::wrongType($class, $object::class);
        }

        return $object;
    }

    /** Whether the container can build or hand out this class. */
    public function has(string $class): bool
    {
        return isset($this->shared[$class]) || isset($this->bindings[$class]) || class_exists($class);
    }

    /**
     * The container as the resolver closure the Kernel and the registries
     * take.
     *
     * @return \Closure(class-string): object
     */
    public function resolver(): \Closure
    {
        return function (string $class): object {
            if (!class_exists($class) && !interface_exists($class)) {
                throw ContainerException::unknownClass($class);
            }

            return $this->get($class);
        };
    }

    /**
     * Drops every request-lived instance. Part of the reset contract: the
     * Kernel calls it after each request.
     */
    public function endRequest(): void
    {
        $this->perRequest = [];
    }

    /** @param class-string $class */
    private function resolve(string $class): object
    {
        if (\in_array($class, $this->building, true)) {
            throw ContainerException::cycle($class, $this->building);
        }

        $this->building[] = $class;
        try {
            $lifetime = $this->lifetimeOf($class);
            $object = isset($this->bindings[$class]) ? ($this->bindings[$class])($this) : $this->build($class);
        } finally {
            array_pop($this->building);
        }

        match ($lifetime) {
            Lifetime::Worker => $this->shared[$class] = $object,
            Lifetime::Request => $this->perRequest[$class] = $object,
            Lifetime::Transient => null,
        };

        return $object;
    }

    /** @param class-string $class */
    private function lifetimeOf(string $class): Lifetime
    {
        if (isset($this->lifetimes[$class])) {
            return $this->lifetimes[$class];
        }

        $attribute = (new \ReflectionClass($class))->getAttributes(Dependency::class)[0] ?? null;

        return $this->lifetimes[$class] = $attribute?->newInstance()->lifetime ?? Lifetime::Transient;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function build(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw ContainerException::notInstantiable($class, \array_slice($this->building, 0, -1));
        }

        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[] = $this->argumentFor($class, $parameter);
        }

        $object = $reflection->newInstanceArgs($arguments);
        $this->injectProperties($reflection, $object);

        return $object;
    }

    private function argumentFor(string $class, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $parameter->getName();
        $path = \array_slice($this->building, 0, -1);

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();
            if (class_exists($dependency) || interface_exists($dependency)) {
                return $this->get($dependency);
            }

            throw ContainerException::unresolvable($class, $name, "is typed {$dependency}, which does not exist", $path);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($type === null || $type->allowsNull()) {
            return null;
        }

        throw ContainerException::unresolvable(
            $class,
            $name,
            "is {$type}, which the container cannot invent; give it a default or build {$class} yourself",
            $path,
        );
    }

    /**
     * Fills #[Dependency] properties, after the constructor has run.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function injectProperties(\ReflectionClass $reflection, object $object): void
    {
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Dependency::class) === [] || $property->isStatic()) {
                continue;
            }

            $type = $property->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw ContainerException::unresolvable(
                    $reflection->getName(),
                    $property->getName(),
                    'is a #[Dependency] property without a class type',
                    \array_slice($this->building, 0, -1),
                );
            }

            $dependency = $type->getName();
            if (!class_exists($dependency) && !interface_exists($dependency)) {
                throw ContainerException::unresolvable(
                    $reflection->getName(),
                    $property->getName(),
                    "is typed {$dependency}, which does not exist",
                    \array_slice($this->building, 0, -1),
                );
            }
            $property->setValue($object, $this->get($dependency));
        }
    }
}
