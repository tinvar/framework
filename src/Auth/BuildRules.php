<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\TenantScoped;
use Tinvar\Data\Data;
use Tinvar\Orm\Model;
use Tinvar\Support\Str;
use Tinvar\Validation\FormRequest;

/**
 * The security model's build rules, checked statically.
 *
 * These are the rules that make declarative authorization trustworthy: without
 * them, forgetting an #[Authorize] is silent, and the failure is open. Run
 * from CI and from `tinvar build`.
 *
 * Implemented here:
 *
 *   authorize-every-model-input
 *     "every model-typed input of every routable entry point ... needs an
 *      #[Authorize] naming it, or #[NoAuthorization(reason: '...')]. This
 *      includes second and third model arguments."
 *
 *   tenant-scoped-ids-must-be-typed
 *     "a form request or Data field typed string or int whose name refers to a
 *      #[TenantScoped] model (siteId, scriptId) fails the build; it must be
 *      typed as the model so it is resolved and scoped."
 *
 * The panel-binding rule needs panels, which are a later phase.
 */
final class BuildRules
{
    public const string AUTHORIZE_EVERY_MODEL_INPUT = 'authorize-every-model-input';
    public const string TENANT_SCOPED_IDS_MUST_BE_TYPED = 'tenant-scoped-ids-must-be-typed';

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
        }

        foreach ($payloads as $class) {
            $violations = [...$violations, ...$this->checkPayload($class)];
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

        foreach ($this->routableMethods($reflection) as $method) {
            // An explicit, reasoned opt-out is the declared escape hatch.
            if ($this->isExempt($reflection, $method)) {
                continue;
            }

            $covered = $this->coveredArguments($reflection, $method);

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                if (!is_subclass_of($type->getName(), Model::class)) {
                    continue;
                }

                // Only a check naming THIS parameter counts. An
                // Authorize('viewAny', Rack::class) says something about the
                // collection, not about the instance this input resolves to.
                if (\in_array($parameter->getName(), $covered, true)) {
                    continue;
                }
                $name = $parameter->getName();

                $violations[] = new Violation(
                    self::AUTHORIZE_EVERY_MODEL_INPUT,
                    "{$class}::{$method->getName()}(\${$name})",
                    "takes {$type->getName()} but no #[Authorize] names it. Add "
                    . "#[Authorize('<ability>', '{$name}')], or #[NoAuthorization(reason: '...')] "
                    . 'if it genuinely needs none.',
                );
            }
        }

        return $violations;
    }

    /**
     * @param class-string $class
     * @return list<Violation>
     */
    private function checkPayload(string $class): array
    {
        if (!is_subclass_of($class, Data::class) && !is_subclass_of($class, FormRequest::class)) {
            return [];
        }

        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $violations = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
                continue;
            }
            if (!\in_array($type->getName(), ['string', 'int'], true)) {
                continue;
            }

            $model = $this->tenantScopedNamedBy($parameter->getName());
            if ($model === null) {
                continue;
            }

            $violations[] = new Violation(
                self::TENANT_SCOPED_IDS_MUST_BE_TYPED,
                "{$class}::\${$parameter->getName()}",
                "is a bare {$type->getName()} naming {$model}, which is #[TenantScoped]. "
                . 'Type it as the model so it is resolved and scoped; a raw id is not.',
            );
        }

        return $violations;
    }

    /** `siteId`, `site_id` and `site` all name Site. @return class-string|null */
    private function tenantScopedNamedBy(string $property): ?string
    {
        $candidate = strtolower((string) preg_replace('/(_id|Id)$/', '', $property));

        return $this->tenantScoped[$candidate] ?? null;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return list<\ReflectionMethod>
     */
    private function routableMethods(\ReflectionClass $reflection): array
    {
        $methods = [];

        if ($reflection->hasMethod('__invoke')) {
            $methods[] = $reflection->getMethod('__invoke');
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === '__invoke' || $method->isStatic()) {
                continue;
            }
            // An action is a method carrying its own declarations.
            if ($method->getAttributes(Authorize::class) !== []
                || $method->getAttributes(NoAuthorization::class) !== []) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /** @param \ReflectionClass<object> $reflection */
    private function isExempt(\ReflectionClass $reflection, \ReflectionMethod $method): bool
    {
        return $reflection->getAttributes(NoAuthorization::class) !== []
            || $method->getAttributes(NoAuthorization::class) !== [];
    }

    /**
     * Argument names (or classes) an #[Authorize] on the class or the method
     * already covers.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<string>
     */
    private function coveredArguments(\ReflectionClass $reflection, \ReflectionMethod $method): array
    {
        $covered = [];

        foreach ([...$reflection->getAttributes(Authorize::class), ...$method->getAttributes(Authorize::class)] as $attribute) {
            $argument = $attribute->newInstance()->argument;
            if ($argument !== null) {
                $covered[] = $argument;
            }
        }

        return $covered;
    }
}
