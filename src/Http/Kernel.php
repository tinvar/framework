<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Cache\QueryCache;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Endpoints\QueryEndpoint;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentManifest;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Validation\Validator;

/**
 * The composition root: one place where the request path is actually joined up.
 *
 * Phase 1 built the router, the loader runner, the gate, the validator, the
 * action and query runners, the cache and the two endpoints -- and nothing in
 * the repository constructed them together. Every seam was exercised only by a
 * test that built both sides itself with a fake in the middle, which is how a
 * page with no #[Authorize] came to register happily, how revalidateTags()
 * came to invalidate nothing, and how three runners came to disagree about an
 * absent optional model. A phase with no integration point cannot notice any
 * of that.
 *
 * So this is deliberately boring and deliberately complete: registries, the
 * gate, the runners, the endpoints, an exception handler and a reset contract,
 * in the order the spec gives them. It is not a container -- the brief asks
 * for wiring to stay a stub behind an interface -- so dependencies come from
 * one resolver closure.
 */
final class Kernel
{
    public readonly Router $router;
    public readonly ActionEndpoint $actions_;
    public readonly QueryEndpoint $queries_;

    /**
     * Null unless the application supplied what a fragment needs: a signing
     * key, a build registry and a way to render a subtree. A deployment with
     * no deferred components mounts nothing, rather than mounting an endpoint
     * that would answer with a half-configured one.
     */
    public readonly ?FragmentEndpoint $fragments_;

    /** Mounted only when an application has statically-built pages to bootstrap. */
    public readonly ?BootstrapEndpoint $bootstrap_;
    public readonly RequestScope $scope;
    public readonly ?PanelContext $panels;

    /**
     * The endpoints table's `throttle:<name>` defaults, per minute.
     *
     * "Proposed defaults, configurable per app" -- so they are a constant an
     * application can replace wholesale rather than numbers buried in the
     * middleware. Fragments get the largest budget because one page can
     * legitimately mount many boundaries; actions the smallest, because an
     * action writes.
     *
     * @var array<string, int>
     */
    public const array DEFAULT_LIMITS = [
        'actions' => 60,
        'queries' => 240,
        'fragments' => 300,
        // Its own bucket. A static page's bootstrap is signed-out traffic
        // keyed by address, so charging it to `actions` would have a shared
        // NAT's visitors spending that NAT's action budget just by loading
        // pages.
        'bootstrap' => 120,
    ];

    private readonly ExceptionHandler $exceptions;

    /**
     * The stack the two endpoints run behind.
     *
     * The spec gives /_action and /_query a default stack -- "web, auth,
     * throttle:actions, plus the action's panel stack" -- and the endpoints'
     * own docblocks say it is applied "by whoever mounts this". This is
     * whoever mounts them. Dispatching to them outside the pipeline, as this
     * class first did, meant CSRF, authentication and every global middleware
     * covered the page path and nothing else: an unauthenticated,
     * token-less POST reached an action body.
     *
     * Throttling is not here, because there is no throttler yet; the README
     * says so rather than this implying otherwise.
     */
    private readonly Pipeline $actionStack;
    private readonly Pipeline $queryStack;
    private readonly Pipeline $bootstrapStack;

