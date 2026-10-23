<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Actions\Revalidator;
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
use Tinvar\Http\Middleware\CheckVersion;
use Tinvar\Http\Middleware\TranslateRedirects;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\RouteMinter;
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

    /** Kept so a page render can mint the route token its client sends back. */
    private readonly ?FragmentSupport $support;

    /** Null when the application declared no builds, so there is nothing to check against. */
    private readonly ?CheckVersion $versions;
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
     * The request attribute carrying the page's route token, for the
     * responder to put into the page metadata. Absent when no token is
     * minted.
     */
    public const string ROUTE_TOKEN = 'tinvar.route_token';

    /**
     * @param \Closure(class-string): object $resolver builds pages, layouts, queries, actions and middleware
     * @param \Closure(Request): list<string> $allowedQueries the route's query allowlist
     * @param list<class-string<Middleware>> $globalMiddleware
     * @param (\Closure(\Throwable, string): void)|null $log
     * @param array<string, int> $limits requests per minute, by throttle bucket
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
        ?BuildRegistry $builds = null,
    ) {
        $this->panels = $panels;
        $gate ??= new PolicyGate($this->policies, $this->auth);
        $cache ??= new QueryCache(new \Tinvar\Cache\ArrayStore(), $this->auth, $panels);

        $this->router = new Router(
            $this->routes,
            new LoaderRunner($this->orm, $resolver, $gate),
            self::middlewareFrom($resolver),
            $globalMiddleware,
        );

        // The revalidator needs the same signer and session seam the
        // fragment endpoint uses, so it is mounted only when an application
        // has supplied them. Without it, revalidate() still names routes
        // and the response simply carries no payloads -- which is what a
        // client reads as "reload that route yourself".
        // Both halves must exist: a signer for route tokens, and something
        // that can render one. Defaulting the renderer to a closure
        // returning '' meant every invalidated route a client held paid for
        // a full loader run and came back as an empty payload, which a
        // client renders as empty content rather than reading as absent.
        $routeSigner = $fragments?->routeSigner;

        $revalidator = ($fragments === null || $routeSigner === null || $fragments->renderRoute === null)
            ? null
            : new Revalidator(
            routes: $this->routes,
            loaders: new LoaderRunner($this->orm, $resolver, $gate),
            signer: $routeSigner,
            orm: $this->orm,
            render: $fragments->renderRoute,
            builds: $fragments->builds,
            middlewareResolver: self::middlewareFrom($resolver),
            panels: $panels,
            globalMiddleware: $globalMiddleware,
            log: $log === null ? null : static function (\Throwable $e) use ($log): void {
                $log($e, ExceptionHandler::digest($e));
            },
        );

        $this->actions_ = new ActionEndpoint(new ActionRunner(
            $this->actions,
            $this->orm,
            $gate,
            new Validator($this->orm),
            new \Tinvar\Data\Encoder(),
            $cache,
        ), revalidator: $revalidator, sessionId: $fragments?->sessionId);

        $queryRunner = new QueryRunner(
            registry: $this->queries,
            orm: $this->orm,
            gate: $gate,
            cache: $cache,
            debug: $debug,
        );

        $this->queries_ = new QueryEndpoint($queryRunner, $allowedQueries);

        $global = array_map(self::middlewareFrom($resolver), $globalMiddleware);

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

        if ($fragments !== null && ($fragments->panels === null || $fragments->orm === null)) {
            // EVERY field. This rebuild listed five of nine, so an
            // application that supplied a query allowlist, a route signer
            // or a route renderer lost them here -- silently, and only on
            // the path that was supposed to be repairing the object. It
            // also never set `orm`, so the condition it exists to fix
            // stayed true.
            $fragments = new FragmentSupport(
                signer: $fragments->signer,
                builds: $fragments->builds,
                render: $fragments->render,
                sessionId: $fragments->sessionId,
                panels: $fragments->panels ?? $panels,
                allowedQueries: $fragments->allowedQueries,
                orm: $fragments->orm ?? $this->orm,
                renderRoute: $fragments->renderRoute,
                routeSigner: $fragments->routeSigner,
            );
        }

        $this->fragments_ = $fragments === null ? null : new FragmentEndpoint(
            routes: $this->routes,
            loaders: new LoaderRunner($this->orm, $resolver, $gate),
            orm: $this->orm,
            signer: $fragments->signer,
            builds: $fragments->builds,
            render: $fragments->render,
            sessionId: $fragments->sessionId,
            allowedQueries: $fragments->allowedQueries,
            middlewareResolver: self::middlewareFrom($resolver),
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

        $this->support = $fragments;

        // Its own argument, not reached through FragmentSupport: detecting a
        // deploy is something every application with a client router needs,
        // whether or not it has a single <Deferred>.
        $builds ??= $fragments?->builds;
        $this->versions = $builds === null ? null : new CheckVersion($builds);
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
        $navigating = false;

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

            // The page response carries the token its client will send back
            // when an action invalidates this route. Minted here rather than
            // by the application, so route and params come from the chain
            // that was actually matched.
            $navigate = fn (Request $r): Response => $this->router->handle($r, function (LoadedRoute $loaded, Request $passed) use ($respond): Response {
                // Minted BEFORE the page renders and handed to it, because a
                // document load cannot read response headers: the token has
                // to be in the page's own metadata too, or the first page a
                // visitor lands on can never be revalidated by an action.
                $token = $this->routeTokenFor($loaded, $passed);
                $response = $respond(
                    $loaded,
                    $token === null ? $passed : $passed->withAttribute(self::ROUTE_TOKEN, $token),
                );

                if ($token === null) {
                    return $response;
                }

                // The token carries a session hash, a tenant and a panel, so
                // the response holding it is per-visitor. A shared cache
                // keyed on URL could otherwise hand one visitor's to
                // another -- inert, since the binding holds, but it is
                // still disclosure.
                return $response
                    ->withHeader('x-route-token', $token)
                    ->withHeader('cache-control', 'private, no-store')
                    ->withAddedHeader('vary', 'Cookie');
            });

            // Version first, before anything renders: a client on another
            // build gets told to reload rather than handed a payload whose
            // client-component IDs it cannot resolve.
            $checked = fn (Request $r): Response => $this->versions === null
                ? $navigate($r)
                : $this->versions->handle($r, $navigate);

            // Outermost, so a redirect from anywhere on the navigation path
            // -- route middleware, the page itself -- reaches the client
            // router in a form it can read.
            $navigating = true;

            return new TranslateRedirects()->handle($request, $checked);
        } catch (\Throwable $e) {
            $response = $this->exceptions->handle($e, $request);

            // An HttpException can carry a redirect too, and it is answered
            // here rather than inside the middleware above.
            return $navigating ? TranslateRedirects::translate($request, $response) : $response;
        } finally {
            $this->scope->reset();
        }
    }

    /**
     * The application's resolver, narrowed to middleware. A resolver that
     * hands back something else is a misconfiguration to name, not a
     * TypeError from deep inside a request.
     *
     * @param \Closure(class-string): object $resolver
     * @return \Closure(class-string): Middleware
     */
    private static function middlewareFrom(\Closure $resolver): \Closure
    {
        return static function (string $class) use ($resolver): Middleware {
            if (!class_exists($class)) {
                throw new \LogicException("No middleware class {$class}.");
            }
            $middleware = $resolver($class);
            if (!$middleware instanceof Middleware) {
                throw new \LogicException("The resolver built {$class} as " . get_debug_type($middleware) . ', not middleware.');
            }

            return $middleware;
        };
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

    /**
     * The route token for a page that has just rendered, or null.
     *
     * Null for an application that configured no signing, and for a route
     * with no name -- a token naming nothing could never be re-rendered
     * from, so issuing one would be issuing something the revalidator
     * always discards. A session-less visitor gets none either: a token
     * bound to a constant hash would be every visitor's.
     */
    private function routeTokenFor(LoadedRoute $loaded, Request $request): ?string
    {
        if ($this->fragments_ === null || $this->support === null) {
            return null;
        }

        $session = ($this->support->sessionId)($request);
        if ($session === '') {
            return null;
        }

        $routeSigner = $this->support->routeSigner;
        if ($routeSigner === null) {
            return null;
        }

        return (new RouteMinter(
            signer: $routeSigner,
            panel: $this->panels?->currentOrNull() ?? '',
            tenant: $this->orm->tenant(),
            build: $this->support->builds->current,
            sessionId: $session,
        ))->mint($loaded->chain);
    }
}
