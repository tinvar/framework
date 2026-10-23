<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Support\Str;
use Tinvar\Validation\FormRequest;

/**
 * Wire name to #[Action] method.
 *
 * Compilation is also where an action is checked: a model-typed input with no
 * check naming it is refused here rather than at the first request, which is
 * the same posture the router takes with unbindable loader parameters.
 */
final class ActionRegistry
{
    /** @var array<string, CompiledAction> */
    private array $actions = [];

    /** @var array<class-string, object> */
    private array $instances = [];

    /** @param (\Closure(class-string): object)|null $resolver */
    public function __construct(private readonly ?\Closure $resolver = null) {}

    /** @param class-string $class @return list<CompiledAction> */
    public function register(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $classExempt = $reflection->getAttributes(NoAuthorization::class) !== [];
        $classChecks = array_map(
            static fn (\ReflectionAttribute $a): Authorize => $a->newInstance(),
            $reflection->getAttributes(Authorize::class),
        );
        $classMiddleware = self::middlewareOf($reflection->getAttributes(Middleware::class));

        $compiled = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attribute = $method->getAttributes(Action::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }
            if ($method->isStatic()) {
                throw new ActionException("{$class}::{$method->getName()}() is static; an action is an instance method.");
            }

            $compiled[] = $this->add($this->compile($reflection, $method, $attribute->newInstance(), $classChecks, $classExempt, $classMiddleware));
        }

        return $compiled;
    }

    /** @param list<class-string> $classes @return list<CompiledAction> */
    public function registerAll(array $classes): array
    {
        $out = [];
        foreach ($classes as $class) {
            $out = [...$out, ...$this->register($class)];
        }

        return $out;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @param list<Authorize>          $classChecks
     * @param list<class-string>       $classMiddleware
     */
    private function compile(
        \ReflectionClass $reflection,
        \ReflectionMethod $method,
        Action $declaration,
        array $classChecks,
        bool $classExempt,
        array $classMiddleware,
    ): CompiledAction {
        $class = $reflection->getName();
        $where = "{$class}::{$method->getName()}()";

        $checks = [
            ...$classChecks,
            ...array_map(
                static fn (\ReflectionAttribute $a): Authorize => $a->newInstance(),
                $method->getAttributes(Authorize::class),
            ),
        ];
        $exempt = $classExempt || $method->getAttributes(NoAuthorization::class) !== [];
        $covered = array_values(array_filter(array_map(
            static fn (Authorize $check): ?string => $check->argument,
            $checks,
        )));

        $models = [];
        $formRequests = [];
        $nestedModels = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType) {
                throw ActionException::unbindable($where, $name, (string) $type);
            }

            if ($type->isBuiltin()) {
                if (!\in_array($type->getName(), ['string', 'int', 'float', 'bool', 'array'], true)) {
                    throw ActionException::unbindable($where, $name, $type->getName());
                }
                continue;
            }

            $typeName = $type->getName();

            if (is_subclass_of($typeName, Model::class)) {
                // Checked at compile time, not trusted to a build step that
                // might not run: a model input with no check naming it must
                // not reach a body.
                if (!$exempt && !\in_array($name, $covered, true)) {
                    throw ActionException::unguarded($where, $name, $typeName);
                }
                $models[$name] = $typeName;
                continue;
            }

            if (is_subclass_of($typeName, FormRequest::class)) {
                $formRequests[$name] = $typeName;

                // A model-typed field inside a form request is resolved and
                // reaches the body exactly like a parameter, so it needs a
                // check exactly like a parameter.
                foreach (self::modelFieldsOf($typeName) as $field => $fieldModel) {
                    $existing = $nestedModels[$field] ?? $models[$field] ?? null;
                    if ($existing !== null && $existing !== $fieldModel) {
                        throw ActionException::conflictingField($where, $field, $existing, $fieldModel);
                    }
                    if (!$exempt && !\in_array($field, $covered, true)) {
                        throw ActionException::unguardedField($where, $typeName, $field, $fieldModel);
                    }
                    $nestedModels[$field] = $fieldModel;
                }
                continue;
            }

            if ($typeName === Request::class) {
                continue;
            }

            throw ActionException::unbindable($where, $name, $typeName);
        }

        // The record the action acts on: the model its first #[Authorize]
        // names. Rules that exempt "the row being edited" read this.
        $subjectArgument = null;
        foreach ($covered as $argument) {
            if (isset($models[$argument]) || isset($nestedModels[$argument])) {
                $subjectArgument = $argument;
                break;
            }
        }

        return new CompiledAction(
            name: $declaration->name ?? Str::shortName($class) . '.' . $method->getName(),
            class: $class,
            method: $method->getName(),
            checks: $checks,
            models: $models,
            formRequests: $formRequests,
            nestedModels: $nestedModels,
            middleware: [...$classMiddleware, ...self::middlewareOf($method->getAttributes(Middleware::class))],
            exempt: $exempt,
            subjectArgument: $subjectArgument,
        );
    }

    private function add(CompiledAction $action): CompiledAction
    {
        $existing = $this->actions[$action->name] ?? null;
        if ($existing !== null) {
            throw ActionException::duplicate($action->name, $existing->where(), $action->where());
        }

        return $this->actions[$action->name] = $action;
    }

    public function has(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    public function get(string $name): CompiledAction
    {
        return $this->actions[$name] ?? throw ActionException::unknown($name);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->actions);
    }

    /** @return list<CompiledAction> */
    public function all(): array
    {
        return array_values($this->actions);
    }

    public function instanceFor(CompiledAction $action): object
    {
        return $this->instances[$action->class] ??= $this->resolver !== null
            ? ($this->resolver)($action->class)
            : new ($action->class)();
    }

    /**
     * Model-typed constructor fields of a form request.
     *
     * @param class-string<FormRequest> $class
     * @return array<string, class-string>
     */
    private static function modelFieldsOf(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $fields = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_subclass_of($type->getName(), Model::class)) {
                $fields[$parameter->getName()] = $type->getName();
            }
        }

        return $fields;
    }

    /**
     * @param list<\ReflectionAttribute<Middleware>> $attributes
     * @return list<class-string>
     */
    private static function middlewareOf(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute->newInstance()->classes as $class) {
                if (!\in_array($class, $out, true)) {
                    $out[] = $class;
                }
            }
        }

        return $out;
    }
}
