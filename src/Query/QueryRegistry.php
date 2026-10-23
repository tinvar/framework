<?php

declare(strict_types=1);

namespace Tinvar\Query;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Query;
use Tinvar\Auth\BuildRules;
use Tinvar\Orm\Model;
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

    /** @var array<string, int> how many of each query's checks, at the front, its class declared */
    private array $classLevelChecks = [];

    /** @var array<string, list<class-string<\Tinvar\Http\Middleware>>> */
    private array $middleware = [];

    /** @var array<string, array<string, bool>> query name => parameter name => required */
    private array $parameters = [];

    /** @var array<string, array<string, class-string<Model>>> query name => argument => model */
    private array $modelArguments = [];

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

        // Found here, not on the first call: there it threw out of the whole
        // tick and took every other query's result with it.
        if (!is_subclass_of($class, BatchQuery::class)
            && !($reflection->hasMethod('__invoke') && $reflection->getMethod('__invoke')->isPublic())) {
            throw new \InvalidArgumentException(
                "{$class} is a #[Query] with no body: give it a public __invoke(), or implement BatchQuery.",
            );
        }

        $declaration = $attribute->newInstance();
        $name = $declaration->name ?? Str::queryName($class);

        // Replacing silently meant a route's allowlist entry, a cache key and
        // a generated TypeScript function all started meaning a different
        // class -- one with its own checks -- depending on which registered
        // last. Two classes under one name is refused, as it is for actions.
        $existing = $this->classes[$name] ?? null;
        if ($existing !== null) {
            throw new \InvalidArgumentException("Two queries are named '{$name}': {$existing} and {$class}.");
        }

        // A batch has no signature to read arguments off, so #[Query(args:)]
        // is its only declaration. Without one, the runner checked no keys
        // and resolved no models: whatever the renderer sent reached
        // loadMany() as raw values, unscoped and unauthorized.
        if (is_subclass_of($class, BatchQuery::class) && $declaration->args === []) {
            throw new \InvalidArgumentException(
                "{$class} is a BatchQuery with no #[Query(args:)]. Declare its per-call arguments, typing "
                . "each model as the model -- #[Query(args: ['unit' => Unit::class])] -- so they are "
                . 'resolved, scoped and authorized before loadMany() runs.',
            );
        }

        // "Enforced by BuildRules AND at registration." A query reaches a body
        // from the socket and from /_query, so an unguarded model input here
        // is served to a caller every policy denies, and a `shared: true`
        // query that reads the current user caches one caller's answer for
        // everyone. Both were registering silently; only actions were checked.
        $violations = (new BuildRules())->check([$class]);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                "{$class} fails the security model's build rules:\n  "
                . implode("\n  ", array_map(strval(...), $violations)),
            );
        }

        $this->classes[$name] = $class;
        $this->declarations[$name] = $declaration;
        // The class's checks and its entry method's -- loadMany() for a
        // batch -- read the way BuildRules reads them. Only the class's used
        // to be kept, so a check written on the method passed the build
        // rules and never ran.
        $this->checks[$name] = Authorize::declaredOn($class, Query::entryMethodOf($class));
        $this->classLevelChecks[$name] = \count($reflection->getAttributes(Authorize::class));
        $this->middleware[$name] = Middleware::declaredOn($class, Query::entryMethodOf($class));
        $this->parameters[$name] = self::parametersOf($reflection, $declaration);
        $this->modelArguments[$name] = self::modelArgumentsOf($reflection, $declaration);

        return $name;
    }

    /**
     * @param list<class-string> $classes
     * @return list<string> the registered names
     */
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

    /**
     * How many of checksFor(), at the front, the class declared rather than
     * the entry method -- so each set can be judged on its own when a call
     * leaves optional subjects out (Checks::undecided).
     */
    public function classLevelChecksFor(string $name): int
    {
        return $this->classLevelChecks[$name] ?? 0;
    }

    /**
     * The #[Middleware] a query declares, on its class and its entry method.
     * None for a name that is not registered: the runner refuses that name,
     * and there is nothing to have declared.
     *
     * @return list<class-string<\Tinvar\Http\Middleware>>
     */
    public function middlewareFor(string $name): array
    {
        return $this->middleware[$name] ?? [];
    }

    /** @return class-string|null */
    public function classFor(string $name): ?string
    {
        return $this->classes[$name] ?? null;
    }

    /**
     * The query object for a name.
     *
     * Built with `new`, a query has no dependencies and so no state, and one
     * instance per worker is enough. Built by the application's resolver, it
     * may take services, and how long those live is the resolver's to
     * decide: kept here for the worker's life, a query holding a
     * request-lived service would carry one request's state into the next.
     * So it is asked every time -- a container keeps what should be kept.
     */
    public function instanceFor(string $name): object
    {
        $class = $this->classes[$name];

        if ($this->resolver !== null) {
            return ($this->resolver)($class);
        }

        return $this->instances[$name] ??= new $class();
    }

    /**
     * The argument names this query accepts, and whether each is required.
     *
     * Arguments arrive from the renderer, which the trust boundary table calls
     * semi-trusted ("runs third-party npm code"), so they are checked against
     * the declaration before authorization rather than spread straight into a
     * call.
     *
     * @return array<string, bool>
     */
    public function parametersFor(string $name): array
    {
        return $this->parameters[$name] ?? [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return array<string, bool>
     */
    private static function parametersOf(\ReflectionClass $reflection, Query $declaration): array
    {
        // A batch query's per-call signature is declared on the attribute:
        // #[Query(args: ['unit' => Unit::class])].
        if (is_subclass_of($reflection->getName(), BatchQuery::class)) {
            return array_map(static fn (): bool => true, $declaration->args);
        }

        // register() has made sure a query that is not a batch has one.
        $parameters = [];
        foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = !$parameter->isDefaultValueAvailable()
                && !$parameter->allowsNull();
        }

        return $parameters;
    }

    /**
     * The arguments this query declares as models.
     *
     * #[Query(args: ['unit' => Unit::class])] -- "the per-call signature is
     * declared ... and denied calls fail individually before the batch runs".
     * A plain query's model arguments come from its __invoke signature.
     *
     * @return array<string, class-string<Model>>
     */
    public function modelArgumentsFor(string $name): array
    {
        return $this->modelArguments[$name] ?? [];
    }

    public function isBatch(string $name): bool
    {
        return isset($this->classes[$name]) && is_subclass_of($this->classes[$name], BatchQuery::class);
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return array<string, class-string<Model>>
     */
    private static function modelArgumentsOf(\ReflectionClass $reflection, Query $declaration): array
    {
        $models = [];

        foreach ($declaration->args as $argument => $class) {
            if (is_subclass_of($class, Model::class)) {
                $models[$argument] = $class;
            }
        }

        if ($models !== [] || !$reflection->hasMethod('__invoke')) {
            return $models;
        }

        foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_subclass_of($type->getName(), Model::class)) {
                $models[$parameter->getName()] = $type->getName();
            }
        }

        return $models;
    }
}
