<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\TenantScoped;
use Tinvar\Data\Data;
use Tinvar\Orm\Model;
use Tinvar\Support\Str;
use Tinvar\Validation\FormRequest;

/**
 * The security model's build rules, checked statically.
 *
 * These are what make declarative authorization trustworthy: without them,
 * forgetting an #[Authorize] is silent, and the failure is open.
 *
 * Routability is decided by the ENTRY-POINT marker -- #[Page], #[Layout],
 * #[Query], #[Action] -- never by whether the method already declares a
 * check. An earlier version only looked at methods that carried #[Authorize]
 * or #[NoAuthorization], which made the rule blind to exactly the mistake it
 * exists to catch.
 *
 * Implemented:
 *
 *   authorize-every-model-input
 *     "every model-typed input of every routable entry point ... needs an
 *      #[Authorize] naming it, or #[NoAuthorization(reason: '...')]. This
 *      includes second and third model arguments."
 *
 *   tenant-scoped-ids-must-be-typed
 *     "a form request or Data field typed string or int whose name refers to a
 *      #[TenantScoped] model (siteId, scriptId) fails the build."
 *
 *   authorize-must-name-a-subject
 *     An #[Authorize] whose argument names nothing resolvable is a 500 on the
 *     first request, which is precisely what a build rule should pre-empt.
 *
 *   entry-point-must-not-be-static
 *     A static entry point has no instance to resolve dependencies on, and the
 *     runners do not call one.
 *
 *   query-must-declare-authorization
 *     "Every routable entry point declares its checks with #[Authorize]", and
 *     a query is routable on its own: POST /_query runs "the query's panel
 *     stack", never the chain of a page that renders it. A query with no
 *     model input used to need no check at all, so one meant only for an
 *     admin page answered any signed-in caller.
 *
 * The panel-binding rule needs panels, which are a later phase.
 */
final class BuildRules
{
    public const string AUTHORIZE_EVERY_MODEL_INPUT = 'authorize-every-model-input';
    public const string TENANT_SCOPED_IDS_MUST_BE_TYPED = 'tenant-scoped-ids-must-be-typed';
    public const string AUTHORIZE_MUST_NAME_A_SUBJECT = 'authorize-must-name-a-subject';
    public const string ENTRY_POINT_MUST_NOT_BE_STATIC = 'entry-point-must-not-be-static';
    public const string SHARED_QUERY_MUST_NOT_READ_THE_USER = 'shared-query-must-not-read-the-user';
    public const string QUERY_MUST_DECLARE_AUTHORIZATION = 'query-must-declare-authorization';

    /**
     * Types a `shared: true` query may not reach by injection, directly or
     * through another injected class.
     */
    private const array USER_DEPENDENT_TYPES = [
        AuthContext::class => 'the current user',
        Gate::class => 'a policy decision',
        // The spec names Panel::current() in this rule. Panels are an
        // injected context in this framework rather than an ambient static,
        // which means the textual needle below can never match -- so the
        // type has to be listed, or choosing the object form would have
        // retired a rule the spec requires.
        PanelContext::class => 'the current panel',
    ];

    /**
     * Text a `shared: true` query may not contain in its own source.
     *
     * Kept ALONGSIDE the type walk, not instead of it. The walk sees injected
     * dependencies; it cannot see `$container->get(AuthContext::class)` or a
     * static call, and dropping the textual check for those was a regression.
     * Each needle is specific enough not to fire on an ordinary identifier --
     * '->can(' does not match '->rescan(' -- and a false positive on an
     * explicit, dangerous opt-in is the right direction to be wrong in.
     */
    private const array USER_DEPENDENT_CALLS = [
        'AuthContext' => 'the current user',
        '->user(' => 'the current user',
        'auth()' => 'the current user',
        '->can(' => 'a policy decision',
        '->allows(' => 'a policy decision',
        '->acrossTenants(' => 'another tenant',
        '->withinReseller(' => 'another reseller',
        'Panel::current' => 'the current panel',
        'PanelContext' => 'the current panel',
        '->currentOrNull(' => 'the current panel',
    ];

    /** @var array<string, class-string> lowercased model name => class */
    private array $tenantScoped = [];

