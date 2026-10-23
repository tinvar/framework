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

    public function __construct()
    {
        // Something that takes the container gets this one, with everything
        // registered on it -- not a new, empty one autowired from nothing.
        $this->shared[self::class] = $this;
    }

    /** @var list<Lifetime> the lifetime of each class being built */
    private array $buildingLifetimes = [];

    /** Set while something long-lived -- the Kernel at boot -- is being assembled. */
    private ?string $longLived = null;

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
        // A class bound to itself -- the way to give a class with no
        // attribute a lifetime -- is built directly; through get() it would
        // re-enter its own resolution and read as a cycle.
        $this->bindings[$abstract] = match (true) {
            $concrete === $abstract => fn (self $c): object => $this->build($abstract),
            \is_string($concrete) => static fn (self $c): object => $c->get($concrete),
            default => $concrete,
        };
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
        // Checked on every lookup of a request-lived service, not only when
        // it is first built: in a real request the service usually exists
        // already -- a page or middleware asked for it -- when a worker
        // service that wants it is built, and that is exactly the capture.
        if (isset($this->perRequest[$class])) {
            $this->assertNotCaptive($class, Lifetime::Request);
        }

        if (!isset($this->shared[$class]) && !isset($this->bindings[$class])
            && !class_exists($class) && !interface_exists($class)) {
            throw ContainerException::unknownClass($class);
        }

        $object = $this->shared[$class] ?? $this->perRequest[$class] ?? $this->resolve($class);
        // A binding's factory can build anything; checked before it is
        // shared, in resolve(), and here for registered instances.
        if (!$object instanceof $class) {
            throw ContainerException::wrongType($class, $object::class);
        }

        return $object;
    }

    /** Whether the container can build or hand out this class. */
    public function has(string $class): bool
    {
        return $this->canProvide($class);
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

        $lifetime = $this->lifetimeOf($class);
        $this->assertNotCaptive($class, $lifetime);

        $this->building[] = $class;
        $this->buildingLifetimes[] = $lifetime;
        try {
            $object = isset($this->bindings[$class]) ? ($this->bindings[$class])($this) : $this->build($class);
            if (!$object instanceof $class) {
                throw ContainerException::wrongType($class, $object::class);
            }
        } finally {
            array_pop($this->building);
            array_pop($this->buildingLifetimes);
        }

        match ($lifetime) {
            Lifetime::Worker => $this->shared[$class] = $object,
            Lifetime::Request => $this->perRequest[$class] = $object,
            Lifetime::Transient => null,
        };

        return $object;
    }

    /**
     * Runs $assemble with request-lived services refused: whatever is built
     * inside it is kept past the request -- the Kernel's endpoint stacks
     * are built once at boot -- and a request-lived service captured there
     * would carry one request's state into every later one.
     *
     * @template T
     * @param \Closure(): T $assemble
     * @return T
     */
    public function longLived(string $holder, \Closure $assemble): mixed
    {
        $previous = $this->longLived;
        $this->longLived = $holder;
        try {
            return $assemble();
        } finally {
            $this->longLived = $previous;
        }
    }

    /**
     * A request-lived service may not be held by anything that outlives the
     * request: a Worker-lived object in the graph being built, or the
     * long-lived assembly in progress. Held, it keeps the instance -- and
     * the user, tenant or data it was built for -- after endRequest() has
     * dropped the container's copy.
     */
    private function assertNotCaptive(string $class, Lifetime $lifetime): void
    {
        if ($lifetime !== Lifetime::Request) {
            return;
        }

        if ($this->longLived !== null) {
            throw ContainerException::captive($class, $this->longLived);
        }

        foreach ($this->buildingLifetimes as $index => $holder) {
            if ($holder === Lifetime::Worker) {
                throw ContainerException::captive($class, $this->building[$index]);
            }
        }
    }

    /** Whether get() could hand this out without failing for want of a binding or a cycle. */
    private function canProvide(string $class): bool
    {
        if (isset($this->shared[$class]) || isset($this->perRequest[$class]) || isset($this->bindings[$class])) {
            return true;
        }

        return class_exists($class)
            && (new \ReflectionClass($class))->isInstantiable()
            && !\in_array($class, $this->building, true);
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
        if (!$reflection->isInstantiable() && !$reflection->isInterface() && !$reflection->isAbstract()) {
            throw ContainerException::privateConstructor($class, \array_slice($this->building, 0, -1));
        }
        if (!$reflection->isInstantiable()) {
            throw ContainerException::notInstantiable($class, \array_slice($this->building, 0, -1));
        }

        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            // A variadic takes nothing: there is no list of things to invent.
            if ($parameter->isVariadic()) {
                break;
            }
            $arguments[] = $this->argumentFor($class, $parameter);
        }

        $object = $reflection->newInstanceArgs($arguments);
        $this->injectProperties($reflection, $object);

        return $object;
    }

    /**
     * Every property of a class, its parents' private ones included:
     * getProperties() on a subclass omits them, so a base class's private
     * #[Dependency] property was never filled.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<\ReflectionProperty>
     */
    private static function propertiesOf(\ReflectionClass $reflection): array
    {
        $properties = $reflection->getProperties();
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(\ReflectionProperty::IS_PRIVATE) as $private) {
                $properties[] = $private;
            }
        }

        return $properties;
    }

    private function argumentFor(string $class, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $parameter->getName();
        $path = \array_slice($this->building, 0, -1);

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();
            if ($dependency === 'self' || $dependency === 'static') {
                $dependency = $parameter->getDeclaringClass()?->getName() ?? $class;
            }

            if (class_exists($dependency) || interface_exists($dependency)) {
                // An optional dependency the container cannot provide -- an
                // unbound interface, a class already being built -- takes
                // its default. Only a required one is an error.
                $optional = $parameter->isDefaultValueAvailable() || $type->allowsNull();
                if ($optional && !$this->canProvide($dependency)) {
                    return $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
                }

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
        foreach (self::propertiesOf($reflection) as $property) {
            if ($property->getAttributes(Dependency::class) === [] || $property->isStatic()) {
                continue;
            }

            // Set already: by the constructor -- a promoted parameter carries
            // the attribute onto its property, and a readonly one cannot be
            // written twice -- or by the class itself. Not the container's.
            if ($property->isInitialized($object)) {
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
