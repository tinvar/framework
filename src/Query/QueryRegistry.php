<?php

declare(strict_types=1);

namespace Tinvar\Query;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Panel;
use Tinvar\Http\DeclaredMiddleware;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PanelBinding;
use Tinvar\Orm\Model;
use Tinvar\Support\Str;
use Tinvar\Support\KnownAttributes;
use Tinvar\Validation\FieldPlan;

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

    /** @var array<string, list<string>> */
    private array $middleware = [];

    /** @var array<string, class-string|null> */
    private array $tenantFrom = [];

    /** @var array<string, array<string, FieldPlan>> query name => a batch's scalar and enum arguments, as plans */
    private array $batchScalars = [];

    /** @var array<string, string> query name => the #[Domain] pattern it is held to */
    private array $hosts = [];

    /** @var array<string, list<Panel>> */
    private array $panels = [];

    /** @var array<string, array<string, bool>> query name => parameter name => required */
    private array $parameters = [];

    /** @var array<string, list<string>> query name => optional parameters that have no default */
    private array $nullWhenAbsent = [];

    /** @var array<string, array<string, class-string<Model>>> query name => argument => model */
    private array $modelArguments = [];

    /** @var array<string, array<string, \ReflectionParameter>> query name => parameter coerced to its type */
    private array $scalars = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * Phase 0's container seam. The brief asks for dependency wiring to stay
     * stubbed behind an interface; a resolver closure is enough until there is
     * a real container.
     *
     * @param (\Closure(class-string): object)|null $resolver
     * @param list<class-string<\Tinvar\Orm\Model>> $models the application's models, which
     *        tenant-scoped-ids-must-be-typed reads as each class registers (ADR 0105)
     */
    public function __construct(private readonly ?\Closure $resolver = null, private readonly array $models = []) {}

    /** @param class-string $class */
    public function register(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        // A Tinvar attribute naming no class would be matched by nothing, and
        // what it declares would not hold (KnownAttributes).
        KnownAttributes::check($reflection);

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

        // The name becomes a generated TypeScript function and the argument
        // keys its parameter's properties, so both are checked here, where
        // they are declared, rather than written into source as they come.
        if (!Str::isDottedIdentifier($name)) {
            throw new \InvalidArgumentException(
                "{$class} is registered as query '{$name}', which is not a dotted identifier. A query name "
                . "is identifiers joined by '.', as in 'racks.unitLoad'.",
            );
        }
        foreach (array_keys($declaration->args) as $argument) {
            if (!Str::isIdentifier((string) $argument)) {
                throw new \InvalidArgumentException(
                    "{$class} declares #[Query(args:)] key '{$argument}', which is not an identifier. "
                    . 'An argument name is letters, digits and _, not starting with a digit.',
                );
            }
        }

        // Replacing silently meant a route's allowlist entry, a cache key and
        // a generated TypeScript function all started meaning a different
        // class -- one with its own checks -- depending on which registered
        // last. Two classes under one name is refused, as it is for actions.
        $existing = $this->classes[$name] ?? null;
        if ($existing !== null) {
            throw new \InvalidArgumentException("Two queries are named '{$name}': {$existing} and {$class}.");
        }

        // A plain query's signature is its __invoke, which is what the runner
        // calls. #[Query(args:)] there was read INSTEAD of it for model
        // arguments while the parameter list still came from __invoke, so an
        // args naming only `rack` left `site` unresolved: the gate was handed
        // a string and the call ended in a TypeError. A second declaration
        // of the same signature can only repeat it or contradict it, so one
        // that contradicts it is refused rather than merged.
        if (!is_subclass_of($class, BatchQuery::class) && $declaration->args !== []) {
            self::assertArgsAgree($reflection, $declaration);
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
        $violations = new BuildRules($this->models)->checkEntryPoints([$class]);
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
        $this->classLevelChecks[$name] = Authorize::classLevelCount($class);
        $this->middleware[$name] = Middleware::declaredOn($class, Query::entryMethodOf($class));
        // A gate -- an attribute that carries its own middleware, as
        // #[RequiresPlan] -- guarded /_query alone: a query a page reads runs
        // in the page's render, behind the page's middleware and never its
        // own, so any page reading it handed its rows to whoever the gate
        // would refuse. Refused where it is written (ADR 0150).
        foreach ($this->middleware[$name] as $middleware) {
            if (DeclaredMiddleware::isReference($middleware)) {
                $gate = substr($middleware, 0, (int) strpos($middleware, '@'));

                throw new \InvalidArgumentException(
                    "{$class} carries #[{$gate}], a gate, and a query runs no gate of its own: a page that reads it runs it in its render, "
                    . 'behind the page\'s middleware, so the gate would guard /_query alone and every page reading the query would hand its rows past it. '
                    . 'Take it off the query, and put it on the page or layout that reads the query -- or on the action, if an action needs it.',
                );
            }
        }
        $this->tenantFrom[$name] = TenantFrom::declaredOn($class, Query::entryMethodOf($class));
        $host = Domain::declaredOn($class, Query::entryMethodOf($class));
        if ($host !== null) {
            $this->hosts[$name] = $host->pattern;
        }
        $this->panels[$name] = Panel::declaredOn($class, Query::entryMethodOf($class));
        try {
            PanelBinding::of($this->panels[$name]);
        } catch (\LogicException $e) {
            throw new \InvalidArgumentException("{$class}: {$e->getMessage()}", 0, $e);
        }
        $this->parameters[$name] = self::parametersOf($reflection, $declaration);
        $this->nullWhenAbsent[$name] = self::nullWhenAbsentOf($reflection);
        $this->modelArguments[$name] = self::modelArgumentsOf($reflection, $declaration);
        $this->scalars[$name] = self::scalarParametersOf($reflection);
        $this->batchScalars[$name] = self::batchScalarsOf($reflection, $declaration);

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
     * @return list<string>
     */
    public function middlewareFor(string $name): array
    {
        return $this->middleware[$name] ?? [];
    }

    /**
     * Where a query's tenant comes from on a call over HTTP, by its
     * #[TenantFrom]; null for none. A call from a render runs in its page's
     * tenant, as it runs in its page's middleware.
     *
     * @return class-string|null
     */
    public function tenantSourceFor(string $name): ?string
    {
        return $this->tenantFrom[$name] ?? null;
    }

    /**
     * The host a query's #[Domain] holds a call over HTTP to, or null for
     * none: then any host the application serves, unless its panel holds it
     * to one (ADR 0149).
     */
    public function hostFor(string $name): ?string
    {
        return $this->hosts[$name] ?? null;
    }

    /** @return list<string> every #[Domain] pattern a query declares */
    public function hostPatterns(): array
    {
        return array_values(array_unique($this->hosts));
    }

    /**
     * A query's #[Panel] declarations, for a call over HTTP; a call from a
     * render runs in its page's panel.
     *
     * @return list<Panel>
     */
    public function panelsFor(string $name): array
    {
        return $this->panels[$name] ?? [];
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
     * The optional parameters PHP has no value of its own for: they accept
     * null, so parametersFor() calls them optional, but they declare no
     * default. A call that leaves one out has to be given null for it, or
     * the body is invoked an argument short.
     *
     * @return list<string>
     */
    public function nullWhenAbsentFor(string $name): array
    {
        return $this->nullWhenAbsent[$name] ?? [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return list<string>
     */
    private static function nullWhenAbsentOf(\ReflectionClass $reflection): array
    {
        // A batch's per-call arguments are all required, and loadMany() is
        // handed them as data rather than bound as parameters.
        if (is_subclass_of($reflection->getName(), BatchQuery::class)) {
            return [];
        }

        $names = [];
        foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
            if ($parameter->allowsNull() && !$parameter->isDefaultValueAvailable()) {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }

    /**
     * Refuses #[Query(args:)] on a plain query unless it names exactly the
     * __invoke parameters, each with its declared type.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private static function assertArgsAgree(\ReflectionClass $reflection, Query $declaration): void
    {
        $signature = [];
        foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
            $type = $parameter->getType();
            $signature[$parameter->getName()] = $type instanceof \ReflectionNamedType ? $type->getName() : null;
        }

        $declared = [];
        foreach ($declaration->args as $argument => $type) {
            $declared[(string) $argument] = ltrim($type, '\\');
        }

        $sorted = static function (array $arguments): array {
            ksort($arguments);

            return $arguments;
        };
        if ($sorted($declared) === $sorted($signature)) {
            return;
        }

        $describe = static fn (array $arguments): string => implode(', ', array_map(
            static fn (string $name, ?string $type): string => ($type ?? 'untyped') . " \${$name}",
            array_keys($arguments),
            $arguments,
        ));

        throw new \InvalidArgumentException(
            "{$reflection->getName()} declares #[Query(args:)] as ({$describe($declared)}), but its __invoke takes "
            . "({$describe($signature)}). A plain query's arguments are its __invoke parameters; drop args, "
            . 'which only a BatchQuery needs, or make it name exactly those.',
        );
    }

    /**
     * The arguments this query declares as models.
     *
     * #[Query(args: ['unit' => Unit::class])] -- "the per-call signature is
     * declared ... and denied calls fail individually before the batch runs".
     * A plain query's model arguments come from its __invoke signature, the
     * same place its parameter list does; register() has refused an args
     * that disagrees with it.
     *
     * @return array<string, class-string<Model>>
     */
    public function modelArgumentsFor(string $name): array
    {
        return $this->modelArguments[$name] ?? [];
    }

    /**
     * The parameters a call's value is coerced to before the body sees it:
     * a plain query's int, float, bool, string and array parameters and its
     * backed enums, as an action's are (Validator::coerceParameter()). JSON
     * sends a case as its backing value and a form or a URL sends digits,
     * and spread as they came each was a TypeError from inside the call --
     * as a string sent for an array was.
     *
     * @return array<string, \ReflectionParameter>
     */
    public function scalarParametersFor(string $name): array
    {
        return $this->scalars[$name] ?? [];
    }

    /**
     * A batch query's scalar and enum arguments, as the plans a call's value
     * is coerced by: #[Query(args: ['term' => 'string'])] types each as a
     * plain query's parameter does, and a value its type has no place for
     * is bad arguments there too, not data loadMany() is left to judge.
     *
     * @return array<string, FieldPlan>
     */
    public function scalarPlansFor(string $name): array
    {
        return $this->batchScalars[$name] ?? [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return array<string, \ReflectionParameter>
     */
    private static function scalarParametersOf(\ReflectionClass $reflection): array
    {
        // A batch's per-call arguments are handed to loadMany() as data.
        if (is_subclass_of($reflection->getName(), BatchQuery::class)) {
            return [];
        }

        // A parameter the runner coerces nothing to -- mixed, untyped, a
        // union, an object -- would take what the caller sent as it came,
        // or fail with a TypeError over it: refused here, as an action's is.
        $scalars = [];
        foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
            $type = $parameter->getType();
            $where = "{$reflection->getName()}::__invoke(\${$parameter->getName()})";
            if ($parameter->isVariadic()) {
                throw new \InvalidArgumentException("{$where} is variadic; a query's argument is one named value.");
            }
            if (!$type instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException(
                    "{$where} is " . ($type === null ? 'untyped' : "typed {$type}") . ', which the runner cannot coerce a call\'s value to. '
                    . 'A query takes a string, int, float, bool or array, a backed enum, or a model resolved by id.',
                );
            }
            $named = $type->getName();
            if ($type->isBuiltin()) {
                if (!\in_array($named, self::SCALARS, true)) {
                    throw new \InvalidArgumentException("{$where} is {$named}, which a call cannot send. " . self::TYPES);
                }
                $scalars[$parameter->getName()] = $parameter;
            } elseif (is_subclass_of($named, \BackedEnum::class)) {
                $scalars[$parameter->getName()] = $parameter;
            } elseif (!is_subclass_of($named, Model::class)) {
                throw new \InvalidArgumentException("{$where} is {$named}, which the runner cannot build from a call. " . self::TYPES);
            }
        }

        return $scalars;
    }

    /** The scalar types a query's argument may be declared as. */
    private const array SCALARS = ['string', 'int', 'float', 'bool', 'array'];

    private const string TYPES = 'A query takes a string, int, float, bool or array, a backed enum, or a model resolved by id.';

    /**
     * A batch's scalar and enum arguments, each #[Query(args:)] type read
     * into the plan a call's value is coerced by -- and a type that is none
     * of those, nor a model, refused: it would type nothing, and the value
     * would reach loadMany() as sent.
     *
     * @param \ReflectionClass<object> $reflection
     * @return array<string, FieldPlan>
     */
    private static function batchScalarsOf(\ReflectionClass $reflection, Query $declaration): array
    {
        if (!is_subclass_of($reflection->getName(), BatchQuery::class)) {
            return [];
        }

        $plans = [];
        foreach ($declaration->args as $argument => $type) {
            $argument = (string) $argument;
            if (is_subclass_of($type, Model::class)) {
                continue;
            }
            if (\in_array($type, self::SCALARS, true)) {
                $plans[$argument] = new FieldPlan($argument, $type, nullable: false, hasDefault: false, default: null, rules: []);
            } elseif (is_subclass_of($type, \BackedEnum::class)) {
                $plans[$argument] = new FieldPlan($argument, 'enum', nullable: false, hasDefault: false, default: null, rules: [], enumClass: $type);
            } else {
                throw new \InvalidArgumentException(
                    "{$reflection->getName()} declares #[Query(args:)] '{$argument}' as '{$type}', which types nothing. " . self::TYPES,
                );
            }
        }

        return $plans;
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

        if (is_subclass_of($reflection->getName(), BatchQuery::class)) {
            foreach ($declaration->args as $argument => $class) {
                if (is_subclass_of($class, Model::class)) {
                    $models[$argument] = $class;
                }
            }

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