    /** @param list<class-string<Model>> $models every model the application declares */
    public function __construct(array $models = [])
    {
        foreach ($models as $model) {
            if ((new \ReflectionClass($model))->getAttributes(TenantScoped::class) !== []) {
                $this->tenantScoped[strtolower(Str::shortName($model))] = $model;
            }
        }
    }

    /**
     * @param list<class-string> $entryPoints pages, layouts, queries, action holders
     * @param list<class-string> $payloads    Data objects and form requests
     * @return list<Violation>
     */
    public function check(array $entryPoints, array $payloads = []): array
    {
        $violations = [];

        foreach ($entryPoints as $class) {
            $violations = [...$violations, ...$this->checkEntryPoint($class)];
            $violations = [...$violations, ...self::checkQueryDeclaresAuthorization($class)];
            $violations = [...$violations, ...self::checkSharedQuery($class)];
        }

        foreach ($payloads as $class) {
            $violations = [...$violations, ...$this->checkPayload($class, $class, [])];
        }

        return $violations;
    }

    /**
     * @param list<class-string> $entryPoints
     * @param list<class-string> $payloads
     */
    public function assert(array $entryPoints, array $payloads = []): void
    {
        $violations = $this->check($entryPoints, $payloads);
        if ($violations === []) {
            return;
        }

        throw new \RuntimeException(
            "Build rules failed:\n  " . implode("\n  ", array_map(strval(...), $violations)),
        );
    }

    /**
     * @param class-string $class
     * @return list<Violation>
     */
    private function checkEntryPoint(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $violations = [];

        foreach ($this->entryMethods($reflection) as [$method, $classLevel]) {
            $where = "{$class}::{$method->getName()}";

            if ($method->isStatic()) {
                $violations[] = new Violation(
                    self::ENTRY_POINT_MUST_NOT_BE_STATIC,
                    "{$where}()",
                    'is a routable entry point and static. The runners call an instance; a static '
                    . 'entry point is silently unreachable, and unchecked.',
                );
                continue;
            }

            // A class-level declaration covers the class's own entry point
            // (__invoke) only. Letting it blanket every method means one
            // read-only check on the class satisfies a destructive method.
            // On an action it still runs, ahead of the method's own, so its
            // subject is checked here too -- it just covers nothing.
            $checks = Authorize::declaredOn($class, $method->getName());
            $inherited = $classLevel ? 0 : \count($reflection->getAttributes(Authorize::class));

            $exempt = $method->getAttributes(NoAuthorization::class) !== []
                || ($classLevel && $reflection->getAttributes(NoAuthorization::class) !== []);

            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = self::modelTypesOf($parameter);

                // A model inside a Data object or form request is resolved and
                // reaches the body exactly like a parameter, so it needs a
                // check exactly like a parameter.
                foreach (self::payloadModelsOf($parameter) as $field => $model) {
                    $parameters[$field] = [$model];
                }
            }

            // A batch query declares its per-call signature on the attribute
            // and has no __invoke, so its model arguments live nowhere a
            // parameter scan would find them.
            foreach (self::batchModelArguments($reflection, $method) as $argument => $model) {
                $parameters[$argument] = [$model];
            }

            $covered = [];
            foreach ($checks as $index => $check) {
                $argument = $check->argument;
                $declares = $index < $inherited ? 'inherits from its class' : 'declares';

                if ($argument === null) {
                    $violations[] = new Violation(
                        self::AUTHORIZE_MUST_NAME_A_SUBJECT,
                        "{$where}()",
                        "{$declares} #[Authorize('{$check->ability}')] with no subject. Name the model-typed "
                        . "input it covers, or the class, as in Authorize('viewAny', Rack::class).",
                    );
                    continue;
                }

                if (!\array_key_exists($argument, $parameters)
                    && !class_exists($argument)
                    && !interface_exists($argument)) {
                    $violations[] = new Violation(
                        self::AUTHORIZE_MUST_NAME_A_SUBJECT,
                        "{$where}()",
                        "{$declares} #[Authorize('{$check->ability}', '{$argument}')], but '{$argument}' is "
                        . 'neither a parameter of this method nor a class. It would throw on the first request.',
                    );
                    continue;
                }

                if (\array_key_exists($argument, $parameters) && $parameters[$argument] === []) {
                    $violations[] = new Violation(
                        self::AUTHORIZE_MUST_NAME_A_SUBJECT,
                        "{$where}(\${$argument})",
                        "is named by #[Authorize('{$check->ability}', '{$argument}')] but is not model-typed. "
                        . 'A policy would be handed a raw value; type it as the model so it is resolved and scoped.',
                    );
                    continue;
                }

                if ($index >= $inherited) {
                    $covered[] = $argument;
                }
            }

            // The same hazard as a bare id in a payload, on the path that has
            // no validation at all: a body that looks the row up itself runs
            // unscoped and unauthorized. Checked whether or not the method is
            // exempt: #[NoAuthorization] opts out of the authorization rule,
            // not out of tenant scoping.
            foreach ($method->getParameters() as $parameter) {
                $bare = self::bareIdType($parameter->getType());
                if ($bare === null) {
                    continue;
                }

                $model = $this->tenantScopedNamedBy($parameter->getName());
                if ($model === null) {
                    continue;
                }

                $violations[] = new Violation(
                    self::TENANT_SCOPED_IDS_MUST_BE_TYPED,
                    "{$where}(\${$parameter->getName()})",
                    "is a bare {$bare} naming {$model}, which is #[TenantScoped]. "
                    . 'Type it as the model so it is resolved and scoped; a raw id is not.',
                );
            }

            if ($exempt) {
                continue;
            }

            foreach ($parameters as $name => $models) {
                if ($models === [] || \in_array($name, $covered, true)) {
                    continue;
                }

                $violations[] = new Violation(
                    self::AUTHORIZE_EVERY_MODEL_INPUT,
                    "{$where}(\${$name})",
                    'takes ' . implode('|', $models) . " but no #[Authorize] names it. Add "
                    . "#[Authorize('<ability>', '{$name}')], or #[NoAuthorization(reason: '...')] "
                    . 'if it genuinely needs none.',
                );
            }
        }

