<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Channel;
use Tinvar\Attributes\Endpoint;
use Tinvar\Attributes\Export;
use Tinvar\Attributes\Import;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Support\KnownAttributes;
use Tinvar\Data\Data;
use Tinvar\Data\DataFields;
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
 * #[Query], #[Export], #[Import], #[Action], and #[Get], #[Post] or
 * #[Endpoint] on a method -- never by whether the method already declares a
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
 *      #[TenantScoped] model (siteId, scriptId) fails the build." It reads the
 *      application's models, which a Kernel hands each registry, and finds
 *      one not listed in the namespace of one that is or of one an entry
 *      point takes, or anywhere the application's composer.json autoloads
 *      its classes (ApplicationClasses, ADR 0122); the registries run it on
 *      each class as it registers, over the payloads it takes too, and
 *      `tinvar check` over every one (payloadsTakenBy()). ADR 0105.
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
 *     admin page answered any signed-in caller. An #[Export] or #[Import]
 *     is held to it under the same name: it is started by name from
 *     /_export or /_import, and one with no model input -- every order, as
 *     a file -- would otherwise answer anyone in its panel (ADR 0070).
 *
 *   attributes-must-name-a-class
 *     An attribute under Tinvar\ whose class does not exist -- a misspelling,
 *     or a name from before the split once no alias is made for it -- is
 *     matched by nothing, so what it declares silently does not hold: a
 *     model's #[TenantScoped] among them (KnownAttributes, ADR 0055).
 *     Checked on every entry point, payload and model.
 *
 *   panel-only-query-must-be-bound
 *     "an action or query reachable only from admin-panel pages with no panel
 *      binding fails the build." Checked where the Kernel is built, by
 *      the entry checks' allowlist check, not here: it needs the panel registry
 *      and the routes' query allowlists, and this class sees one class at a
 *      time. A query with no #[Panel] and outside every panel's namespace,
 *      which only routes in a panel may call, fails.
 *
 *   panel-only-action-must-be-bound
 *     Its half for actions, by the entry checks' action callers as the
 *     Kernel is built. Nothing PHP holds relates an action to the pages that
 *     call it, so the renderer build reads it from source -- each page's
 *     modules, its client components among them, for the actions they
 *     import -- into the fragment manifest (ADR 0095). An action with no
 *     #[Panel] and outside every panel's namespace, which only pages in a
 *     panel call, fails.
 */
