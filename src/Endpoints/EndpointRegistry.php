<?php

declare(strict_types=1);

namespace Tinvar\Endpoints;

use Tinvar\Actions\TagInvalidation;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Cors;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Endpoint;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Throttle;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\Violation;
use Tinvar\Data\Data;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Model;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\Domains;
use Tinvar\Routing\HostMatch;
use Tinvar\Routing\HostPattern;
use Tinvar\Routing\PathTemplate;
use Tinvar\Routing\PathTemplateRefused;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\KnownAttributes;
use Tinvar\Support\Str;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Validator;

/**
 * The #[Get], #[Post] and #[Endpoint] methods of the classes an application
 * lists, by name and by path.
 *
 * Compilation is where an endpoint is held to the build rules, as an
 * action is: a model input no #[Authorize] names, a path parameter nothing
 * takes, a return the encoder could not send, a path under the framework's
 * own `/_` -- each refused here, when the application starts, rather than on
 * the first request. What only the whole application can show -- an
 * endpoint and a page that both match one path -- is refused where both
 * registries meet: the Kernel, and `tinvar check` (conflicts()).
 */
final class EndpointRegistry
{
    /** The rule a path two routes both match breaks, in `tinvar check`. */
    public const string PATHS_MUST_NOT_OVERLAP_PAGES = 'endpoint-paths-must-not-overlap-pages';

    /** The rule an endpoint the registry refuses breaks, in `tinvar check`. */
    public const string ENDPOINTS_MUST_COMPILE = 'endpoints-must-compile';

    /** @var array<string, CompiledEndpoint> */
    private array $byName = [];

    /** @var list<CompiledEndpoint> */
    private array $ordered = [];

    private bool $sorted = true;

    /**
     * @param list<class-string<\Tinvar\Orm\Model>> $models the application's models, which
     *        tenant-scoped-ids-must-be-typed reads as each class registers (ADR 0105)
     */
    public function __construct(private readonly array $models = []) {}

    private ?Domains $domains = null;

    /**
     * @param class-string $class
     * @return list<CompiledEndpoint>
     */
    public function register(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        KnownAttributes::check($reflection);

        // A bare id naming a tenant's model reaches the method unscoped
        // (ADR 0105).
        $bare = BuildRules::bareTenantIds($class, $this->models);
        if ($bare !== null) {
            throw new EndpointException($bare);
        }

        // As an action holder's: a check or an exemption on the class covers
        // no method. A class-level #[Authorize] still runs ahead of each
        // method's own; a class-level #[NoAuthorization] is refused, since it
        // would exempt every endpoint added to the class later.
        $classDeclares = Authorize::classLevelCount($class) > 0
            || $reflection->getAttributes(NoAuthorization::class) !== [];

        $compiled = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            try {
                $declaration = Endpoint::declaredOn($method);
            } catch (\InvalidArgumentException $e) {
                throw new EndpointException($e->getMessage(), 0, $e);
            }
            if ($declaration === null) {
                continue;
            }

            $where = "{$class}::{$method->getName()}()";
            if ($method->isStatic()) {
                throw new EndpointException("{$where} is static; an endpoint is an instance method.");
            }
            if ($classDeclares
                && $method->getAttributes(Authorize::class) === []
                && $method->getAttributes(NoAuthorization::class) === []) {
                throw new EndpointException(
                    "{$class} carries a class-level #[Authorize] or #[NoAuthorization], and {$method->getName()}() "
                    . 'declares neither. A class-level declaration covers no endpoint: declare the check on the method.',
                );
            }

            $compiled[] = $this->add($this->compile($reflection, $method, $declaration, $where));
        }

        if ($compiled === []) {
            throw new EndpointException("{$class} is listed as holding endpoints and declares no #[Get], #[Post] or #[Endpoint] method.");
        }

