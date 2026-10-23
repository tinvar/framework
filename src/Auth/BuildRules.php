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
 * The panel-binding rule needs panels, which are a later phase.
 */
final class BuildRules
{
    public const string AUTHORIZE_EVERY_MODEL_INPUT = 'authorize-every-model-input';
    public const string TENANT_SCOPED_IDS_MUST_BE_TYPED = 'tenant-scoped-ids-must-be-typed';
    public const string AUTHORIZE_MUST_NAME_A_SUBJECT = 'authorize-must-name-a-subject';
    public const string ENTRY_POINT_MUST_NOT_BE_STATIC = 'entry-point-must-not-be-static';
    public const string SHARED_QUERY_MUST_NOT_READ_THE_USER = 'shared-query-must-not-read-the-user';

    /**
     * What a `shared: true` query may not touch.
     *
     * Each would make the answer depend on something the cache key no longer
     * carries, so two users would read one entry.
     */
    private const array USER_DEPENDENT = [
        'auth()' => 'the current user',
        '->user(' => 'the current user',
        'AuthContext' => 'the current user',
        '->can(' => 'a policy decision',
        'can(' => 'a policy decision',
        'Panel::current' => 'the current panel',
        'acrossTenants' => 'another tenant',
        'withinReseller' => 'another reseller',
    ];

    /** Attributes that make a class's __invoke a routable entry point. */
    private const array CLASS_MARKERS = [Page::class, Layout::class, Query::class];

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
            "Build rules failed:\n  " . implode("\n  ", array_map(strval(...), $violations))
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
            $checks = $classLevel
                ? [...self::attributes($reflection, Authorize::class), ...self::attributes($method, Authorize::class)]
                : self::attributes($method, Authorize::class);

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
            foreach ($checks as $check) {
                $argument = $check->argument;

                if ($argument === null) {
                    $violations[] = new Violation(
                        self::AUTHORIZE_MUST_NAME_A_SUBJECT,
                        "{$where}()",
                        "declares #[Authorize('{$check->ability}')] with no subject. Name the model-typed "
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
                        "declares #[Authorize('{$check->ability}', '{$argument}')], but '{$argument}' is "
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

                $covered[] = $argument;
            }

            if ($exempt) {
                continue;
            }

            // The same hazard as a bare id in a payload, on the path that has
            // no validation at all: a body that looks the row up itself runs
            // unscoped and unauthorized.
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
                    continue;
                }
                if (!\in_array($type->getName(), ['string', 'int', 'array'], true)) {
                    continue;
                }

                $model = $this->tenantScopedNamedBy($parameter->getName());
                if ($model === null) {
                    continue;
                }

                $violations[] = new Violation(
                    self::TENANT_SCOPED_IDS_MUST_BE_TYPED,
                    "{$where}(\${$parameter->getName()})",
                    "is a bare {$type->getName()} naming {$model}, which is #[TenantScoped]. "
                    . 'Type it as the model so it is resolved and scoped; a raw id is not.',
                );
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
            if (\is_string($class) && is_a($class, Model::class, true)) {
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
        $methods = [];
        $hasClassMarker = false;

        foreach (self::CLASS_MARKERS as $marker) {
            if ($reflection->getAttributes($marker) !== []) {
                $hasClassMarker = true;
                break;
            }
        }

        if ($hasClassMarker && $reflection->hasMethod('__invoke')) {
            $methods[] = [$reflection->getMethod('__invoke'), true];
        }

        // A BatchQuery has no __invoke; loadMany is its entry point.
        if ($hasClassMarker && !$reflection->hasMethod('__invoke') && $reflection->hasMethod('loadMany')) {
            $methods[] = [$reflection->getMethod('loadMany'), true];
        }

        // An #[Action] marks its own method, wherever it sits, including on a
        // class that is also a page.
        foreach ($reflection->getMethods() as $method) {
            if ($method->getAttributes(Action::class) === []) {
                continue;
            }
            if ($method->getName() === '__invoke' && $hasClassMarker) {
                continue;   // already added
            }
            $methods[] = [$method, false];
        }

        return $methods;
    }

    /**
     * `shared: true` drops the user from the cache key, so the body must not
     * be able to vary by user.
     *
     * This reads the class's own source, not its call graph. Following the
     * graph needs a static analyser; saying so is better than implying a
     * guarantee this does not give. It catches the direct case, which is the
     * one people write.
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

        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return [];
        }

        $source = implode('', \array_slice(file($file) ?: [], $start - 1, $end - $start + 1));
        $violations = [];

        foreach (self::USER_DEPENDENT as $needle => $what) {
            if (!str_contains($source, $needle)) {
                continue;
            }

            $violations[] = new Violation(
                self::SHARED_QUERY_MUST_NOT_READ_THE_USER,
                $class,
                "is #[Query(shared: true)] but mentions '{$needle}', which reads {$what}. "
                . 'A shared query drops the user from its cache key, so two users read one entry. '
                . 'Either stop reading it, or drop shared.',
            );
        }

        return $violations;
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
            if ($type['class'] !== null) {
                $violations = [...$violations, ...$this->checkPayload($type['class'], $root, $visited)];
                continue;
            }

            if (!\in_array($type['builtin'], ['string', 'int', 'array'], true)) {
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
                "is a bare {$type['builtin']} naming {$model}, which is #[TenantScoped]. "
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
     * @return array<string, array{builtin: string|null, class: class-string|null}>
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

    /** @return array{builtin: string|null, class: class-string|null} */
    private static function describeType(?\ReflectionType $type): array
    {
        if (!$type instanceof \ReflectionNamedType) {
            return ['builtin' => null, 'class' => null];
        }

        if ($type->isBuiltin()) {
            return ['builtin' => $type->getName(), 'class' => null];
        }

        /** @var class-string $name */
        $name = $type->getName();

        return ['builtin' => null, 'class' => $name];
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

    /**
     * @template T of object
     * @param \ReflectionClass<object>|\ReflectionMethod $owner
     * @param class-string<T> $attribute
     * @return list<T>
     */
    private static function attributes(\ReflectionClass|\ReflectionMethod $owner, string $attribute): array
    {
        return array_map(
            static fn (\ReflectionAttribute $a): object => $a->newInstance(),
            $owner->getAttributes($attribute),
        );
    }
}