    /**
     * @param \Closure(class-string): object $resolver builds pages, layouts, queries, actions and middleware
     * @param \Closure(Request): list<string> $allowedQueries the route's query allowlist
     * @param list<class-string> $globalMiddleware
     * @param (\Closure(\Throwable, string): void)|null $log
     */
    public function __construct(
        public readonly Orm $orm,
        public readonly RouteRegistry $routes,
        public readonly QueryRegistry $queries,
        public readonly ActionRegistry $actions,
        public readonly AuthContext $auth,
        public readonly PolicyRegistry $policies,
        \Closure $resolver,
        \Closure $allowedQueries,
        ?QueryCache $cache = null,
        array $globalMiddleware = [],
        ?Gate $gate = null,
        ?\Closure $log = null,
        bool $debug = false,
        ?FragmentSupport $fragments = null,
        ?Throttle $throttle = null,
        array $limits = self::DEFAULT_LIMITS,
        ?PanelContext $panels = null,
        ?FragmentManifest $manifest = null,
    ) {
        $this->panels = $panels;
        $gate ??= new PolicyGate($this->policies, $this->auth);
        $cache ??= new QueryCache(new \Tinvar\Cache\ArrayStore(), $this->auth, $panels);

        $this->router = new Router(
            $this->routes,
            new LoaderRunner($this->orm, $resolver, $gate),
            static fn (string $class): Middleware => $resolver($class),
            $globalMiddleware,
        );

        $this->actions_ = new ActionEndpoint(new ActionRunner(
            $this->actions,
            $this->orm,
            $gate,
            new Validator($this->orm),
            new \Tinvar\Data\Encoder(),
            $cache,
        ));

        $queryRunner = new QueryRunner(
            registry: $this->queries,
            orm: $this->orm,
            gate: $gate,
            cache: $cache,
            debug: $debug,
        );

        $this->queries_ = new QueryEndpoint($queryRunner, $allowedQueries);

        $global = array_map(static fn (string $class): Middleware => $resolver($class), $globalMiddleware);

        // The address pass is outermost -- ahead of the application's own
        // global middleware, so a refused request does not pay for session
        // start or tenant resolution. The account pass runs AFTER that
        // stack, because the auth context is populated by middleware and a
        // throttle in front of it always sees an anonymous caller: putting
        // both outermost looked right and silently disabled the per-user
        // limit entirely.
        $this->actionStack = new Pipeline([
            ...$this->throttleFor($throttle, 'actions', $limits, byAddress: true),
            ...$global,
            ...$this->throttleFor($throttle, 'actions', $limits, byAddress: false),
            new VerifyCsrfToken(),
            new RequireAuthentication($this->auth, $this->entryPointFor(...)),
        ]);

        $this->queryStack = new Pipeline([
            ...$this->throttleFor($throttle, 'queries', $limits, byAddress: true),
            ...$global,
            ...$this->throttleFor($throttle, 'queries', $limits, byAddress: false),
            new VerifyCsrfToken(),
            new RequireAuthentication($this->auth, $this->entryPointFor(...)),
        ]);

        // Bootstrap gets the throttle and CSRF an action gets, and NOT the
        // authentication check. It exists to hand a visitor the tokens for a
        // statically-built page, and such a visitor may well not be signed
        // in -- running it behind RequireAuthentication 401'd exactly the
        // traffic it was built for. The carve-out is deliberate and narrow:
        // minting is not authorization, and every token it hands back still
        // has to survive the originating route's #[Authorize] chain on use.
        $this->bootstrapStack = new Pipeline([
            ...$this->throttleFor($throttle, 'bootstrap', $limits, byAddress: true),
            ...$global,
            ...$this->throttleFor($throttle, 'bootstrap', $limits, byAddress: false),
            new VerifyCsrfToken(),
        ]);

        // Fragments run their own stack: GET with no body, so CSRF does not
        // apply, and the endpoint composes the originating ROUTE's middleware
        // inside it once the token says which route that is. The global stack
        // it is given is this kernel's, so a refusal is still throttled and
        // logged like any other request.
        // The support object mints; it needs the same panel context the
        // endpoint reads, or the two halves disagree.
        // A support object carrying a DIFFERENT context is a configuration
        // error, not something to silently prefer one side of: the mint side
        // would read one context and the verify side another, so a fragment
        // minted under one panel would be checked against a context that
        // never saw the switch.
        if ($fragments !== null
            && $fragments->panels !== null
            && $panels !== null
            && $fragments->panels !== $panels
        ) {
            throw new \InvalidArgumentException(
                'FragmentSupport carries a different PanelContext than this Kernel. '
                . 'Minting and verifying must read the same one.'
            );
        }

        if ($fragments !== null && $fragments->panels === null && $panels !== null) {
            $fragments = new FragmentSupport(
                signer: $fragments->signer,
                builds: $fragments->builds,
                render: $fragments->render,
                sessionId: $fragments->sessionId,
                panels: $panels,
            );
        }

        $this->fragments_ = $fragments === null ? null : new FragmentEndpoint(
            routes: $this->routes,
            loaders: new LoaderRunner($this->orm, $resolver, $gate),
            signer: $fragments->signer,
            builds: $fragments->builds,
            render: $fragments->render,
            sessionId: $fragments->sessionId,
            allowedQueries: $fragments->allowedQueries,
            middlewareResolver: static fn (string $class): Middleware => $resolver($class),
            globalMiddleware: $globalMiddleware,
            extraGlobal: $this->throttleFor($throttle, 'fragments', $limits, byAddress: true),
            panels: $panels,
            extraInner: $this->throttleFor($throttle, 'fragments', $limits, byAddress: false),
        );

        // Both, or neither: a manifest says which boundaries exist, and
        // FragmentSupport is what can sign for them. Either alone cannot
        // answer a bootstrap request.
        $this->bootstrap_ = $fragments === null || $manifest === null || !$manifest->hasAny()
            ? null
            : new BootstrapEndpoint($this->routes, $manifest, $fragments);

        $this->exceptions = new ExceptionHandler($log);
        $this->scope = RequestScope::standard($this->orm, $this->auth, $this->policies, $panels, $queryRunner);
    }