final class BuildRules
{
    public const string AUTHORIZE_EVERY_MODEL_INPUT = 'authorize-every-model-input';
    public const string TENANT_SCOPED_IDS_MUST_BE_TYPED = 'tenant-scoped-ids-must-be-typed';
    public const string AUTHORIZE_MUST_NAME_A_SUBJECT = 'authorize-must-name-a-subject';
    public const string ENTRY_POINT_MUST_NOT_BE_STATIC = 'entry-point-must-not-be-static';
    public const string SHARED_QUERY_MUST_NOT_READ_THE_USER = 'shared-query-must-not-read-the-user';
    public const string QUERY_MUST_DECLARE_AUTHORIZATION = 'query-must-declare-authorization';
    public const string CHANNEL_MUST_DECLARE_AUTHORIZATION = 'channel-must-declare-authorization';
    public const string PANEL_ONLY_QUERY_MUST_BE_BOUND = 'panel-only-query-must-be-bound';
    public const string PANEL_ONLY_ACTION_MUST_BE_BOUND = 'panel-only-action-must-be-bound';
    public const string ATTRIBUTES_MUST_NAME_A_CLASS = 'attributes-must-name-a-class';
    public const string FIELD_ENUMS_NAME_DATA_FIELDS = 'field-enums-name-data-fields';

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
        // A page's shared data is its visitor's (ADR 0060): a provider
        // reads the session it was built in, and a render's reads hold
        // what was produced for it.
        \Tinvar\Data\SharedData::class => "a page's shared data, which is its visitor's",
        \Tinvar\Data\SharedReads::class => "a page's shared data, which is its visitor's",
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
        '->withinSubtree(' => 'the tenants below this one',
        '->grantSubtree(' => 'the tenants below this one',
        'Panel::current' => 'the current panel',
        'PanelContext' => 'the current panel',
        '->currentOrNull(' => 'the current panel',
    ];

    /** @var array<string, class-string> lowercased model name => class */
    private array $tenantScoped = [];

    /** @var array<string, true> the namespaces the application's models are in */
    private array $namespaces = [];

    /** @var list<class-string<Model>> the application's models, held to attributes-must-name-a-class */
    private array $models = [];

    /** @var array<string, true> the top namespaces of the application's models and entry points: whose classes are looked for */
    private array $roots = [];

    private readonly ApplicationClasses $classes;

    /**
     * @param list<class-string<Model>> $models every model the application declares
     * @param ApplicationClasses|null $classes where a model no list holds is looked for besides:
     *        the application's composer.json's PSR-4 map, unless given
     */
    public function __construct(array $models = [], ?ApplicationClasses $classes = null)
    {
        $this->models = $models;
        $this->classes = $classes ?? ApplicationClasses::fromComposer();
        foreach ($models as $model) {
            $this->namespaces[self::namespaceOf($model)] = true;
            $this->roots[self::topOf($model)] = true;
            if (self::isTenantScoped($model)) {
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

        foreach ($this->models as $model) {
            $violations = [...$violations, ...self::checkKnownAttributes($model)];
        }

        return [...$violations, ...$this->checkEntryPoints($entryPoints, $payloads)];
    }

    /**
     * The rules over entry points and the payloads they take, without the
     * models' own: what a registry runs on each class as it registers it,
     * given the application's models (ADR 0007, 0105).
     *
     * @param list<class-string> $entryPoints
     * @param list<class-string> $payloads
     * @return list<Violation>
     */
    public function checkEntryPoints(array $entryPoints, array $payloads = []): array
    {
        $violations = [];

        foreach ($entryPoints as $class) {
            $this->learnNamespaces($class);
        }

        foreach ($entryPoints as $class) {
            $violations = [...$violations, ...self::checkKnownAttributes($class)];
            $violations = [...$violations, ...$this->checkEntryPoint($class)];
            $violations = [...$violations, ...self::checkQueryDeclaresAuthorization($class)];
            $violations = [...$violations, ...self::checkChannelDeclaresAuthorization($class)];
            $violations = [...$violations, ...self::checkSharedQuery($class)];
        }

        // Every Data object and form request an entry point takes, whether
        // or not the caller listed it. A list kept by hand is a list a new
        // form request is missing from, and the bare id in it then passed in
        // silence. Only with a model to name -- one listed, one in the
        // namespace of a model an entry point takes, or one the
        // application's autoload map may hold: without one the rule cannot
        // fire.
        if ($this->mayName()) {
            $payloads = array_values(array_unique([...$payloads, ...self::payloadsTakenBy($entryPoints)]));
        }

        foreach ($payloads as $class) {
            $violations = [...$violations, ...self::checkKnownAttributes($class)];
            $violations = [...$violations, ...$this->checkPayload($class, $class, [])];
        }

        return $violations;
    }

    /**
     * tenant-scoped-ids-must-be-typed alone, over entry points and every
     * payload they take: what the registries whose own checks are the rest
     * of the rules -- actions, endpoints, channels -- run on each class as it
     * registers (ADR 0105).
     *
     * @param list<class-string> $entryPoints
     * @return list<Violation>
     */
    public function checkTenantScopedIds(array $entryPoints): array
    {
        $violations = [];
        foreach ($entryPoints as $class) {
            $this->learnNamespaces($class);
        }
        foreach ($entryPoints as $class) {
            $reflection = new \ReflectionClass($class);
            foreach (self::entryMethods($reflection) as [$method]) {
                if (!$method->isStatic()) {
                    $violations = [...$violations, ...$this->checkBareIds($reflection, $method, "{$class}::{$method->getName()}")];
                }
            }
        }
        if (!$this->mayName()) {
            return $violations;
        }
        foreach (self::payloadsTakenBy($entryPoints) as $payload) {
            $violations = [...$violations, ...$this->checkPayload($payload, $payload, [])];
        }

        return $violations;
    }

    /**
     * Why a class a registry is registering is refused, when it takes a
     * bare id naming a #[TenantScoped] model; null when it does not.
     *
     * @param class-string $class
     * @param list<class-string<Model>> $models the application's models
     */
    public static function bareTenantIds(string $class, array $models): ?string
    {
        $violations = new self($models)->checkTenantScopedIds([$class]);

        return $violations === []
            ? null
            : "{$class} fails the security model's build rules:\n  " . implode("\n  ", array_map(strval(...), $violations));
    }

    /**
     * attributes-must-name-a-class, on one class and its members.
     *
     * @param class-string $class
     * @return list<Violation>
     */
    private static function checkKnownAttributes(string $class): array
    {
        try {
            KnownAttributes::check(new \ReflectionClass($class));
        } catch (\LogicException $e) {
            return [new Violation(self::ATTRIBUTES_MUST_NAME_A_CLASS, $class, $e->getMessage())];
        }

        return [];
    }

    /**
     * The Data objects and form requests the entry points take: the types of
     * every parameter of every routable method -- a page's or layout's
     * loader, an action, a query -- and of every argument a batch query
     * declares in #[Query(args:)]. Ones nested inside them are reached by
     * the payload check itself.
     *
     * @param list<class-string> $entryPoints
     * @return list<class-string>
     */
    public static function payloadsTakenBy(array $entryPoints): array
    {
        $payloads = [];

        foreach ($entryPoints as $class) {
            $reflection = new \ReflectionClass($class);

            foreach (self::entryMethods($reflection) as [$method]) {
                $types = array_values(self::batchArguments($reflection, $method));
                foreach ($method->getParameters() as $parameter) {
                    foreach (self::membersOf($parameter->getType()) as $member) {
                        if (!$member->isBuiltin()) {
                            $types[] = $member->getName();
                        }
                    }
                }

                foreach ($types as $type) {
                    if (is_subclass_of($type, Data::class) || is_subclass_of($type, FormRequest::class)) {
                        $payloads[$type] = $type;
                    }
                }
            }
        }

        return array_values($payloads);
    }

    /**
     * Sort and filter field enums, each held to its #[FieldsOf]: every case
     * a field of the Data class it names, mapped from a column of the same
     * name (DataFields). "Sort, filter and column fields are backed enums or
     * #[In] lists generated from Data fields" -- an enum naming a column no
     * Data field shows would let a request sort by it, so it fails the build
     * here, and at its first use besides.
     *
     * @param list<class-string> $enums
     * @return list<Violation>
     */
    public static function checkFieldEnums(array $enums): array
    {
        $violations = [];
        foreach ($enums as $enum) {
            foreach (DataFields::problemsWith($enum) as $problem) {
                $violations[] = new Violation(self::FIELD_ENUMS_NAME_DATA_FIELDS, $enum, $problem);
            }
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
        $violations = self::checkLoaderlessRoute($reflection);

        foreach (self::entryMethods($reflection) as [$method, $classLevel]) {
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
            $inherited = $classLevel ? 0 : Authorize::classLevelCount($class);

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

            $violations = [...$violations, ...$this->checkBareIds($reflection, $method, $where)];

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
     * The checks of a page or layout that has no loader.
     *
     * "Loaders are optional", and a route without one still carries its
     * #[Authorize] -- which checkEntryPoint() never saw, since it walks
     * entry METHODS and there is none. Authorize('view') with no subject, or
     * Authorize('view', 'site') with nothing to resolve a site, registered
     * cleanly and then failed every request with a 500.
     *
     * With no loader there is no model input to name, so the check names a
     * class. That is the rule a route WITH a loader is already held to --
     * its subject is one of its own parameters or a class, never a model a
     * layout above it happens to resolve -- and it is decided from the class
     * alone, so it holds whatever order the routes register in.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<Violation>
     */
    private static function checkLoaderlessRoute(\ReflectionClass $reflection): array
    {
        $isRoute = $reflection->getAttributes(Page::class) !== []
            || $reflection->getAttributes(Layout::class) !== [];
        if (!$isRoute || $reflection->hasMethod('__invoke')) {
            return [];
        }

        $class = $reflection->getName();
        $violations = [];

        foreach (Authorize::declaredOn($class) as $check) {
            $argument = $check->argument;
            if ($argument !== null && (class_exists($argument) || interface_exists($argument))) {
                continue;
            }

            $violations[] = new Violation(
                self::AUTHORIZE_MUST_NAME_A_SUBJECT,
                $class,
                $argument === null
                    ? "has no loader and declares #[Authorize('{$check->ability}')] with no subject. Name the "
                        . "class it covers, as in Authorize('viewAny', Rack::class)."
                    : "has no loader and declares #[Authorize('{$check->ability}', '{$argument}')], but "
                        . "'{$argument}' is not a class, and with no loader there is no model input for it to "
                        . 'name. Give the route a loader taking the model, or name a class. It would throw on '
                        . 'the first request.',
            );
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
        return array_filter(
            self::batchArguments($reflection, $method),
            static fn (string $class): bool => is_a($class, Model::class, true),
        );
    }

    /**
     * A batch query's per-call arguments as #[Query(args:)] declares them,
     * models or not.
     *
     * @param \ReflectionClass<object> $reflection
     * @return array<string, string> argument => declared type
     */
    private static function batchArguments(\ReflectionClass $reflection, \ReflectionMethod $method): array
    {
        if ($method->getName() !== 'loadMany') {
            return [];
        }

        $attribute = $reflection->getAttributes(Query::class)[0] ?? null;

        return $attribute === null ? [] : $attribute->newInstance()->args;
    }

    /**
     * Routable methods, each flagged with whether a class-level declaration
     * applies to it.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<array{\ReflectionMethod, bool}>
     */
    private static function entryMethods(\ReflectionClass $reflection): array
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

        // A #[Channel]'s parameters are its constructor's: what a client
        // subscribes with, resolved and checked, as a page's loader's are
        // (ADR 0069). The class's declarations cover it, as a page's do.
        if ($reflection->getAttributes(Channel::class) !== [] && $reflection->hasMethod('__construct')) {
            $methods['__construct'] = [$reflection->getMethod('__construct'), true];
        }

        // An export's or an import's body is its __invoke, and its class's
        // declarations cover it, as a query's do: the class is the entry
        // point, started by name from /_export or /_import.
        if (self::isTransfer($reflection) && $reflection->hasMethod('__invoke')) {
            $methods['__invoke'] ??= [$reflection->getMethod('__invoke'), true];
        }

        // An #[Action] marks its own method, wherever it sits, including on a
        // class that is also a page.
        // So does a #[Get], #[Post] or #[Endpoint] (ADR 0067), and as an
        // action's, a class-level declaration covers none of them.
        foreach ($reflection->getMethods() as $method) {
            if ($method->getAttributes(Action::class) === [] && !Endpoint::marks($method)) {
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
        $isQuery = $reflection->getAttributes(Query::class) !== [];
        // An export or an import says who may start it for the same reason:
        // one with no model input -- every order, as a file -- would
        // otherwise need no check at all, and answer anyone in its panel.
        $isTransfer = self::isTransfer($reflection);
        if (!$isQuery && !$isTransfer) {
            return [];
        }

        $entry = $isQuery ? Query::entryMethodOf($class) : '__invoke';
        if (Authorize::declaredOn($class, $entry) !== []) {
            return [];
        }

        $exempt = $reflection->getAttributes(NoAuthorization::class) !== []
            || ($reflection->hasMethod($entry)
                && $reflection->getMethod($entry)->getAttributes(NoAuthorization::class) !== []);
        if ($exempt) {
            return [];
        }

        if ($isTransfer) {
            return [new Violation(
                self::QUERY_MUST_DECLARE_AUTHORIZATION,
                $class,
                'is an #[Export] or #[Import] with no #[Authorize] and no #[NoAuthorization]. It is started by name, '
                . "and nothing else decides who may. Declare who may, as in Authorize('export', Order::class), or "
                . "#[NoAuthorization(reason: '...')] if everyone in its panel may.",
            )];
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

    /** @param \ReflectionClass<object> $reflection */
    private static function isTransfer(\ReflectionClass $reflection): bool
    {
        return $reflection->getAttributes(Export::class) !== [] || $reflection->getAttributes(Import::class) !== [];
    }

    /**
     * A channel says who may subscribe, whether or not it takes a model --
     * as a query must, and for the same reason: with no model input the
     * model-input rule has nothing to demand a check on, and a channel that
     * declared nothing would let in anyone signed in (ADR 0069).
     *
     * @param class-string $class
     * @return list<Violation>
     */
    private static function checkChannelDeclaresAuthorization(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        if ($reflection->getAttributes(Channel::class) === []
            || Authorize::declaredOn($class, '__construct') !== []
            || $reflection->getAttributes(NoAuthorization::class) !== []) {
            return [];
        }

        return [new Violation(
            self::CHANNEL_MUST_DECLARE_AUTHORIZATION,
            $class,
            'is a #[Channel] with no #[Authorize] and no #[NoAuthorization]. Declare who may subscribe, as in '
            . "Authorize('view', 'order'), or #[NoAuthorization(reason: '...')] if everyone it lets in may.",
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
     *  - The same TEXTUAL check over its CALL GRAPH: every application
     *    method and function its entry point and constructor reach, followed
     *    through the calls CallGraph can name, with a call it cannot follow
     *    refused (checkSharedCallGraph()).
     *
     * What is NOT checked: the framework's own methods, where the walk
     * stops and the call into them is what the text check reads. A call on
     * a local is followed by what the method gives the local, and one on a
     * value the walk cannot type is refused (ADR 0104).
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

        return [...$violations, ...self::checkSharedCallGraph($reflection)];
    }

    /**
     * The same reads, in what the query's code calls: "the build fails if a
     * shared query's body or call graph reads ..." (tinvar-spec.md, "Query
     * cache"). The application methods and functions reachable from its
     * entry point and constructor (CallGraph) are each held to the textual
     * check its own source is; a call the walk cannot follow is refused, as
     * an unanalysable dependency is.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<Violation>
     */
    private static function checkSharedCallGraph(\ReflectionClass $reflection): array
    {
        $class = $reflection->getName();
        $graph = CallGraph::walk($class, [Query::entryMethodOf($class), '__construct']);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        $violations = [];
        foreach ($graph['refused'] as $refused) {
            $violations[] = new Violation(
                self::SHARED_QUERY_MUST_NOT_READ_THE_USER,
                $class,
                "is #[Query(shared: true)] and its call graph cannot be followed at {$refused['where']}: {$refused['why']}. "
                . 'A shared query drops the user from its cache key, so a call that cannot be read is refused '
                . 'rather than assumed not to read the user.',
            );
        }

        // A framework object the walk stops at is still the object it is: a
        // method called on an AuthContext reads the user, whatever its name.
        foreach ($graph['receivers'] as $receiver) {
            foreach (self::USER_DEPENDENT_TYPES as $forbidden => $what) {
                if (is_a($receiver['class'], $forbidden, true)) {
                    $violations[] = new Violation(
                        self::SHARED_QUERY_MUST_NOT_READ_THE_USER,
                        $class,
                        "is #[Query(shared: true)] but its call graph calls a method of {$forbidden} at {$receiver['where']}, which reads {$what}. "
                        . 'A shared query drops the user from its cache key, so two users read one entry.',
                    );
                }
            }
        }

        foreach ($graph['reached'] as $method) {
            // The query's own source is read whole above.
            if ($method['file'] === $file && $method['start'] >= $start && $method['end'] <= $end) {
                continue;
            }
            foreach (self::USER_DEPENDENT_CALLS as $needle => $what) {
                if (str_contains($method['source'], $needle)) {
                    $violations[] = new Violation(
                        self::SHARED_QUERY_MUST_NOT_READ_THE_USER,
                        $class,
                        "is #[Query(shared: true)] but calls {$method['where']}, which calls '{$needle}' and reads {$what}. "
                        . 'A shared query drops the user from its cache key, so two users read one entry.',
                    );
                }
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
                // The flag reader reads the user, the tenant and the panel, and
                // every flag a body reads keys its answer by what it read
                // (ADR 0094): a shared query's entry is shared only between
                // callers whose flags agree, whoever they are. The reader and
                // nothing wider: Features itself can name another scope, or
                // say who the user is, and is walked like any dependency.
                if ($name === \Tinvar\Features\FlagReader::class) {
                    continue;
                }

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
     * tenant-scoped-ids-must-be-typed on one entry method: its parameters,
     * and a batch query's declared arguments.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<Violation>
     */
    private function checkBareIds(\ReflectionClass $reflection, \ReflectionMethod $method, string $where): array
    {
        $violations = [];

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

        // The same hazard on a batch query, whose per-call arguments are
        // declared on the attribute rather than as parameters. One typed
        // as anything but a model reaches loadMany() as the raw value the
        // renderer sent.
        foreach (self::batchArguments($reflection, $method) as $argument => $type) {
            if (is_a($type, Model::class, true)) {
                continue;
            }

            $model = $this->tenantScopedNamedBy($argument);
            if ($model === null) {
                continue;
            }

            $violations[] = new Violation(
                self::TENANT_SCOPED_IDS_MUST_BE_TYPED,
                "{$where}(\${$argument})",
                "is declared in #[Query(args:)] as {$type}, naming {$model}, which is #[TenantScoped]. "
                . 'Declare it as the model so it is resolved and scoped; a raw id is not.',
            );
        }

        return $violations;
    }

    /**
     * `siteId`, `site_id`, `siteID`, `site` and `siteIds` all name Site --
     * and `scriptRunId`, `script_run_id` and `script_run` all name
     * ScriptRun: the models are keyed by their lowercased short name, which
     * has no underscores, so a snake_case name loses its own before it is
     * looked up.
     *
     * @return class-string|null
     */
    private function tenantScopedNamedBy(string $property): ?string
    {
        $stem = (string) preg_replace('/(_ids?|ids?)$/i', '', $property);
        $candidate = strtolower(str_replace('_', '', $stem));
        $known = $this->tenantScoped[$candidate] ?? $this->tenantScoped[strtolower(str_replace('_', '', $property))] ?? null;
        if ($known !== null) {
            return $known;
        }

        // A model the application did not list, beside one it did or one an
        // entry point takes: `siteId` names Site in each models' namespace.
        // The list is kept by hand, and one missing a model would let a bare
        // id to it pass in silence.
        $studly = ucfirst(str_replace('_', '', ucwords($stem, '_')));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $studly) !== 1) {
            return null;
        }
        foreach (array_keys($this->namespaces) as $namespace) {
            $class = ltrim("{$namespace}\\{$studly}", '\\');
            if (class_exists($class) && is_subclass_of($class, Model::class) && self::isTenantScoped($class)) {
                $this->tenantScoped[$candidate] = $class;

                return $class;
            }
        }

        // And one in a namespace of its own, beside nothing listed or
        // taken: wherever the application's composer.json maps its classes,
        // under the application's own top namespaces (ADR 0122).
        foreach ($this->classes->named($studly, array_map(strval(...), array_keys($this->roots))) as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class) && self::isTenantScoped($class)) {
                $this->tenantScoped[$candidate] = $class;

                return $class;
            }
        }

        return null;
    }

    /** Whether a bare id could name a model here: one listed, one beside a model, or one the autoload map holds. */
    private function mayName(): bool
    {
        return $this->tenantScoped !== [] || $this->namespaces !== []
            || $this->classes->maps(array_map(strval(...), array_keys($this->roots)));
    }

    /** A class's first namespace, or '' for one in none. */
    private static function topOf(string $class): string
    {
        $top = strstr($class, '\\', true);

        return $top === false ? '' : $top;
    }

    /** @param class-string $class */
    private static function isTenantScoped(string $class): bool
    {
        return (new \ReflectionClass($class))->getAttributes(TenantScoped::class, \ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    /**
     * The namespaces of the models a class's entry points take, where a
     * model a bare id names is looked for too.
     *
     * @param class-string $class
     */
    private function learnNamespaces(string $class): void
    {
        $this->roots[self::topOf($class)] = true;
        foreach (self::entryMethods(new \ReflectionClass($class)) as [$method]) {
            foreach ($method->getParameters() as $parameter) {
                foreach (self::modelTypesOf($parameter) as $model) {
                    if (is_subclass_of($model, Model::class)) {
                        $this->namespaces[self::namespaceOf($model)] = true;
                    }
                }
            }
        }
    }

    private static function namespaceOf(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? '' : substr($class, 0, $slash);
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
