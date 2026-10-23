<?php

declare(strict_types=1);

namespace Tinvar\Query;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Query;
use Tinvar\Support\Str;

/**
 * Wire name to #[Query] class, with the declarations each one carries.
 *
 * The registry is the only way a name from the socket reaches a class, so an
 * unregistered name can never be instantiated.
 */
final class QueryRegistry
{
    /** @var array<string, class-string> */
    private array $classes = [];

    /** @var array<string, Query> */
    private array $declarations = [];

    /** @var array<string, list<Authorize>> */
    private array $checks = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * Phase 0's container seam. The brief asks for dependency wiring to stay
     * stubbed behind an interface; a resolver closure is enough until there is
     * a real container.
     *
     * @param (\Closure(class-string): object)|null $resolver
     */
    public function __construct(private readonly ?\Closure $resolver = null) {}

    /** @param class-string $class */
    public function register(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Query::class)[0] ?? null;
        if ($attribute === null) {
            throw new \InvalidArgumentException("{$class} has no #[Query] attribute.");
        }

        $declaration = $attribute->newInstance();
        $name = $declaration->name ?? Str::queryName($class);

        $this->classes[$name] = $class;
        $this->declarations[$name] = $declaration;
        $this->checks[$name] = array_map(
            static fn (\ReflectionAttribute $a): Authorize => $a->newInstance(),
            $reflection->getAttributes(Authorize::class),
        );

        return $name;
    }

    /** @param list<class-string> $classes @return list<string> */
    public function registerAll(array $classes): array
    {
        return array_map($this->register(...), $classes);
    }

    public function has(string $name): bool
    {
        return isset($this->classes[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->classes);
    }

    public function declarationFor(string $name): Query
    {
        return $this->declarations[$name];
    }

    /** @return list<Authorize> */
    public function checksFor(string $name): array
    {
        return $this->checks[$name];
    }

    /** @param class-string|null $class */
    public function classFor(string $name): ?string
    {
        return $this->classes[$name] ?? null;
    }

    /** Query objects are stateless, so one instance per worker is enough. */
    public function instanceFor(string $name): object
    {
        $class = $this->classes[$name];

        return $this->instances[$name] ??= $this->resolver !== null
            ? ($this->resolver)($class)
            : new $class();
    }

    public function isBatch(string $name): bool
    {
        return isset($this->classes[$name]) && is_subclass_of($this->classes[$name], BatchQuery::class);
    }
}
