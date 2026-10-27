<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Uploaded;
use Tinvar\Auth\PanelBinding;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Str;
use Tinvar\Uploads\FileTypes;
use Tinvar\Uploads\Upload;
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

    /** @param (\Closure(class-string): object)|null $resolver */
    public function __construct(private readonly ?\Closure $resolver = null) {}

    /**
     * @param class-string $class
     * @return list<CompiledAction>
     */
    public function register(string $class): array
    {
        $reflection = new \ReflectionClass($class);

        // Class-level #[Authorize] and #[NoAuthorization] do not COVER action
        // methods. Letting them blanket every method meant one read-only
        // check on the holder satisfied a destructive action, and a single
        // #[NoAuthorization(reason: 'public status ping')] exempted every
        // action added to the class afterwards -- including inherited ones.
        // BuildRules already refuses that shape; the registry must agree,
        // since it is the check that cannot be skipped.
        //
        // A class-level #[Authorize] still RUNS on every action, ahead of the
        // method's own (see compile()): "everything in here is admin-only"
        // was silently dropped the moment a method declared a check of its
        // own, which is exactly when an author stops looking.
        //
        // "The class" includes its ancestors and traits. PHP does not inherit
        // class attributes, so a holder extending an #[Authorize]- and
        // #[Middleware]-carrying base ran with neither.
        $classMiddleware = Middleware::declaredOn($class);
        $classDeclares = Authorize::classLevelCount($class) > 0
            || $reflection->getAttributes(NoAuthorization::class) !== [];

        $compiled = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attribute = $method->getAttributes(Action::class)[0] ?? null;
            if ($attribute === null) {
                continue;
            }
            if ($method->isStatic()) {
                throw new ActionException("{$class}::{$method->getName()}() is static; an action is an instance method.");
            }

            // Silently ignoring a class-level declaration is its own hazard:
            // the author wrote it expecting coverage. Say so instead.
            if ($classDeclares
                && $method->getAttributes(Authorize::class) === []
                && $method->getAttributes(NoAuthorization::class) === []) {
                throw ActionException::classLevelDeclaration($class, $method->getName());
            }

            $compiled[] = $this->add($this->compile($reflection, $method, $attribute->newInstance(), $classMiddleware));
        }

        return $compiled;
    }

    /**
     * @param list<class-string> $classes
     * @return list<CompiledAction>
     */
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
     * @param list<class-string>       $classMiddleware
     */
    private function compile(
        \ReflectionClass $reflection,
        \ReflectionMethod $method,
        Action $declaration,
        array $classMiddleware,
    ): CompiledAction {
        $class = $reflection->getName();
        $where = "{$class}::{$method->getName()}()";

        // The name becomes a generated TypeScript stub, so it is checked
        // here, where it is declared, rather than written into source as is.
        $actionName = $declaration->name ?? Str::shortName($class) . '.' . $method->getName();
        if (!Str::isDottedIdentifier($actionName)) {
            throw ActionException::badName($where, $actionName);
        }

        // The class's checks first, then the method's, and the action must
        // pass every one. Only the method's own count as covering an input.
        $checks = Authorize::declaredOn($class, $method->getName());
        $inherited = Authorize::classLevelCount($class);
        $exempt = $method->getAttributes(NoAuthorization::class) !== [];

        $covered = [];
        foreach ($checks as $index => $check) {
            // A check that names nothing resolvable is a 500 on the first
            // call. BuildRules flags it; so does this, for symmetry.
            if ($check->argument === null) {
                throw ActionException::subjectless($where, $check->ability);
            }
            if ($index >= $inherited) {
                $covered[] = $check->argument;
            }
        }

        $models = [];
        $formRequests = [];
        $nestedModels = [];
        $uploads = [];
        $optionalUploads = [];

        // A name the action and one of its form requests both declare is one
        // input, resolved once. It is optional only if EVERY declaration of
        // it is: OR-ing them let a form request shared with a create form
        // (`?Rack $rack = null`) make a required `Rack $rack` parameter
        // optional, so its check was skipped when the caller left it out and
        // the call ended in a TypeError from the body.
        $optional = [];
        $required = [];

        // Two passes. Collecting parameters first, then nested fields, keeps
        // the collision check from depending on declaration order: with one
        // pass, a form request declared before a colliding model parameter
        // registered cleanly and the nested model silently won.
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($parameter->isVariadic()) {
                throw ActionException::unbindable($where, $name, 'a variadic parameter');
            }

            // A declaration on anything but an Upload would be one nothing
            // reads: the author meant a limit, and there would be none.
            $uploaded = $parameter->getAttributes(Uploaded::class);
            if ($uploaded !== [] && (!$type instanceof \ReflectionNamedType || $type->getName() !== Upload::class)) {
                throw new ActionException("{$where}: \${$name} carries #[Uploaded] and is not typed Upload.");
            }

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
                if ($type->allowsNull() || $parameter->isDefaultValueAvailable()) {
                    $optional[$name] = true;
                } else {
                    $required[$name] = true;
                }
                continue;
            }

            if (is_subclass_of($typeName, FormRequest::class)) {
                $formRequests[$name] = $typeName;
                continue;
            }

            if ($typeName === Request::class) {
                continue;
            }

            // "Uploaded files are referenced only by a server-issued upload
            // ticket": the parameter takes the upload /_upload stored under
            // one, and what may be uploaded to it is declared beside it --
            // there is no default, so no upload is ever taken unlimited.
            if ($typeName === Upload::class) {
                if ($uploaded === []) {
                    throw new ActionException("{$where}: \${$name} takes an Upload and declares no #[Uploaded] saying what may be uploaded to it.");
                }
                $uploads[$name] = $uploaded[0]->newInstance();
                if ($type->allowsNull() || $parameter->isDefaultValueAvailable()) {
                    $optionalUploads[$name] = true;
                }
                continue;
            }

            throw ActionException::unbindable($where, $name, $typeName);
        }

        foreach ($formRequests as $requestClass) {
            foreach (self::modelFieldsOf($requestClass) as $field => $fieldModel) {
                $existing = $nestedModels[$field] ?? $models[$field] ?? null;
                if ($existing !== null && $existing !== $fieldModel) {
                    throw ActionException::conflictingField($where, $field, $existing, $fieldModel);
                }

                // A model-typed field inside a form request is resolved and
                // reaches the body exactly like a parameter, so it needs a
                // check exactly like a parameter.
                if (!$exempt && !\in_array($field, $covered, true)) {
                    throw ActionException::unguardedField($where, $requestClass, $field, $fieldModel);
                }

                $nestedModels[$field] = $fieldModel;
                if (self::fieldIsOptional($requestClass, $field)) {
                    $optional[$field] = true;
                } else {
                    $required[$field] = true;
                }
            }
        }

        $optional = array_diff_key($optional, $required);

        foreach ($uploads as $name => $declared) {
            self::checkUpload($where, $name, $declared, $models, $optional);
        }

        // Every check names an input this method takes, or a class. A class
        // check runs on every action in the class, so one naming an input
        // only some of them take would throw on the first call to the rest.
        foreach ($checks as $index => $check) {
            $argument = $check->argument ?? '';
            if (isset($models[$argument]) || isset($nestedModels[$argument])
                || class_exists($argument) || interface_exists($argument)) {
                continue;
            }

            throw ActionException::unresolvedSubject($where, $check->ability, $argument, $index < $inherited);
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
            name: $actionName,
            class: $class,
            method: $method->getName(),
            checks: $checks,
            models: $models,
            formRequests: $formRequests,
            nestedModels: $nestedModels,
            middleware: array_values(array_unique([...$classMiddleware, ...self::middlewareOf($method->getAttributes(Middleware::class))])),
            exempt: $exempt,
            subjectArgument: $subjectArgument,
            optionalModels: $optional,
            classLevelChecks: $inherited,
            tenantFrom: self::tenantFromOf($where, $class, $method->getName()),
            panels: self::panelsOf($where, $class, $method->getName()),
            uploads: $uploads,
            optionalUploads: $optionalUploads,
        );
    }

    /**
     * An #[Uploaded] declaration that can be held to: attached to a record
     * the action always takes, under a prefix a key can carry, taking only
     * types the framework recognises, up to a size.
     *
     * @param array<string, class-string<Model>> $models the action's own model parameters
     * @param array<string, true> $optional the model inputs that may be absent
     */
    private static function checkUpload(string $where, string $name, Uploaded $declared, array $models, array $optional): void
    {
        // The record is the action's own parameter, resolved and authorized
        // before the upload is looked at, and always there: an upload bound
        // to a record could not be checked against one that was left out.
        if (!isset($models[$declared->to]) || isset($optional[$declared->to])) {
            throw new ActionException(
                "{$where}: \${$name} is attached to '{$declared->to}', which is not a required model parameter of the action.",
            );
        }

        if (!StorageKey::isValidPrefix($declared->prefix)) {
            throw new ActionException(
                "{$where}: \${$name}'s prefix '{$declared->prefix}' is not one a storage key can carry: "
                . 'lowercase letters, digits, _ and -, in segments split by /.',
            );
        }

        if ($declared->types === []) {
            throw new ActionException("{$where}: \${$name} names no types it takes.");
        }
        foreach ($declared->types as $type) {
            if (!FileTypes::known($type)) {
                throw new ActionException(\sprintf(
                    "%s: \$%s takes '%s', which uploads do not recognise; they take %s.",
                    $where,
                    $name,
                    $type,
                    implode(', ', array_keys(FileTypes::EXTENSIONS)),
                ));
            }
        }

        if ($declared->maxBytes < 1) {
            throw new ActionException("{$where}: \${$name} takes files of at most {$declared->maxBytes} bytes, which is none.");
        }
    }

    /**
     * An action's #[Panel] declarations, its holder's and its method's,
     * which must name one panel.
     *
     * @param class-string $class
     * @return list<Panel>
     */
    private static function panelsOf(string $where, string $class, string $method): array
    {
        $panels = Panel::declaredOn($class, $method);

        try {
            PanelBinding::of($panels);
        } catch (\LogicException $e) {
            throw new ActionException("{$where}: {$e->getMessage()}", 0, $e);
        }

        return $panels;
    }

    /**
     * An action's #[TenantFrom]: its method's, or its holder's.
     *
     * @param class-string $class
     * @return class-string|null
     */
    private static function tenantFromOf(string $where, string $class, string $method): ?string
    {
        try {
            return TenantFrom::declaredOn($class, $method);
        } catch (\LogicException|\InvalidArgumentException $e) {
            throw new ActionException("{$where}: {$e->getMessage()}", 0, $e);
        }
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

    /**
     * An unknown name is a 404, and the name is not interpolated: it is the
     * path segment the client sent, and these messages reach logs.
     */
    public function get(string $name): CompiledAction
    {
        return $this->actions[$name] ?? throw new NotFoundException('No action is registered under that name.');
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

    /**
     * A fresh handler per call.
     *
     * Caching one for the life of the registry means a handler that memoises
     * anything leaks it across calls -- and on a long-lived worker, across
     * users and tenants. Construction is cheap; the leak is not.
     */
    public function instanceFor(CompiledAction $action): object
    {
        return $this->resolver !== null
            ? ($this->resolver)($action->class)
            : new ($action->class)();
    }

    /**
     * True when a form request's field may legitimately be absent.
     *
     * @param class-string $class
     */
    private static function fieldIsOptional(string $class, string $field): bool
    {
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $field) {
                return $parameter->getType()?->allowsNull() === true
                    || $parameter->isDefaultValueAvailable();
            }
        }

        return false;
    }

    /**
     * Model-typed constructor fields of a form request.
     *
     * @param class-string<FormRequest> $class
     * @return array<string, class-string<Model>>
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