    /**
     * One request, start to finish.
     *
     * Everything that can be thrown is classified here rather than escaping,
     * and the scope is reset in a finally so the next request on this worker
     * does not inherit this one's user, identity map or policy instances.
     *
     * @param \Closure(LoadedRoute, Request): Response $respond renders a matched route
     */
    public function handle(Request $request, \Closure $respond, string $rawBody = ''): Response
    {
        try {
            if ($this->actions_->matches($request)) {
                return $this->actionStack->run(
                    $request,
                    fn (Request $passed): Response => $this->actions_->handle($passed, $rawBody),
                );
            }

            // Before the other two, because its prefix is as specific and the
            // order should not depend on which was written first.
            if ($this->bootstrap_ !== null && $this->bootstrap_->matches($request)) {
                return $this->bootstrapStack->run(
                    $request,
                    fn (Request $passed): Response => $this->bootstrap_->handle($passed, $rawBody),
                );
            }

            if ($this->fragments_ !== null && $this->fragments_->matches($request)) {
                return $this->fragments_->handle($request);
            }

            if ($this->queries_->matches($request)) {
                return $this->queryStack->run(
                    $request,
                    fn (Request $passed): Response => $this->queries_->handle($passed, $rawBody),
                );
            }

            return $this->router->handle($request, $respond);
        } catch (\Throwable $e) {
            return $this->exceptions->handle($e, $request);
        } finally {
            $this->scope->reset();
        }
    }

    /**
     * Which entry point a request targets, so #[PublicAccess] can be read off
     * it. An unknown name resolves to null, which is not public: the endpoint
     * refuses it anyway, and telling an anonymous caller "no such action"
     * would be an enumeration oracle.
     *
     * @return array{0: class-string, 1: string|null}|null
     */
    private function entryPointFor(Request $request): ?array
    {
        if (str_starts_with($request->path, ActionEndpoint::PREFIX)) {
            $name = substr($request->path, \strlen(ActionEndpoint::PREFIX));
            if (!$this->actions->has($name)) {
                return null;
            }
            $action = $this->actions->get($name);

            return [$action->class, $action->method];
        }

        if (str_starts_with($request->path, QueryEndpoint::PREFIX)) {
            $name = substr($request->path, \strlen(QueryEndpoint::PREFIX));
            $class = $this->queries->classFor($name);

            return $class === null ? null : [$class, '__invoke'];
        }

        return null;
    }

    /**
     * `throttle:<name>`, or nothing when the application configured none.
     *
     * Absent rather than unlimited-by-default: a throttle with no shared
     * store is a limit multiplied by the worker count, which looks enforced
     * and is not, so an application has to say what store it means.
     *
     * @param array<string, int> $limits
     * @return list<Middleware>
     */
    private function throttleFor(
        ?Throttle $throttle,
        string $name,
        array $limits,
        bool $byAddress = true,
    ): array {
        if ($throttle === null || !isset($limits[$name])) {
            return [];
        }

        return [new ThrottleRequests($throttle, $name, $limits[$name], byAddress: $byAddress)];
    }
}