        return $violations;
    }

    /**
     * Model-typed fields of a Data object or form request a parameter holds.
     *
     * @return array<string, class-string>
     */
    private static function payloadModelsOf(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return [];
        }

        $class = $type->getName();
        if (!is_subclass_of($class, Data::class) && !is_subclass_of($class, FormRequest::class)) {
            return [];
        }

        $models = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $field) {
            $fieldType = $field->getType();
            if ($fieldType instanceof \ReflectionNamedType
                && !$fieldType->isBuiltin()
                && is_a($fieldType->getName(), Model::class, true)) {
                $models[$field->getName()] = $fieldType->getName();
            }
        }

        return $models;
    }

    /**
     * A batch query's per-call model arguments, from #[Query(args:)].
     *
     * @param \ReflectionClass<object> $reflection
     * @return array<string, class-string>
     */
    private static function batchModelArguments(\ReflectionClass $reflection, \ReflectionMethod $method): array
    {
        if ($method->getName() !== 'loadMany') {
            return [];
        }

        $attribute = $reflection->getAttributes(Query::class)[0] ?? null;
        if ($attribute === null) {
            return [];
        }

        $models = [];
        foreach ($attribute->newInstance()->args as $argument => $class) {
            if (is_a($class, Model::class, true)) {
                $models[$argument] = $class;
            }
        }

        return $models;
    }

    /**
     * Routable methods, each flagged with whether a class-level declaration
     * applies to it.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<array{\ReflectionMethod, bool}>
     */
    private function entryMethods(\ReflectionClass $reflection): array
    {
        /** @var array<string, array{\ReflectionMethod, bool}> $methods */
        $methods = [];
        $isRoute = $reflection->getAttributes(Page::class) !== []
            || $reflection->getAttributes(Layout::class) !== [];

        if ($isRoute && $reflection->hasMethod('__invoke')) {
            $methods['__invoke'] = [$reflection->getMethod('__invoke'), true];
        }

        // The method the query runner calls: loadMany() for a BatchQuery,
        // even one that also has an __invoke. Checking __invoke there left
        // the method that actually runs unexamined.
        if ($reflection->getAttributes(Query::class) !== []) {
            $entry = Query::entryMethodOf($reflection->getName());
            if ($reflection->hasMethod($entry)) {
                $methods[$entry] ??= [$reflection->getMethod($entry), true];
            }
        }

        // An #[Action] marks its own method, wherever it sits, including on a
        // class that is also a page.
        foreach ($reflection->getMethods() as $method) {
            if ($method->getAttributes(Action::class) === []) {
                continue;
            }
            $methods[$method->getName()] ??= [$method, false];
        }

        return array_values($methods);
    }

    /**
     * A query says who may call it, whether or not it takes a model.
     *
     * The model-input rule cannot catch this: a query with no model input has
     * nothing for it to demand a check on. Such a query used to borrow its
     * protection from the page that rendered it -- which /_query never runs,
     * and whose route the caller names. Declaring a check, or an explicit
     * #[NoAuthorization] with a reason, is what makes the query safe on the
     * path where no page is involved.
     *
     * @param class-string $class
     * @return list<Violation>
     */
    private static function checkQueryDeclaresAuthorization(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        if ($reflection->getAttributes(Query::class) === []) {
            return [];
        }

        $entry = Query::entryMethodOf($class);
        if (Authorize::declaredOn($class, $entry) !== []) {
            return [];
        }

        $exempt = $reflection->getAttributes(NoAuthorization::class) !== []
            || ($reflection->hasMethod($entry)
                && $reflection->getMethod($entry)->getAttributes(NoAuthorization::class) !== []);
        if ($exempt) {
            return [];
        }

        return [new Violation(
            self::QUERY_MUST_DECLARE_AUTHORIZATION,
            $class,
            'is a #[Query] with no #[Authorize] and no #[NoAuthorization]. Over /_query it answers any '
            . "caller whose route lists it, and no page's checks run there. Declare who may call it, as in "
            . "Authorize('viewAny', Rack::class), or #[NoAuthorization(reason: '...')] if every signed-in "
            . 'user may.',
        )];
    }

    /**
     * `shared: true` drops the user from the cache key, so the body must not
     * be able to vary by user.
     *
     * Two checks, and the limits of both are stated rather than implied:
     *
     *  - A TYPE walk over the constructor dependency closure. Any class the
     *    query can reach by injection that itself depends on AuthContext or a
     *    Gate is refused. This catches the indirect case -- a query that reads
     *    the user through an injected helper -- which a textual check cannot.
     *  - A TEXTUAL check of the class's own source for acrossTenants,
     *    withinReseller and Panel::current. These are methods on objects a
     *    query legitimately depends on, so a type walk cannot distinguish
     *    them; only the direct case is caught.
     *
     * What is NOT checked: a method call into the call graph that reaches one
     * of those from a class whose type closure looks innocent. The spec asks
     * for "body or call graph", and the call graph needs a static analyser.
     * Saying so is better than a check that quietly passes the case it misses.
     *
     * @param class-string $class
     * @return list<Violation>
     */
    private static function checkSharedQuery(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Query::class)[0] ?? null;

        if ($attribute === null || !$attribute->newInstance()->shared) {
            return [];
        }

        $violations = [];

        // The constructor's parameters as well as the entry point's: a
        // dependency arrives either way.
        $parameters = [
            ...($reflection->getConstructor()?->getParameters() ?? []),
            ...self::entryParametersOf($reflection),
        ];

        foreach ($parameters as $parameter) {
            foreach (self::unanalysable($parameter) as $why) {
                $violations[] = new Violation(
                    self::SHARED_QUERY_MUST_NOT_READ_THE_USER,
                    "{$class}::\${$parameter->getName()}",
                    "is #[Query(shared: true)] and takes {$why}, which cannot be checked for a dependency "
                    . 'on the current user. A shared query drops the user from its cache key, so an '
                    . 'unanalysable dependency is refused rather than assumed safe.',
                );
            }
        }

        foreach (self::userDependentDependencies($class, []) as $dependency => $reason) {
            $violations[] = new Violation(
                self::SHARED_QUERY_MUST_NOT_READ_THE_USER,
                $class,
                "is #[Query(shared: true)] but depends on {$dependency}, which reads {$reason}. "
                . 'A shared query drops the user from its cache key, so two users read one entry. '
                . 'Either stop depending on it, or drop shared.',
            );
        }

        foreach (self::USER_DEPENDENT_CALLS as $needle => $what) {
            if (str_contains(self::sourceOf($reflection), $needle)) {
                $violations[] = new Violation(
                    self::SHARED_QUERY_MUST_NOT_READ_THE_USER,
                    $class,
                    "is #[Query(shared: true)] but calls '{$needle}', which reads {$what}. "
                    . 'A shared query drops the user from its cache key, so two users read one entry.',
                );
            }
        }

        return $violations;
    }

    /**
     * Classes reachable by constructor injection that depend on the user.
     *
     * @param class-string       $class
     * @param list<class-string> $visited
     * @return array<string, string> dependency => what it reads
     */
    private static function userDependentDependencies(string $class, array $visited): array
    {
        if (\in_array($class, $visited, true) || !class_exists($class)) {
            return [];
        }
        $visited[] = $class;

        $found = [];

        $reflection = new \ReflectionClass($class);
        $parameters = [
            ...($reflection->getConstructor()?->getParameters() ?? []),
            ...self::entryParametersOf($reflection),
        ];

        foreach ($parameters as $parameter) {
            // Union and intersection members all count: one of them reaching
            // the user is enough.
            foreach (self::namedTypesOf($parameter) as $name) {
                $matched = false;

                foreach (self::USER_DEPENDENT_TYPES as $forbidden => $reason) {
                    if ($name === $forbidden || is_a($name, $forbidden, true)) {
                        $found[$name] = $reason;
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    $found = [...$found, ...self::userDependentDependencies($name, $visited)];
                }
            }
        }

        return $found;
    }

    /**
     * Parameters of whatever the query's entry point is.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<\ReflectionParameter>
     */
    private static function entryParametersOf(\ReflectionClass $reflection): array
    {
        foreach (['__invoke', 'loadMany'] as $method) {
            if ($reflection->hasMethod($method)) {
                return $reflection->getMethod($method)->getParameters();
            }
        }

        return [];
    }

    /**
     * Class names a parameter can hold, flattening unions and intersections.
     *
     * @return list<class-string>
     */
    private static function namedTypesOf(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        $candidates = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType,
            $type instanceof \ReflectionIntersectionType => $type->getTypes(),
            default => [],
        };

        $names = [];
        foreach ($candidates as $candidate) {
            if ($candidate instanceof \ReflectionNamedType && !$candidate->isBuiltin()) {
                /** @var class-string $name */
                $name = $candidate->getName();
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Why a parameter cannot be checked, if it cannot.
     *
     * An untyped, interface-typed or abstract-typed dependency could be
     * anything at runtime, so the type walk proves nothing about it. For an
     * explicit, dangerous opt-in, that is a refusal rather than a pass.
     *
     * @return list<string>
     */
    private static function unanalysable(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if ($type === null) {
            return ['an untyped parameter'];
        }

        $reasons = [];
        foreach (self::namedTypesOf($parameter) as $name) {
            if (interface_exists($name)) {
                $reasons[] = "the interface {$name}";
                continue;
            }
            if (class_exists($name) && (new \ReflectionClass($name))->isAbstract()) {
                $reasons[] = "the abstract class {$name}";
            }
        }

        return $reasons;
    }

    /** @param \ReflectionClass<object> $reflection */
    private static function sourceOf(\ReflectionClass $reflection): string
    {
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return '';
        }

        return implode('', \array_slice(file($file) ?: [], $start - 1, $end - $start + 1));
    }

    /**
     * @param class-string       $class   the payload being walked
     * @param class-string       $root    the payload the caller asked about
     * @param list<class-string> $visited guards against a cyclic graph
     * @return list<Violation>
     */
    private function checkPayload(string $class, string $root, array $visited): array
    {
        if (!is_subclass_of($class, Data::class) && !is_subclass_of($class, FormRequest::class)) {
            return [];
        }
        if (\in_array($class, $visited, true)) {
            return [];
        }
        $visited[] = $class;

        $violations = [];

        foreach (self::payloadFields($class) as $name => $type) {
            // Recurse: a bare id two Data objects deep is still a bare id, and
            // the caller has no reason to enumerate nested types by hand.
            foreach ($type['classes'] as $nested) {
                $violations = [...$violations, ...$this->checkPayload($nested, $root, $visited)];
            }

            if ($type['bare'] === null) {
                continue;
            }

            $model = $this->tenantScopedNamedBy($name);
            if ($model === null) {
                continue;
            }

            $where = $class === $root ? "{$class}::\${$name}" : "{$root} -> {$class}::\${$name}";

            $violations[] = new Violation(
                self::TENANT_SCOPED_IDS_MUST_BE_TYPED,
                $where,
                "is a bare {$type['bare']} naming {$model}, which is #[TenantScoped]. "
                . 'Type it as the model so it is resolved and scoped; a raw id is not.',
            );
        }

        return $violations;
    }

    /**
     * Constructor parameters and declared properties alike: a form request may
     * assign a field in its body rather than promote it.
     *
     * @param class-string $class
     * @return array<string, array{bare: string|null, classes: list<class-string>}>
     */
    private static function payloadFields(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $fields = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $fields[$parameter->getName()] = self::describeType($parameter->getType());
        }

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && !isset($fields[$property->getName()])) {
                $fields[$property->getName()] = self::describeType($property->getType());
            }
        }

        return $fields;
    }

    /** @return array{bare: string|null, classes: list<class-string>} */
    private static function describeType(?\ReflectionType $type): array
    {
        $classes = [];
        foreach (self::membersOf($type) as $member) {
            if (!$member->isBuiltin()) {
                /** @var class-string $name */
                $name = $member->getName();
                $classes[] = $name;
            }
        }

        return ['bare' => self::bareIdType($type), 'classes' => $classes];
    }

    /**
     * The part of a type that can carry a raw id, as written -- `int|string`,
     * `mixed` -- or null when every member is a class. Each member of a union
     * counts: `int|string $scriptId` is as bare as `string $scriptId`, and an
     * untyped one is barer still.
     */
    private static function bareIdType(?\ReflectionType $type): ?string
    {
        if ($type === null) {
            return 'untyped value';
        }

        $bare = array_values(array_filter(
            array_map(static fn (\ReflectionNamedType $t): string => $t->getName(), self::membersOf($type)),
            static fn (string $name): bool => \in_array($name, ['string', 'int', 'array', 'mixed'], true),
        ));

        return $bare === [] ? null : implode('|', $bare);
    }

    /** @return list<\ReflectionNamedType> */
    private static function membersOf(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type];
        }
        if ($type instanceof \ReflectionUnionType) {
            return array_values(array_filter($type->getTypes(), static fn ($t): bool => $t instanceof \ReflectionNamedType));
        }

        return [];   // an intersection is of classes, never a raw id
    }

    /**
     * `siteId`, `site_id`, `siteID`, `site` and `siteIds` all name Site.
     *
     * @return class-string|null
     */
    private function tenantScopedNamedBy(string $property): ?string
    {
        $candidate = strtolower((string) preg_replace('/(_ids?|ids?)$/i', '', $property));

        return $this->tenantScoped[$candidate] ?? $this->tenantScoped[strtolower($property)] ?? null;
    }

    /**
     * Every model class a parameter could hold, including through a union and
     * including the base Model class or a supertype models implement.
     *
     * @return list<string>
     */
    private static function modelTypesOf(\ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if ($type === null) {
            return [];
        }

        $candidates = $type instanceof \ReflectionNamedType
            ? [$type]
            : ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType
                ? $type->getTypes()
                : []);

        $models = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof \ReflectionNamedType || $candidate->isBuiltin()) {
                continue;
            }

            $name = $candidate->getName();

            // is_a with allow_string also matches Model itself and any
            // interface or abstract supertype a model implements, which
            // is_subclass_of does not.
            if ($name === Model::class || is_a($name, Model::class, true)) {
                $models[] = $name;
            }
        }

        return $models;
    }
}