        return $compiled;
    }

    /**
     * @param list<class-string> $classes
     * @return list<CompiledEndpoint>
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
     */
    private function compile(\ReflectionClass $reflection, \ReflectionMethod $method, Endpoint $declaration, string $where): CompiledEndpoint
    {
        $class = $reflection->getName();

        $name = $declaration->name ?? Str::shortName($class) . '.' . $method->getName();
        if (!Str::isDottedIdentifier($name)) {
            throw new EndpointException("{$where}: '{$name}' is not an endpoint name: dot-separated identifiers, as in orders.receipt.");
        }

        [$template, $segments, $parameters] = self::compilePath($where, $declaration->path);

        // Its host's parameters are inputs as its path's are (ADR 0149).
        try {
            $host = Domain::declaredOn($class, $method->getName());
        } catch (\InvalidArgumentException $e) {
            throw new EndpointException("{$where}: {$e->getMessage()}", 0, $e);
        }
        foreach ($host->parameters ?? [] as $hostParameter) {
            if (\in_array($hostParameter, $parameters, true)) {
                throw new EndpointException("{$where}: '{$hostParameter}' is in both its host and its path; a parameter comes from one place.");
            }
        }
        $parameters = [...($host->parameters ?? []), ...$parameters];

        $checks = Authorize::declaredOn($class, $method->getName());
        $inherited = Authorize::classLevelCount($class);
        $exempt = $method->getAttributes(NoAuthorization::class) !== [];

        $covered = [];
        foreach ($checks as $index => $check) {
            if ($check->argument === null) {
                throw new EndpointException(
                    "{$where} declares #[Authorize('{$check->ability}')] with no subject. Name the model-typed input "
                    . "it covers, or the class, as in Authorize('viewAny', Rack::class).",
                );
            }
            if ($index >= $inherited) {
                $covered[] = $check->argument;
            }
        }

        $models = [];
        $scalars = [];
        $formRequests = [];
        $nestedModels = [];
        $optional = [];
        $required = [];
        $taken = [];

        foreach ($method->getParameters() as $parameter) {
            $parameterName = $parameter->getName();
            $type = $parameter->getType();
            if ($parameter->isVariadic() || !$type instanceof \ReflectionNamedType) {
                throw new EndpointException("{$where}: \${$parameterName} cannot be bound; give it one type.");
            }
            $typeName = $type->getName();
            $fromPath = \in_array($parameterName, $parameters, true);

            if ($fromPath) {
                $taken[] = $parameterName;
                if (!$type->isBuiltin() && is_subclass_of($typeName, Model::class)) {
                    // Checked here, not left to a build step that might not
                    // run: a model with no check naming it must not reach a body.
                    if (!$exempt && !\in_array($parameterName, $covered, true)) {
                        throw new EndpointException(
                            "{$where}: \${$parameterName} takes {$typeName} but no #[Authorize] names it. Add "
                            . "#[Authorize('<ability>', '{$parameterName}')], or #[NoAuthorization(reason: '...')] "
                            . 'if it genuinely needs none.',
                        );
                    }
                    $models[$parameterName] = $typeName;
                    continue;
                }
                if ($type->isBuiltin() ? \in_array($typeName, ['string', 'int', 'float', 'bool'], true) : is_subclass_of($typeName, \BackedEnum::class)) {
                    $scalars[$parameterName] = $typeName;
                    continue;
                }

                throw new EndpointException(
                    "{$where}: \${$parameterName} is in the path and typed {$typeName}. A path parameter is a model, "
                    . 'a string, int, float or bool, or a backed enum.',
                );
            }

            if (!$type->isBuiltin() && is_subclass_of($typeName, FormRequest::class)) {
                if (Validator::uploadFields($typeName) !== []) {
                    throw new EndpointException("{$where}: \${$parameterName} takes an upload field, which only an action is handed: an endpoint verifies no upload.");
                }
                $formRequests[$parameterName] = $typeName;
                continue;
            }
            if ($typeName === Request::class) {
                continue;
            }
            if (!$type->isBuiltin() && is_subclass_of($typeName, Model::class)) {
                throw new EndpointException(
                    "{$where}: \${$parameterName} takes {$typeName} and the path has no {{$parameterName}}. An endpoint "
                    . 'takes a model from its path, or from a form request field.',
                );
            }

            throw new EndpointException(
                "{$where}: \${$parameterName} ({$typeName}) is neither in the path, a form request, nor the Request. "
                . 'Read input through a form request, which validates it.',
            );
        }

        foreach ($parameters as $parameterName) {
            if (!\in_array($parameterName, $taken, true)) {
                throw new EndpointException(
                    "{$where}: its path has {{$parameterName}}, and the method takes no \${$parameterName}. Every "
                    . 'part of the path is an input, checked and bound, or not in the path at all.',
                );
            }
        }

        foreach ($formRequests as $requestClass) {
            foreach (self::modelFieldsOf($requestClass) as $field => [$fieldModel, $fieldOptional]) {
                $existing = $nestedModels[$field] ?? $models[$field] ?? null;
                if ($existing !== null) {
                    throw new EndpointException(
                        "{$where}: '{$field}' is taken twice -- as {$existing} and in {$requestClass} as {$fieldModel}. "
                        . 'One name is one input.',
                    );
                }
                if (!$exempt && !\in_array($field, $covered, true)) {
                    throw new EndpointException(
                        "{$where}: {$requestClass}::\${$field} takes {$fieldModel} but no #[Authorize] names it. Add "
                        . "#[Authorize('<ability>', '{$field}')], or #[NoAuthorization(reason: '...')].",
                    );
                }
                $nestedModels[$field] = $fieldModel;
                if ($fieldOptional) {
                    $optional[$field] = true;
                } else {
                    $required[$field] = true;
                }
            }
        }

        // Every check names an input this method takes, or a class.
        foreach ($checks as $index => $check) {
            $argument = (string) $check->argument;
            if (isset($models[$argument]) || isset($nestedModels[$argument])
                || class_exists($argument) || interface_exists($argument)) {
                continue;
            }

            throw new EndpointException(\sprintf(
                "%s %s #[Authorize('%s', '%s')], but '%s' is neither a model-typed input of this endpoint nor a class. "
                . 'It would throw on the first request.',
                $where,
                $index < $inherited ? 'inherits from its class' : 'declares',
                $check->ability,
                $argument,
                $argument,
            ));
        }

        [$returns, $data] = self::returnOf($where, $method);

        $public = PublicAccess::declaredOn($class, $method->getName());
        try {
            $scopes = RequiresScope::declaredOn($class, $method->getName());
        } catch (\InvalidArgumentException $e) {
            throw new EndpointException("{$where}: {$e->getMessage()}", 0, $e);
        }
        // An API token is its caller's credential, never a cookie (ADR
        // 0155): the endpoint runs with no session, as #[WithoutSession]
        // does, so no cookie signs anyone in and no CSRF token is asked for.
        $sessionless = $scopes !== [] || WithoutSession::declaredOn($class, $method->getName());
        if ($scopes !== [] && $public) {
            throw new EndpointException(
                "{$where} requires the scopes " . implode(', ', $scopes) . ' and is #[PublicAccess]. A scope is held by a token, '
                . 'which signs its caller in: take #[PublicAccess] away, or the #[RequiresScope].',
            );
        }
        $safe = array_diff($declaration->methods, ['GET']) === [];

        // "No unauthenticated state-changing route without an explicit
        // opt-out with a reason." A public GET changes nothing; a public
        // POST is someone's decision, and it says why.
        if ($public && !$safe && PublicAccess::reasonOn($class, $method->getName()) === null) {
            throw new EndpointException(
                "{$where} answers " . implode(', ', $declaration->methods) . ' with #[PublicAccess] and gives no reason. '
                . "Anyone may change something through it: say why, as in #[PublicAccess(reason: '...')].",
            );
        }

        try {
            $tenantFrom = TenantFrom::declaredOn($class, $method->getName());
            $panels = Panel::declaredOn($class, $method->getName());
            PanelBinding::of($panels);
            $throttle = Throttle::declaredOn($class, $method->getName());
            $cors = Cors::declaredOn($class, $method->getName());
        } catch (\LogicException|\InvalidArgumentException $e) {
            throw new EndpointException("{$where}: {$e->getMessage()}", 0, $e);
        }

        // A token API reads no cookie (ADR 0067): an origin allowed to send
        // the visitor's cookies to it would send them for nothing, and the
        // author who asked for that expected them to sign someone in.
        if ($cors !== null && $cors->credentials && $sessionless) {
            throw new EndpointException(
                "{$where} is #[WithoutSession] and allows other origins credentials. It reads no cookie: its callers "
                . "send their token in a header, which #[Cors(headers: ['authorization'])] allows.",
            );
        }

        // A tag that names the record it changes -- `rack:{rack}` -- names
        // it by a path parameter (ADR 0106), and by nothing else.
        try {
            TagInvalidation::assertPlaceholders($where, $declaration->revalidates, $parameters);
        } catch (\InvalidArgumentException $e) {
            throw new EndpointException($e->getMessage(), 0, $e);
        }

        return new CompiledEndpoint(
            name: $name,
            class: $class,
            method: $method->getName(),
            methods: $declaration->methods,
            template: $template,
            segments: $segments,
            parameters: $parameters,
            checks: $checks,
            classLevelChecks: $inherited,
            models: $models,
            scalars: $scalars,
            formRequests: $formRequests,
            nestedModels: $nestedModels,
            optionalModels: array_diff_key($optional, $required),
            middleware: Middleware::declaredOn($class, $method->getName()),
            exempt: $exempt,
            tenantFrom: $tenantFrom,
            panels: $panels,
            sessionless: $sessionless,
            returns: $returns,
            data: $data,
            publicAccess: $public,
            throttle: $throttle,
            cors: $cors,
            revalidates: $declaration->revalidates,
            host: $host,
            scopes: $scopes,
        );
    }

    /**
     * What an endpoint answers with, by its declared return type: a
     * Response, one Data class, or void. Anything else -- an array, a
     * model, mixed, no type at all -- would reach the client unchecked or
     * not at all, so it is refused here.
     *
     * @return array{string, class-string<Data>|null}
     */
    private static function returnOf(string $where, \ReflectionMethod $method): array
    {
        $type = $method->getReturnType();
        if ($type instanceof \ReflectionNamedType && !$type->allowsNull()) {
            $name = $type->getName();
            if ($name === 'void') {
                return [CompiledEndpoint::RETURNS_NOTHING, null];
            }
            if ($name === Response::class) {
                return [CompiledEndpoint::RETURNS_RESPONSE, null];
            }
            if (!$type->isBuiltin() && is_subclass_of($name, Data::class)) {
                return [CompiledEndpoint::RETURNS_DATA, $name];
            }
        }

        throw new EndpointException(
            "{$where} returns " . ($type === null ? 'no declared type' : (string) $type) . '. An endpoint returns a '
            . 'Response, one Data class -- sent as JSON through the encoder -- or void.',
        );
    }

    /**
     * A template's normal form, its segments and its parameters, or a
     * refusal: a literal that is not a plain path segment, a parameter that
     * is not an identifier or appears twice, or a first segment starting
     * with `_` -- the framework's own (`/_action`, `/_query`, `/_fragment`,
     * `/_health` and the rest), which an application never answers under.
     *
     * @return array{string, list<string>, list<string>}
     */
    private static function compilePath(string $where, string $path): array
    {
        $template = PathTemplate::normalize($path);
        $segments = CompiledRoute::split($template);

        if (($segments[0] ?? '') !== '' && str_starts_with($segments[0], '_')) {
            throw new EndpointException("{$where}: '{$path}' is under /_, which is the framework's own. Choose another path.");
        }

        // By the compiler a page's template is held to as well.
        try {
            [$segments, $parameters] = PathTemplate::compile($template);
        } catch (PathTemplateRefused $e) {
            throw new EndpointException(match ($e->rule) {
                PathTemplateRefused::LITERAL => "{$where}: '{$path}' has '{$e->part}', which is not a literal path segment.",
                PathTemplateRefused::NAME => "{$where}: '{$path}' has '{$e->part}', which is not a parameter name.",
                default => "{$where}: '{$path}' names {{$e->part}} twice.",
            });
        }

        return [$template, $segments, $parameters];
    }

    /**
     * Model-typed constructor fields of a form request, each with whether
     * it may be left out.
     *
     * @param class-string<FormRequest> $class
     * @return array<string, array{class-string<Model>, bool}>
     */
    private static function modelFieldsOf(string $class): array
    {
        $fields = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_subclass_of($type->getName(), Model::class)) {
                $fields[$parameter->getName()] = [$type->getName(), $type->allowsNull() || $parameter->isDefaultValueAvailable()];
            }
        }

        return $fields;
    }

    /**
     * Refuses a second endpoint under a name already taken, and two that
     * answer one method at the same shape of path: whichever answered would
     * depend on the order the classes were listed in, and its checks and
     * middleware with it.
     */
    private function add(CompiledEndpoint $endpoint): CompiledEndpoint
    {
        $existing = $this->byName[$endpoint->name] ?? null;
        if ($existing !== null) {
            throw new EndpointException("Both {$existing->where()} and {$endpoint->where()} are the endpoint '{$endpoint->name}'.");
        }

        foreach ($this->ordered as $other) {
            // One bucket is one counter: two sizes for it would have the
            // endpoint asked first decide what the other may spend.
            if ($endpoint->throttle !== null && $other->throttle !== null
                && $endpoint->throttle->bucket === $other->throttle->bucket
                && $endpoint->throttle->perMinute !== $other->throttle->perMinute) {
                throw new EndpointException(\sprintf(
                    "Both %s and %s spend the throttle bucket '%s', at %d and %d a minute. One bucket has one size.",
                    $other->where(),
                    $endpoint->where(),
                    $endpoint->throttle->bucket,
                    $other->throttle->perMinute,
                    $endpoint->throttle->perMinute,
                ));
            }

            $shared = array_intersect($other->methods, $endpoint->methods);
            if ($other->shape() === $endpoint->shape() && $shared !== []) {
                throw new EndpointException(\sprintf(
                    'Both %s and %s answer %s at %s.',
                    $other->where(),
                    $endpoint->where(),
                    implode(', ', $shared),
                    $endpoint->template,
                ));
            }
        }

        $this->byName[$endpoint->name] = $endpoint;
        $this->ordered[] = $endpoint;
        $this->sorted = false;

        return $endpoint;
    }

    /**
     * The endpoint a request is for, with its path's parameters; or, when
     * endpoints match the path and none answers the method, the methods
     * that would do; null when none matches the path.
     *
     * Candidates are asked most specific first, as pages are, and the first
     * that answers the method is the one. A path under `/_` matches nothing
     * here, whatever an endpoint's parameters would take: it is the
     * framework's.
     *
     * @return array{endpoint: CompiledEndpoint, parameters: array<string, string>}|array{allow: list<string>}|null
     */
    public function match(Request $request): ?array
    {
        return $this->matchMethod($request->path, $request->method, $this->hostOf($request));
    }

    /**
     * match(), for a method other than the request's own, on the request's
     * host: what a CORS preflight asks about.
     *
     * @return array{endpoint: CompiledEndpoint, parameters: array<string, string>}|array{allow: list<string>}|null
     */
    public function matchAs(Request $request, string $method): ?array
    {
        return $this->matchMethod($request->path, $method, $this->hostOf($request));
    }

    /** Tells the registry which hosts the application serves (ADR 0149); the Kernel does, once. */
    public function serveOn(Domains $domains): void
    {
        $this->domains = $domains;
    }

    /**
     * Every #[Domain] pattern an endpoint declares, once each.
     *
     * @return list<HostPattern>
     */
    public function hostPatterns(): array
    {
        $patterns = [];
        foreach ($this->ordered as $endpoint) {
            if ($endpoint->host !== null) {
                $patterns[$endpoint->host->pattern] = $endpoint->host;
            }
        }

        return array_values($patterns);
    }

    /**
     * The host a request is for: the Kernel's resolution, or -- asked before
     * it, as whether a request opens a session is -- resolved here; null
     * where the application routes on no host, and for a host it does not
     * serve, which nothing here then matches.
     */
    private function hostOf(Request $request): ?HostMatch
    {
        if ($this->domains === null) {
            return null;
        }

        try {
            return $this->domains->resolve($request);
        } catch (\Tinvar\Http\HttpException) {
            return new HostMatch("\0unserved", '');
        }
    }

    /**
     * match(), for a method other than the request's own: the one a CORS
     * preflight asks whether it may send (ADR 0074).
     *
     * @return array{endpoint: CompiledEndpoint, parameters: array<string, string>}|array{allow: list<string>}|null
     */
    public function matchMethod(string $path, string $method, ?HostMatch $host = null): ?array
    {
        $first = CompiledRoute::split($path)[0] ?? '';
        if (str_starts_with($first, '_')) {
            return null;
        }

        $this->sort();

        $allow = [];
        foreach ($this->ordered as $endpoint) {
            // On its own host only; the primary host for one declaring none.
            if ($endpoint->host?->pattern !== $host?->pattern) {
                continue;
            }
            $parameters = $endpoint->match($path);
            if ($parameters === null) {
                continue;
            }
            $parameters = [...($host === null ? [] : $host->parameters), ...$parameters];
            if ($endpoint->answers($method)) {
                return ['endpoint' => $endpoint, 'parameters' => $parameters];
            }
            $allow = [...$allow, ...$endpoint->allowed()];
        }

        return $allow === [] ? null : ['allow' => array_values(array_unique($allow))];
    }

    /**
     * Refuses an endpoint whose path a page's also matches. A page answers
     * GET and HEAD and refuses the rest with a 405; an endpoint at a path
     * it shares would take some of those requests, depending on the method
     * or on which literal came first -- neither of which a reader of either
     * class would guess. The Kernel asks when it is built.
     */
    public function assertNoConflicts(RouteRegistry $routes): void
    {
        $conflicts = $this->conflicts($routes);
        if ($conflicts !== []) {
            throw new EndpointException($conflicts[0]->where . ': ' . $conflicts[0]->message);
        }
    }

    /** @return list<Violation> */
    public function conflicts(RouteRegistry $routes): array
    {
        $violations = [];
        foreach ($this->all() as $endpoint) {
            foreach ($routes->pages() as $page) {
                if ($endpoint->host?->shape() === $page->host?->shape() && $endpoint->overlaps($page->segments)) {
                    $violations[] = new Violation(
                        self::PATHS_MUST_NOT_OVERLAP_PAGES,
                        $endpoint->where(),
                        "answers at {$endpoint->template}, and the page {$page->class} at {$page->template}; some path "
                        . 'matches both. Give the endpoint a path no page matches.',
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * What `tinvar check` reports for an application's endpoints: each
     * class the registry refuses, and each endpoint whose path a page's
     * also matches.
     *
     * @param list<class-string> $classes the classes holding endpoints
     * @param list<class-string<\Tinvar\Orm\Model>> $models the application's models, as the
     *        Kernel's registry is given them, so a bare id naming a tenant's model is refused
     *        here too (ADR 0105)
     * @return list<Violation>
     */
    public static function check(array $classes, RouteRegistry $routes, array $models = []): array
    {
        $registry = new self($models);
        $violations = [];
        foreach ($classes as $class) {
            try {
                $registry->register($class);
            } catch (\RuntimeException|\LogicException $e) {
                $violations[] = new Violation(self::ENDPOINTS_MUST_COMPILE, $class, $e->getMessage());
            }
        }

        return [...$violations, ...$registry->conflicts($routes)];
    }

    /**
     * Whether a class declares an endpoint on any public method -- or tries
     * to, with a declaration register() would refuse -- for picking the
     * endpoints out of a list of entry points that holds channels and
     * transfers too.
     *
     * @param class-string $class
     */
    public static function holdsEndpoints(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            try {
                if (Endpoint::declaredOn($method) !== null) {
                    return true;
                }
            } catch (\InvalidArgumentException) {
                return true;
            }
        }

        return false;
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function get(string $name): CompiledEndpoint
    {
        return $this->byName[$name] ?? throw new EndpointException("No endpoint is named '{$name}'.");
    }

    /** @return list<CompiledEndpoint> most specific first */
    public function all(): array
    {
        $this->sort();

        return $this->ordered;
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }
        usort($this->ordered, static fn (CompiledEndpoint $a, CompiledEndpoint $b): int => $a->beats($b));
        $this->sorted = true;
    }
}
