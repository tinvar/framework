<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\AdminResource;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\ClassAttributes;
use Tinvar\Attributes\Cors;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Throttle;
use Tinvar\Attributes\Uploaded;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PanelBinding;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\KnownAttributes;
use Tinvar\Support\Str;
use Tinvar\Storage\FileTypes;
use Tinvar\Uploads\Upload;
use Tinvar\Uploads\UploadList;
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

    /**
     * @param (\Closure(class-string): object)|null $resolver
     * @param list<class-string<\Tinvar\Orm\Model>> $models the application's models, which
     *        tenant-scoped-ids-must-be-typed reads as each class registers (ADR 0105)
     */
    public function __construct(private readonly ?\Closure $resolver = null, private readonly array $models = []) {}

    /**
     * @param class-string $class
     * @return list<CompiledAction>
     */
    public function register(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        // A Tinvar attribute naming no class would be matched by nothing, and
        // what it declares would not hold (KnownAttributes).
        KnownAttributes::check($reflection);

        // An admin resource's declarations are held to its model here, where
        // its actions register: a column naming no property, a bulk action
        // naming no method, is a refusal at boot, not a 500 on its list
        // (ADR 0150).
        if (is_subclass_of($class, \Tinvar\Resources\Resource::class) || AdminResource::of($class) !== null) {
            try {
                \Tinvar\Resources\ResourceDefinition::of($class);
            } catch (\LogicException $e) {
                throw new ActionException($e->getMessage(), 0, $e);
            }
        }

        // A bare id naming a tenant's model, in an action's parameters or the
        // form request it takes, reaches the body unscoped (ADR 0105).
        $bare = BuildRules::bareTenantIds($class, $this->models);
        if ($bare !== null) {
            throw new ActionException($bare);
        }


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
            // An endpoint's own bucket and cross-origin policy (ADR 0074) are
            // read by the endpoint registry alone. On an action they would
            // limit and open nothing, which its author would not guess.
            // On the class, its ancestors' and its traits' count too: they
            // limit and open nothing here either.
            if ($method->getAttributes(Throttle::class) !== [] || $method->getAttributes(Cors::class) !== []
                || ClassAttributes::of($reflection, Throttle::class) !== [] || ClassAttributes::of($reflection, Cors::class) !== []) {
                throw new ActionException(
                    "{$class}::{$method->getName()}() is an action under #[Throttle] or #[Cors], which only an endpoint reads. "
                    . 'An action spends throttle:actions and is called from this origin alone.',
                );
            }

            // Silently ignoring a class-level declaration is its own hazard:
            // the author wrote it expecting coverage. Say so instead.
            if ($classDeclares
                && $method->getAttributes(Authorize::class) === []
                && $method->getAttributes(NoAuthorization::class) === []) {
                throw ActionException::classLevelDeclaration($class, $method->getName());
            }

            $compiled[] = $this->add($this->compile($reflection, $method, $attribute->newInstance()));
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

    /** @param \ReflectionClass<object> $reflection */
    private function compile(
        \ReflectionClass $reflection,
        \ReflectionMethod $method,
        Action $declaration,
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
        // An #[AdminResource]'s actions (ADR 0150) are Resource's, written
        // once for every model: a check naming the ORM's base Model names the
        // resource's model, and a parameter typed as it is bound as one.
        $resource = AdminResource::of($class);
        $checks = array_map(
            static fn (Authorize $check): Authorize => $resource !== null && $check->argument === Model::class
                ? new Authorize($check->ability, $resource->model)
                : $check,
            Authorize::declaredOn($class, $method->getName()),
        );
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
            if ($uploaded !== [] && (!$type instanceof \ReflectionNamedType || !\in_array($type->getName(), [Upload::class, UploadList::class], true))) {
                throw new ActionException("{$where}: \${$name} carries #[Uploaded] and is not typed Upload or UploadList.");
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
            if ($typeName === Model::class) {
                $typeName = $resource !== null ? $resource->model : throw ActionException::unbindable(
                    $where,
                    $name,
                    Model::class . ', which names no model: type it as one, or -- on a Tinvar\\Resources\\Resource -- declare the class an #[AdminResource]',
                );
            }

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
            if ($typeName === Upload::class || $typeName === UploadList::class) {
                $uploads[$name] = self::declaredUpload("{$where}: \${$name}", $typeName, $uploaded);
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

        // An upload inside a form request is a field of the form, posted
        // flat under its name as a nested model is, and held to everything
        // an upload parameter is -- verified with the rest of the input,
        // claimed in the body's transaction (ADR 0127).
        foreach ($formRequests as $requestClass) {
            foreach (self::uploadFieldsOf($requestClass) as $field => $parameter) {
                $type = $parameter->getType();
                \assert($type instanceof \ReflectionNamedType);
                if (isset($uploads[$field]) || isset($models[$field]) || isset($nestedModels[$field])) {
                    throw new ActionException("{$where}: {$requestClass}::\${$field} takes an upload under a name the action already gives another input.");
                }
                $uploads[$field] = self::declaredUpload("{$where}: {$requestClass}::\${$field}", $type->getName(), $parameter->getAttributes(Uploaded::class));
                if ($type->allowsNull() || $parameter->isDefaultValueAvailable()) {
                    $optionalUploads[$field] = true;
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
            middleware: Middleware::declaredOn($class, $method->getName()),
            exempt: $exempt,
            subjectArgument: $subjectArgument,
            optionalModels: $optional,
            classLevelChecks: $inherited,
            tenantFrom: self::tenantFromOf($where, $class, $method->getName()),
            panels: self::panelsOf($where, $class, $method->getName(), $resource),
            uploads: $uploads,
            optionalUploads: $optionalUploads,
            transaction: $declaration->transaction,
            host: self::hostOf($where, $class, $method->getName()),
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
        if ($declared->to !== null && (!isset($models[$declared->to]) || isset($optional[$declared->to]))) {
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

        // Uploads' own bytes live under these, and pruning sweeps them on its
        // own clock whatever record names a file there: a file kept under one
        // would be deleted from under its record.
        foreach ([\Tinvar\Uploads\UploadEndpoint::INCOMING_PREFIX, \Tinvar\Uploads\UploadEndpoint::PARTIAL_PREFIX] as $reserved) {
            if ($declared->prefix === $reserved || str_starts_with($declared->prefix, "{$reserved}/")) {
                throw new ActionException(
                    "{$where}: \${$name}'s prefix '{$declared->prefix}' is under {$reserved}/, which uploads keep their own bytes under and pruning sweeps.",
                );
            }
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
    private static function panelsOf(string $where, string $class, string $method, ?AdminResource $resource = null): array
    {
        // An #[AdminResource]'s actions are in its panel, as its pages are.
        $panels = [...Panel::declaredOn($class, $method), ...($resource === null ? [] : [$resource->panelDeclaration()])];

        try {
            PanelBinding::of($panels);
        } catch (\LogicException $e) {
            throw new ActionException("{$where}: {$e->getMessage()}", 0, $e);
        }

        return $panels;
    }

    /**
     * An action's #[Domain]: its method's, or its holder's, the nearest
     * winning (ADR 0149).
     *
     * @param class-string $class
     */
    private static function hostOf(string $where, string $class, string $method): ?\Tinvar\Routing\HostPattern
    {
        try {
            return \Tinvar\Attributes\Domain::declaredOn($class, $method);
        } catch (\InvalidArgumentException $e) {
            throw new ActionException("{$where}: {$e->getMessage()}", 0, $e);
        }
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
     * What an upload parameter or field declares, held to its type: one
     * file is an Upload, more an UploadList -- the type says which the body
     * is handed, the declaration how many. There is no default: no upload
     * is ever taken unlimited.
     *
     * @param list<\ReflectionAttribute<Uploaded>> $uploaded
     */
    private static function declaredUpload(string $where, string $typeName, array $uploaded): Uploaded
    {
        if ($uploaded === []) {
            throw new ActionException("{$where} takes an upload and declares no #[Uploaded] saying what may be uploaded to it.");
        }
        $declared = $uploaded[0]->newInstance();
        if ($typeName === Upload::class && $declared->maxFiles !== 1) {
            throw new ActionException("{$where} takes one Upload, and declares maxFiles: {$declared->maxFiles}; take an UploadList for more.");
        }
        if ($typeName === UploadList::class && ($declared->maxFiles < 2 || $declared->maxFiles > Uploaded::MAX_FILES)) {
            throw new ActionException("{$where} takes an UploadList, of between 2 and " . Uploaded::MAX_FILES . ' files by its maxFiles; take an Upload for one.');
        }

        return $declared;
    }

    /**
     * A form request's constructor parameters that take an upload, by
     * name; one that carries #[Uploaded] and is not typed Upload or
     * UploadList is refused, as it is on an action.
     *
     * @param class-string $class
     * @return array<string, \ReflectionParameter>
     */
    private static function uploadFieldsOf(string $class): array
    {
        $fields = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $typed = $type instanceof \ReflectionNamedType && \in_array($type->getName(), [Upload::class, UploadList::class], true);
            if ($parameter->getAttributes(Uploaded::class) !== [] && !$typed) {
                throw new ActionException("{$class}::\${$parameter->getName()} carries #[Uploaded] and is not typed Upload or UploadList.");
            }
            if ($typed) {
                $fields[$parameter->getName()] = $parameter;
            }
        }

        return $fields;
    }
}
