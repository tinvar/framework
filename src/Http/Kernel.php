<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Actions\Revalidator;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Cache\QueryCache;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Endpoints\QueryEndpoint;
use Tinvar\Http\Middleware\CheckVersion;
use Tinvar\Http\Middleware\TranslateRedirects;
use Tinvar\Container\Container;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Live\TagsRead;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\RenderFailed;
use Tinvar\Render\RenderFailure;
use Tinvar\Render\RendererException;
use Tinvar\Render\RouteMinter;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenError;
use Tinvar\Data\PayloadContext;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteChain;
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

    /** Mounted only when an application supplies live updates. */
    public readonly ?SubscribeEndpoint $live_;

    /**
     * What a page's loaders read, by segment, for its live token -- given
     * to a loader that asks for it by type.
     */
    public readonly TagsRead $tagsRead;

    /** The hub and keys live tokens need; null when the application has none. */
    private readonly ?LiveUpdates $live;

    /** The builds a token may name, wherever the application declared them. */
    private readonly ?BuildRegistry $builds;

    /** Kept so a page render can mint the route token its client sends back. */
    private readonly ?FragmentSupport $support;

    /** Issues the CSRF cookie on page responses, and binds every check to the session. */
    private readonly ?CsrfTokens $csrf;

    /** Where a browser's own form post leaves its errors for the page it goes back to. */
    private readonly ?FlashStore $flashes;

    /** Where each session's previous URL is kept between its requests. */
    private readonly ?PreviousUrlStore $previousUrls;

    /** The keys a page's client seals its remembered state with (ADR 0019). */
    private readonly ?HistoryKeys $historyKeys;

    /** Which routes contain a <Deferred> boundary, so which renders may mint. */
    private readonly ?FragmentManifest $manifest;

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
        // A trade per page and per grant -- one every few minutes a tab is
        // open -- and one per reconnect, which backs off.
        'live' => 60,
    ];

    private readonly ExceptionHandler $exceptions;

    /** @var list<\Closure(RenderFailed): void> */
    private readonly array $renderFailed;

    /** Whether a failed render escapes handle() rather than becoming an error page. */
    private readonly bool $throwOnRenderError;

    /** Runs an #[ErrorPage]'s layouts and loader, as the router runs a page's. */
    private readonly LoaderRunner $errorLoaders;

    /** The page PHP writes alone when the error page cannot be rendered. */
    private readonly ErrorShell $errorShell;

    /** The application's global middleware, alone: what errorPage() runs an error page behind. */
    private readonly Pipeline $globalStack;

    /** What every entry point's declarations put around it; see EntryContext. */
    private readonly EntryContext $entry;

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
    private readonly Pipeline $liveStack;

    /**
     * The request attribute carrying the page's route token, for the
     * responder to put into the page metadata. Absent when no token is
     * minted.
     */
    public const string ROUTE_TOKEN = 'tinvar.route_token';

    /**
     * The request attribute carrying the page's FragmentMinter, for the
     * responder to hand the render channel. Absent unless the route has a
     * <Deferred> boundary to mint for -- see minterFor().
     */
    public const string FRAGMENT_MINTER = 'tinvar.fragment_minter';

    /**
     * The request attribute carrying the session's CSRF token, for the
     * responder to hand the render. "<Form> also renders a hidden _token
     * field during dynamic server rendering, so forms work before
     * JavaScript loads" -- and the render cannot read the cookie, which
     * this response may be the one to set. Absent without CsrfTokens or a
     * session.
     */
    public const string CSRF_TOKEN = 'tinvar.csrf_token';

    /**
     * The request attribute carrying the flash a browser's own form post
     * left for this page (see FlashStore), for the responder to hand the
     * render. Absent when there was none to read.
     */
    public const string FLASH = 'tinvar.flash';

    /**
     * The request attribute carrying the page's history key (HistoryKeys),
     * for the responder to put into the page metadata: a document load
     * cannot read the header a client navigation gets it in. Absent when the
     * page's history is not encrypted (ADR 0019).
     */
    public const string HISTORY_KEY = 'tinvar.history_key';

    /**
     * The request attribute carrying the page's LiveMinter, for the responder
     * to mint the page's live token with once the render has run -- what it
     * read is known only then -- and hand it over: in `X-Live-Token` on a
     * payload, at the end of a document (Tinvar\Live). Absent without live
     * updates, a route name or a session.
     */
    public const string LIVE_MINTER = 'tinvar.live_minter';

    /**
     * @param \Closure(class-string): object $resolver builds pages, layouts, queries, actions and middleware
     * @param \Closure(Request): list<string> $allowedQueries the route's query allowlist
     * @param list<class-string<Middleware>> $globalMiddleware
     * @param (\Closure(\Throwable, string): void)|null $log
     * @param array<string, int> $limits requests per minute, by throttle bucket, over
     *        DEFAULT_LIMITS; 0 turns a bucket off
     * @param Container|null $container registered with the framework's objects, and its
     *        request-lived services cleared by the reset contract
     * @param CsrfTokens|null $csrf the session-bound CSRF token. Given, every
     *        page response issues the `__Host-XSRF-TOKEN` cookie and every
     *        POST must carry its session's token; without it nothing issues
     *        the cookie, and the application has to.
     * @param bool $partialReloads honour X-Only and X-Except (ADR 0015). The
     *        application's renderer must then render a frame's `segments`
     *        alone, since a partial frame carries only their data.
     * @param FlashStore|null $flashes the application's session-scoped flash
     *        store. Given, a form posted without script that fails validation
     *        is sent back with its errors, and the page reads them; without
     *        it, that post gets the framework's 422 page.
     * @param PreviousUrlStore|null $previousUrls the application's
     *        session-scoped store for the previous URL. Given, every document
     *        load and client visit that renders a page is recorded in it, and
     *        Request::previousUrl() reads it back; without it, that is null.
     * @param HistoryKeys|null $historyKeys the session-scoped history keys.
     *        Given, a page rendered for a signed-in user -- or one that says
     *        #[EncryptHistory] -- carries its session's key, and the client
     *        router seals what it remembers for the page with it; without
     *        them, nothing is encrypted (ADR 0019).
     * @param PanelRegistry|null $panelRegistry the application's panels: each one's
     *        guard and stack, and the namespaces in it. Every #[Panel] must name
     *        one of them; with none, declaring a panel is refused.
     * @param Memberships|null $memberships the application's membership model.
     *        Given, every request into a panel needs a membership of it in the
     *        request's tenant, and `requires:` and `permission:` are held to it;
     *        without it, declaring either is refused.
     * @param LiveUpdates|null $live the hub and keys for live updates. Given,
     *        every page render is handed a LiveMinter, /_live/subscribe is
     *        mounted, and what an action invalidates is published once it
     *        commits; without them, nothing is live.
     * @param list<\Closure(RenderFailed): void> $renderFailed told of every render that
     *        fails -- the renderer threw, timed out, could not be reached or broke the
     *        protocol -- before the request is answered with an error page
     * @param bool $throwOnRenderError the spec's `throw_on_error`, for tests: a failed
     *        render escapes handle() as itself, after the listeners have heard of it,
     *        instead of being answered with an error page that a test reads as a
     *        response like any other
     * @param ErrorShell|null $errorShell the page PHP writes on its own when a
     *        failed document request's #[ErrorPage] cannot be rendered -- the
     *        renderer being what failed, usually; a plain one unless given
     * @param bool $dev built for dev (see DevMode): a failed document request that
     *        is a bug rather than an answer gets the DevOverlay over its error
     *        page -- class, message, file and line, stack, classification -- and
     *        one with no error page of its own gets the shell to carry it. Never
     *        in production: everything it shows is what production keeps from
     *        the browser.
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
        ?Container $container = null,
        ?CsrfTokens $csrf = null,
        bool $partialReloads = false,
        ?FlashStore $flashes = null,
        ?PreviousUrlStore $previousUrls = null,
        ?HistoryKeys $historyKeys = null,
        ?PanelRegistry $panelRegistry = null,
        ?Memberships $memberships = null,
        ?LiveUpdates $live = null,
        array $renderFailed = [],
        bool $throwOnRenderError = false,
        ?ErrorShell $errorShell = null,
        private readonly bool $dev = false,
    ) {
        $this->renderFailed = $renderFailed;
        $this->throwOnRenderError = $throwOnRenderError;
        $this->errorShell = $errorShell ?? new ErrorShell();

        // Panels need somewhere to be current. An application defining them
        // and passing no context gets one, rather than panels that resolve
        // and are put nowhere.
        $panels ??= $panelRegistry === null ? null : new PanelContext();

        $this->csrf = $csrf;
        $this->flashes = $flashes;
        $this->previousUrls = $previousUrls;
        $this->historyKeys = $historyKeys;
        $this->manifest = $manifest;
        $this->live = $live;
        $this->builds = $builds ?? $fragments?->builds;

        // Always, live updates or not: a loader that says what it read asks
        // for this by type, and must get one either way.
        $this->tagsRead = new TagsRead($this->orm);

        // Over the defaults, not instead of them: tightening one bucket by
        // passing ['actions' => 3] silently turned off every other one. A
        // bucket is off only when it says so, with 0.
        $limits = [...self::DEFAULT_LIMITS, ...$limits];

        // The per-account pass reads who is signed in from the context it
        // was built with. Built with any other than this Kernel's, it saw
        // every caller as anonymous and the per-user limit never applied --
        // which is how the example wired it.
        if ($throttle !== null && !$throttle->reads($auth)) {
            throw new \InvalidArgumentException(
                'The Throttle reads a different AuthContext than this Kernel, so its per-account limit '
                . 'would never apply. Build it with the same one.',
            );
        }

        // The framework's own objects, so anything the container builds can
        // take them by type. Pass $container->resolver() as $resolver.
        $container?->instance(Orm::class, $this->orm)
            ->instance(RouteRegistry::class, $this->routes)
            ->instance(QueryRegistry::class, $this->queries)
            ->instance(ActionRegistry::class, $this->actions)
            ->instance(AuthContext::class, $this->auth)
            ->instance(PolicyRegistry::class, $this->policies)
            ->instance(TagsRead::class, $this->tagsRead);
        if ($panels !== null) {
            $container?->instance(PanelContext::class, $panels);
        }

        $this->panels = $panels;
        $gate ??= new PolicyGate($this->policies, $this->auth);
        $cache ??= new QueryCache(new \Tinvar\Cache\ArrayStore(), $this->auth, $panels);

        // What every entry point's declarations put around it -- the tenant
        // its #[TenantFrom] names, the panel its #[Panel] or namespace puts
        // it in, and that panel's stack -- shared by every path that runs
        // one, so a page, its fragments, its re-render and an action all
        // resolve them the same way. Checked now: a panel nobody defined, or
        // a requirement nothing can hold, is refused here rather than found
        // by whoever first reaches it.
        $entry = new EntryContext($this->orm, $resolver, $panels, $panelRegistry, $memberships, $this->auth);
        $entry->check($this->routes, $this->actions, $this->queries);
        // And every route's query allowlist the Kernel is shown -- the one
        // fragment support carries, by route name -- against the panels of
        // the queries it names: a render runs them behind its page's panel.
        if ($fragments?->allowedQueries !== null) {
            $entry->checkAllowlists($this->routes, $this->queries, $fragments->allowedQueries);
        }
        $this->entry = $entry;

        // Every error page's layouts are known now; one it could not be
        // rendered in is refused here, not on the request that first fails.
        $this->routes->assertErrorPagesRenderable();
        $this->errorLoaders = new LoaderRunner($this->orm, $resolver, $gate);

        $this->router = new Router(
            $this->routes,
            new LoaderRunner($this->orm, $resolver, $gate, $this->tagsRead),
            self::middlewareFrom($resolver),
            $globalMiddleware,
            $partialReloads,
            $entry,
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
                entry: $entry,
            );

        // Built before the runners, which report through it: a rescued field
        // and a failed query are logged by the same handler as a failed
        // request, under the digest the client is shown.
        $this->exceptions = new ExceptionHandler($log);
        $encoder = new \Tinvar\Data\Encoder($this->report(...));

        $this->actions_ = new ActionEndpoint(
            new ActionRunner(
                $this->actions,
                $this->orm,
                $gate,
                new Validator($this->orm),
                $encoder,
                $cache,
                $live?->invalidator($this->orm, $this->report(...)),
            ),
            revalidator: $revalidator,
            sessionId: $fragments?->sessionId,
            middlewareResolver: self::middlewareFrom($resolver),
            globalMiddleware: $globalMiddleware,
            flashes: $flashes,
            entry: $entry,
        );

        $queryRunner = new QueryRunner(
            registry: $this->queries,
            orm: $this->orm,
            gate: $gate,
            encoder: $encoder,
            cache: $cache,
            debug: $debug,
            report: $this->report(...),
            panels: $this->queryPanelRefusal(...),
        );

        $this->queries_ = new QueryEndpoint(
            $queryRunner,
            $allowedQueries,
            middlewareResolver: self::middlewareFrom($resolver),
            globalMiddleware: $globalMiddleware,
            entry: $entry,
        );

        // Built once, here, and kept for the worker's life by the endpoint
        // stacks below: a request-lived service resolved into one of them
        // would carry the first request's state into every later one, so
        // the container refuses that while they are assembled.
        $buildGlobal = static fn (): array => array_map(self::middlewareFrom($resolver), $globalMiddleware);
        $global = $container === null ? $buildGlobal() : $container->longLived('the Kernel\'s endpoint stacks', $buildGlobal);
        $this->globalStack = new Pipeline($global);

        // The address pass is outermost -- ahead of the application's own
        // global middleware, so a refused request does not pay for session
        // start or tenant resolution. The account pass runs AFTER that
        // stack, because the auth context is populated by middleware and a
        // throttle in front of it always sees an anonymous caller: putting
        // both outermost looked right and silently disabled the per-user
        // limit entirely.
        //
        // Both budget passes come after CSRF. A page on another site can make
        // a visitor's browser send POSTs that fail it -- from the visitor's
        // own address -- and while the address pass ran first, sixty of
        // those locked the visitor out of their own actions. Outermost there
        // is only a flood guard, at ten times the budget, so a flood is
        // still refused before session start: no address limit can tell a
        // visitor's requests from a hostile page in the same browser, but
        // the one their real requests spend can no longer be spent for them.
        $this->actionStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'actions', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'actions', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'actions', $limits, byAddress: false),
            new RequireAuthentication($this->auth, $this->entryPointFor(...)),
        ]);

        $this->queryStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'queries', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'queries', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'queries', $limits, byAddress: false),
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
            ...$this->floodGuardFor($throttle, 'bootstrap', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'bootstrap', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'bootstrap', $limits, byAddress: false),
        ]);

        // The live trade gets what bootstrap gets, and for the same reason no
        // authentication: a page a visitor with no account may see reads
        // tags too. It sets a cookie, so CSRF; and whose session it is, it
        // checks itself.
        $this->liveStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'live', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'live', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'live', $limits, byAddress: false),
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
                . 'Minting and verifying must read the same one.',
            );
        }

        if ($fragments !== null && $fragments->auth !== null && $fragments->auth !== $this->auth) {
            throw new \InvalidArgumentException(
                'FragmentSupport carries a different AuthContext than this Kernel. '
                . 'Minting and verifying must read the same one.',
            );
        }

        if ($fragments !== null && ($fragments->panels === null || $fragments->orm === null || $fragments->auth === null)) {
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
                renderGroup: $fragments->renderGroup,
                auth: $fragments->auth ?? $this->auth,
            );
        }

        // A group request renders a subtree per token, so it is charged per
        // token: one request carrying sixteen is sixteen renders, and the
        // budget is a budget of renders (ADR 0018).
        $perToken = FragmentEndpoint::tokenCount(...);

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
            extraGlobal: $this->throttleFor($throttle, 'fragments', $limits, byAddress: true, cost: $perToken),
            panels: $panels,
            extraInner: $this->throttleFor($throttle, 'fragments', $limits, byAddress: false, cost: $perToken),
            renderGroup: $fragments->renderGroup,
            auth: $this->auth,
            entry: $entry,
        );

        // Both, or neither: a manifest says which boundaries exist, and
        // FragmentSupport is what can sign for them. Either alone cannot
        // answer a bootstrap request.
        $this->bootstrap_ = $fragments === null || $manifest === null || !$manifest->hasAny()
            ? null
            : new BootstrapEndpoint($this->routes, $manifest, $fragments, $entry);

        $this->support = $fragments;

        // Every trade runs the route each token names through its tenant,
        // panel stack, middleware and #[Authorize] chain, as /_fragment does:
        // a session taken out of the panel stops being granted its topics.
        $this->live_ = $live === null ? null : new SubscribeEndpoint(
            live: $live,
            auth: $this->auth,
            orm: $this->orm,
            routes: $this->routes,
            loaders: new LoaderRunner($this->orm, $resolver, $gate),
            entry: $entry,
            middlewareResolver: self::middlewareFrom($resolver),
            panels: $panels,
            builds: $this->builds,
            globalMiddleware: $globalMiddleware,
        );

        // Its own argument, not reached through FragmentSupport: detecting a
        // deploy is something every application with a client router needs,
        // whether or not it has a single <Deferred>.
        $this->versions = $this->builds === null ? null : new CheckVersion($this->builds);
        $this->scope = RequestScope::standard($this->orm, $this->auth, $this->policies, $panels, $queryRunner);

        // Request-lived services go with the request, like the user and the
        // identity map: one that remembered a user's data would hand it to
        // the next request on this worker.
        if ($container !== null) {
            $this->scope->add('container', $container->endRequest(...));
        }

        // What one page's loaders read is not the next request's.
        $this->scope->add('live', $this->tagsRead->forget(...));
    }

    /**
     * One request, start to finish.
     *
     * Everything that can be thrown is classified here rather than escaping,
     * and the scope is reset in a finally so the next request on this worker
     * does not inherit this one's user, identity map or policy instances.
     *
     * A document request that fails is answered with the application's
     * #[ErrorPage] for its status, rendered here -- before the reset, so the
     * session the global middleware opened, and with it the signed-in user,
     * is still in place for the error page's layouts even when no route
     * matched at all.
     *
     * @param \Closure(LoadedRoute, Request): Response $respond renders a matched route,
     *        and an error page
     * @param bool $errorPage false for a caller that wants the failure as the
     *        framework answers it -- a benchmark, a build step -- not a page
     */
    public function handle(Request $request, \Closure $respond, string $rawBody = '', bool $errorPage = true): Response
    {
        $request = $this->withPreviousUrl($request);

        try {
            $failure = null;
            $response = $this->dispatch($request, $respond, $rawBody, $failure);

            return $errorPage ? $this->withErrorPage($request, $response, $respond, $failure) : $response;
        } finally {
            $this->scope->reset();
        }
    }

    /**
     * Routes one request to an endpoint or a page, and classifies whatever it
     * throws into a response; $failure is set to what was thrown.
     *
     * @param \Closure(LoadedRoute, Request): Response $respond
     */
    private function dispatch(Request $request, \Closure $respond, string $rawBody, ?\Throwable &$failure): Response
    {
        $navigating = false;

        try {
            // Each endpoint's method is checked before its stack runs: a
            // GET to an action that needs a user was answered 401 by the
            // stack's authentication, where the answer is 405 and the method
            // that would do.
            if ($this->actions_->matches($request)) {
                ActionEndpoint::assertMethod($request);

                try {
                    $response = $this->actionStack->run(
                        $request,
                        fn (Request $passed): Response => $this->actions_->handle($passed, $rawBody),
                    );
                } catch (HttpException $e) {
                    // "A 419 becomes a same-origin redirect back with a
                    // 'page expired' flash, not an error screen." Script
                    // retries with the refreshed cookie and arranges that
                    // itself; a browser's own post is sent back here. With
                    // no input put back: the post failed CSRF, so whoever
                    // made it may not be the person it is shown to.
                    if ($e->status === 419 && ActionEndpoint::isFormNavigation($request)) {
                        return $this->actions_->back($request, ['form' => [
                            'action' => substr($request->path, \strlen(ActionEndpoint::PREFIX)),
                            'errors' => [],
                            'old' => [],
                            'expired' => true,
                        ]]);
                    }
                    throw $e;
                }

                // A client-router call (`RSC: 1`) fetches with redirect:
                // 'manual', which hides a 303's Location entirely. It reads
                // an action's redirect as a navigation reads one: 409 +
                // X-Redirect, followed inside the same visit.
                return TranslateRedirects::translate($request, $response);
            }

            // Before the other two, because its prefix is as specific and the
            // order should not depend on which was written first.
            if ($this->bootstrap_ !== null && $this->bootstrap_->matches($request)) {
                BootstrapEndpoint::assertMethod($request);

                return $this->bootstrapStack->run(
                    $request,
                    fn (Request $passed): Response => $this->bootstrap_->handle($passed, $rawBody),
                );
            }

            if ($this->live_ !== null && $this->live_->matches($request)) {
                SubscribeEndpoint::assertMethod($request);

                return $this->liveStack->run(
                    $request,
                    fn (Request $passed): Response => $this->live_->handle($passed, $rawBody),
                );
            }

            if ($this->fragments_ !== null && $this->fragments_->matches($request)) {
                FragmentEndpoint::assertMethod($request);

                return $this->fragments_->handle($request);
            }

            if ($this->queries_->matches($request)) {
                QueryEndpoint::assertMethod($request);

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
                $minter = $this->minterFor($loaded, $passed);
                if ($token !== null) {
                    $passed = $passed->withAttribute(self::ROUTE_TOKEN, $token);
                }
                if ($minter !== null) {
                    $passed = $passed->withAttribute(self::FRAGMENT_MINTER, $minter);
                }
                // Built now, with the binding as it stands for this render;
                // used once the render has run.
                $live = $this->liveMinter($passed, $loaded->chain->page->name, $loaded->chain->parameters, $this->tagsRead);
                if ($live !== null) {
                    $passed = $passed->withAttribute(self::LIVE_MINTER, $live);
                }
                // For the page metadata, as the route token is.
                $historyKey = $this->historyKeyFor($loaded, $passed);
                if ($historyKey !== null) {
                    $passed = $passed->withAttribute(self::HISTORY_KEY, $historyKey);
                }
                $loaded = $this->withHeldOnce($loaded, $passed, $token);
                [$passed, $flashed] = $this->withFormState($passed);
                $response = $this->withCsrfCookie($passed, $respond($loaded, $passed));
                $response = $this->withHistoryKey($passed, $response, $historyKey);
                $this->recordVisit($passed, $response);

                // Read, so gone: the cookie that said it was waiting goes too.
                if ($flashed) {
                    $response = $response->withCookie(Flash::COOKIE, '', maxAge: 0);
                }

                if ($token === null) {
                    // Still per-visitor by default: a page runs its loaders
                    // and #[Authorize] chain for whoever asked, and with no
                    // cache header a shared cache was free to keep one
                    // visitor's page for the next. A page meant for every
                    // visitor says so with its own Cache-Control.
                    return $response->header('cache-control') !== null
                        ? $response
                        : $response->withHeader('cache-control', 'private, no-cache')->withAddedHeader('vary', 'Cookie');
                }

                // The token carries a session and user hash, a tenant and a
                // panel, so the response holding it is per-visitor. A shared cache
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
            $failure = $e;
            $this->renderFailed($e, $request);
            $response = $this->exceptions->handle($e, $request);

            // An HttpException can carry a redirect too, and it is answered
            // here rather than inside the middleware above.
            return $navigating ? TranslateRedirects::translate($request, $response) : $response;
        }
    }

    /**
     * The failed document request's response, as the application's error
     * page for its status -- or as it was, when there is none.
     *
     * "Render failures: Inertia falls back to client rendering, which RSC
     * cannot do ... Instead PHP renders an error page in its own shell." The
     * error page is a page, rendered by the renderer, so it is skipped when
     * the renderer is what failed in a way that leaves it unusable -- gone,
     * cut off, out of time -- and anything that fails while rendering it
     * falls back to the ErrorShell, which PHP writes alone.
     *
     * @param \Closure(LoadedRoute, Request): Response $respond
     */
    private function withErrorPage(Request $request, Response $response, \Closure $respond, ?\Throwable $failure): Response
    {
        return self::wantsErrorPage($request, $response) ? $this->drawErrorPage($request, $response, $respond, $failure) : $response;
    }

    /**
     * The error page for $status, as a document request failing with it
     * would be answered: behind the global middleware, so the session and
     * the signed-in user are what the page's layouts see -- for an
     * application to show one in a situation of its own, and to look at
     * each of its error pages without having to cause the failure.
     *
     * @param \Closure(LoadedRoute, Request): Response $respond
     */
    public function errorPage(Request $request, int $status, \Closure $respond): Response
    {
        try {
            return $this->globalStack->run(
                $this->withPreviousUrl($request),
                fn (Request $passed): Response => $this->drawErrorPage($passed, Response::make($status), $respond, null),
            );
        } finally {
            $this->scope->reset();
        }
    }

    /** @param \Closure(LoadedRoute, Request): Response $respond */
    private function drawErrorPage(Request $request, Response $response, \Closure $respond, ?\Throwable $failure): Response
    {
        $overlaid = $this->dev && $failure !== null && DevOverlay::shows($failure);
        $chain = $this->routes->errorChain($response->status);
        if ($chain === null && !$overlaid) {
            return $response;
        }

        $error = $failure === null ? HttpError::returned($response->status) : $this->exceptions->errorFor($failure);
        // The status the response carries, which is what the visitor gets:
        // a classified failure and its response always agree on it, and a
        // returned refusal has nothing else to go on.
        $error = new HttpError($response->status, $error->code, $error->reason, $error->digest);
        $page = $chain === null
            // In dev, a failure with no error page of its own still has its
            // overlay: the framework's bare page allows no script to carry it.
            ? $this->carried($response, $this->errorShell->respond($error, $request))
            : $this->renderedErrorPage($chain, $error, $request, $response, $respond, $failure);

        if (!$overlaid) {
            return $page;
        }

        $overlay = DevOverlay::render($failure, $error->digest, DevOverlay::nonceOf($page->header('content-security-policy')));

        return Response::make($page->status, DevOverlay::into($page->body(), $overlay), $page->headers())->withCookiesOf($page);
    }

    /**
     * The error page rendered by the renderer, or the shell when it cannot be.
     *
     * @param \Closure(LoadedRoute, Request): Response $respond
     */
    private function renderedErrorPage(
        RouteChain $chain,
        HttpError $error,
        Request $request,
        Response $response,
        \Closure $respond,
        ?\Throwable $failure,
    ): Response {
        $rendererUnusable = $failure instanceof ProtocolException
            || ($failure instanceof RendererException && !$failure->failure()->leavesRendererUsable());

        try {
            if ($rendererUnusable) {
                return $this->carried($response, $this->errorShell->respond($error, $request));
            }

            // The token a form in the header posts without script, as on any
            // page; never a route token or a minter, which an error page --
            // no route of its own -- has no business carrying.
            $token = $this->csrf?->for($request);
            $passed = $token === null ? $request : $request->withAttribute(self::CSRF_TOKEN, $token);

            $loaded = $this->errorLoaders->run($chain, $passed, provided: [HttpError::class => $error]);
            $rendered = $respond($loaded, $passed);
            if ($rendered->status !== 200) {
                throw new \UnexpectedValueException("The error page for {$response->status} was answered with {$rendered->status}.");
            }

            return $this->carried($response, $rendered->withStatus($response->status));
        } catch (\Throwable $e) {
            // Reported, and answered by the shell under the original
            // failure's digest: that is the line the visitor's reference has
            // to find, and this one says why the page around it could not be
            // drawn.
            $this->report($e);

            return $this->carried($response, $this->errorShell->respond($error, $request));
        }
    }

    /**
     * Only a browser asking for a document, with a GET, outside the
     * framework's own endpoints: an endpoint's caller wants its JSON, a 422
     * is a form's errors and not a failure, and a client navigation that
     * fails is loaded again as a document, which lands here.
     */
    private static function wantsErrorPage(Request $request, Response $response): bool
    {
        return $response->status >= 400
            && $response->status !== 422
            && \in_array($request->method, ['GET', 'HEAD'], true)
            && !$request->wantsRscPayload()
            && !str_starts_with($request->path, '/_')
            && str_contains($request->header('accept') ?? '', 'text/html');
    }

    /**
     * The error page, carrying what the refusal had to say besides its status:
     * its cookies, Retry-After and the rate-limit counters on a 429, Allow on
     * a 405. Never kept by a shared cache: it was drawn for this visitor.
     */
    private function carried(Response $failed, Response $page): Response
    {
        $page = $page->withCookiesOf($failed)
            ->withHeader('cache-control', 'private, no-store')
            ->withAddedHeader('vary', 'Cookie');

        foreach (['retry-after', 'allow', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset'] as $header) {
            $value = $failed->header($header);
            if ($value !== null) {
                $page = $page->withHeader($header, $value);
            }
        }

        return $page;
    }

    /**
     * Tells the listeners when $e is a failed render, and rethrows it under
     * throw_on_error.
     *
     * The digest handed over is the one the response will carry, computed the
     * same way, so a listener's alert and the visitor's reference name the
     * same log line. A listener that throws is reported and the rest still
     * run: one broken metrics hook must not decide what the visitor is shown.
     */
    private function renderFailed(\Throwable $e, Request $request): void
    {
        $failure = match (true) {
            $e instanceof RendererException => $e->failure(),
            $e instanceof ProtocolException => RenderFailure::ProtocolViolation,
            default => null,
        };
        if ($failure === null) {
            return;
        }

        $event = new RenderFailed($e, $failure, $request, ExceptionHandler::digest($e));
        foreach ($this->renderFailed as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $thrown) {
                $this->report($thrown);
            }
        }

        if ($this->throwOnRenderError) {
            throw $e;
        }
    }

    /**
     * Logs a failure that did not fail its response -- a #[Rescue] field, a
     * fragment subtree the renderer rendered as an error row, a query that
     * threw -- and returns the digest it was logged under.
     *
     * An application that builds its own Encoder, QueryRunner or
     * RenderChannel passes this to each, so what they rescue is logged where
     * every failed request is.
     */
    public function report(\Throwable $e, ?string $digest = null): string
    {
        return $this->exceptions->report($e, $digest);
    }

    /**
     * Why a call to query $name may not run in the request's panel as it
     * stands, or null when it may; see EntryContext::queryRefusal().
     *
     * An application that builds its own QueryRunner for its render channel
     * passes this as the runner's `panels`, so a query declared for a
     * stricter panel is refused, call by call, from a page that is not held
     * to it -- as the Kernel's own runner does.
     */
    public function queryPanelRefusal(string $name): ?string
    {
        return $this->entry->queryRefusal($this->queries, $name);
    }

    /**
     * The live minter for a render of this route, bound to the request as it
     * stands -- session, user and auth epoch, tenant, panel, build -- or null
     * without live updates, a route name or a session.
     *
     * A page render gets one as Kernel::LIVE_MINTER, which counts what its
     * loaders read; an application rendering a fragment asks here, inside
     * the fragment's own request, with no loaders to count -- the fragment
     * endpoint runs none.
     *
     * @param array<string, string> $params
     */
    public function liveMinter(Request $request, ?string $route, array $params, ?TagsRead $loaders = null): ?LiveMinter
    {
        if ($this->live === null || $route === null) {
            return null;
        }

        $session = ($this->live->sessionId)($request);
        if ($session === '') {
            return null;
        }

        return new LiveMinter(
            signer: $this->live->signer,
            topics: $this->live->topics,
            route: $route,
            params: $params,
            panel: $this->panels?->currentOrNull() ?? '',
            tenant: $this->orm->tenant(),
            build: $this->builds->current ?? 'dev',
            sessionId: $session,
            user: $this->auth->id(),
            authEpoch: $this->auth->epoch(),
            loaders: $loaders,
        );
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
     * The outermost per-address guard: the bucket's budget ten times over,
     * counted before anything else runs.
     *
     * @param array<string, int> $limits
     * @return list<Middleware>
     */
    private function floodGuardFor(?Throttle $throttle, string $name, array $limits): array
    {
        if ($throttle === null || ($limits[$name] ?? 0) <= 0) {
            return [];
        }

        return [new ThrottleRequests($throttle, "{$name}.flood", $limits[$name] * 10, byAddress: true, advertise: false)];
    }

    /**
     * `throttle:<name>`, or nothing when the application configured none.
     *
     * Absent rather than unlimited-by-default: a throttle with no shared
     * store is a limit multiplied by the worker count, which looks enforced
     * and is not, so an application has to say what store it means.
     *
     * @param array<string, int> $limits
     * @param (\Closure(Request): int)|null $cost hits a request counts as; one by default
     * @return list<Middleware>
     */
    private function throttleFor(
        ?Throttle $throttle,
        string $name,
        array $limits,
        bool $byAddress = true,
        ?\Closure $cost = null,
    ): array {
        if ($throttle === null || ($limits[$name] ?? 0) <= 0) {
            return [];
        }

        return [new ThrottleRequests($throttle, $name, $limits[$name], byAddress: $byAddress, cost: $cost)];
    }

    /**
     * The page response, setting the CSRF cookie when the request does not
     * already carry its session's token.
     *
     * Nothing issued it before, so every POST endpoint answered 419 to
     * every client. On page responses, because a page is where a client
     * arrives before it can POST anything; and only when it would change,
     * so a cookie is not re-sent on every navigation. A session that
     * rotated would get its new token on the next page it loads -- unless
     * that page fails, which issues nothing -- so an application that
     * rotates the session sets the new token with the new session cookie.
     *
     * Readable by script -- not HttpOnly -- because the client copies it
     * into the header; Secure, SameSite=Lax and `__Host-`, as the spec's
     * cookie rule requires.
     */
    private function withCsrfCookie(Request $request, Response $response): Response
    {
        if ($this->csrf === null || $response->status >= 400) {
            return $response;
        }

        $token = $this->csrf->for($request);
        if ($token === null || hash_equals($token, $request->cookie(VerifyCsrfToken::COOKIE) ?? '')) {
            return $response;
        }

        // Per session, so per visitor: a shared cache must neither keep the
        // response nor hand its cookie to someone else.
        return $response
            ->withCookie(VerifyCsrfToken::COOKIE, $token, httpOnly: false)
            ->withHeader('cache-control', 'private, no-store')
            ->withAddedHeader('vary', 'Cookie');
    }

    /**
     * The request, carrying what a page's forms need from the server: the
     * session's CSRF token, for the hidden field a form posted without
     * script sends, and the flash such a post left for this page.
     *
     * The flash is read only when the cookie says one is waiting, so a page
     * with nothing flashed asks the store nothing; and only by a document
     * request, which is where a post made without script lands: its 303 is
     * followed by the browser, as a document load. Never by a prefetch,
     * which runs a page the person may not open -- "Prefetch requests skip
     * side effects" -- nor by a client router's RSC request: a poll, a
     * partial reload or a visit in another of the person's tabs, sharing the
     * cookie, took the flash and its errors from the page the post was sent
     * back to.
     *
     * @return array{Request, bool} the request, and whether a flash was read
     */
    private function withFormState(Request $request): array
    {
        $token = $this->csrf?->for($request);
        if ($token !== null) {
            $request = $request->withAttribute(self::CSRF_TOKEN, $token);
        }

        if ($this->flashes === null || $request->cookie(Flash::COOKIE) === null
            || $request->isPrefetch() || $request->wantsRscPayload()) {
            return [$request, false];
        }

        $flash = $this->flashes->pull($request);

        return [$flash === [] ? $request : $request->withAttribute(self::FLASH, $flash), true];
    }

    /**
     * The request, able to say the URL its session visited before it.
     *
     * Attached to every request -- an action sending its caller back reads it
     * as much as a page does -- and read only when something asks. Bound to
     * the request as it arrived: what the session visited before this request,
     * whatever this request goes on to do to the session.
     */
    private function withPreviousUrl(Request $request): Request
    {
        $store = $this->previousUrls;
        if ($store === null) {
            return $request;
        }

        return $request->withAttribute(
            PreviousUrl::ATTRIBUTE,
            new PreviousUrl(static fn (): ?string => $store->get($request)),
        );
    }

    /**
     * Records a page the session has just been shown as its previous URL --
     * when the request was a visit (see PreviousUrl::records()).
     *
     * The URL already recorded is read first, so a reload of the page already
     * recorded writes nothing; and so that anything asking after this, for
     * this request, is still told the visit before it.
     *
     * A store that fails is reported and the page goes out regardless: which
     * page was last is bookkeeping, and not worth the page someone asked for.
     */
    private function recordVisit(Request $request, Response $response): void
    {
        if ($this->previousUrls === null || !PreviousUrl::records($request, $response)) {
            return;
        }

        // Only a URL that can be gone back to: the query is the client's, as
        // sent, and one redirect() would refuse -- a backslash in it, say --
        // would only be refused again when it was read back.
        $url = PreviousUrl::of($request);
        if (\strlen($url) > PreviousUrl::MAX_BYTES || !Response::isSameOriginTarget($url)) {
            return;
        }

        try {
            if ($request->previousUrl() !== $url) {
                $this->previousUrls->put($request, $url);
            }
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    /**
     * The page's history key, or null when its history is not encrypted.
     *
     * "On by default for authenticated routes ... `#[EncryptHistory(false)]`
     * opts a page out." Who is signed in is decided per request here -- no
     * route says it needs a user, a page's middleware does -- so a page is
     * encrypted when it is rendered for a signed-in user, unless it opts
     * out; and a page that says #[EncryptHistory] is encrypted for anyone
     * with a session. Bound to the same session, user, auth epoch, tenant and
     * panel the route token is, read at the same moment, so the key changes
     * exactly when the client's binding does (ADR 0019).
     */
    private function historyKeyFor(LoadedRoute $loaded, Request $request): ?string
    {
        if ($this->historyKeys === null || !($loaded->chain->page->encryptHistory ?? $this->auth->check())) {
            return null;
        }

        return $this->historyKeys->for(
            $request,
            $this->orm->tenant(),
            $this->panels?->currentOrNull() ?? '',
            $this->auth->id() ?? '',
            $this->auth->epoch(),
        );
    }

    /**
     * The page response, carrying its history key to a client navigation.
     *
     * A header for a payload, which the router reads; a document has it in
     * its page metadata already. Either way the response is the session's
     * alone: no shared cache may keep it, nor hand it to anyone else.
     */
    private function withHistoryKey(Request $request, Response $response, ?string $key): Response
    {
        if ($key === null || $response->status >= 400) {
            return $response;
        }

        if ($request->wantsRscPayload()) {
            $response = $response->withHeader(HistoryKeys::HEADER, $key);
        }

        return $response
            ->withHeader('cache-control', 'private, no-store')
            ->withAddedHeader('vary', 'Cookie');
    }

    /**
     * The route as loaded, with the #[Once] keys the browser holds -- when
     * it can be believed to hold them for this response.
     *
     * "Client-held data ... is bound to (user, tenant)." What the browser
     * keeps under a once key was rendered for some session, and a request
     * listing it would have the field left out of a page rendered for
     * whoever is asking now. Sign in, sign out, switch tenant: the next
     * request still lists what was kept before, and honoured, the page would
     * be drawn with the last session's content in it. So the list counts
     * only beside `X-Once-Binding`, the route token of a page the content
     * was kept with, and only when that token verifies and names the same
     * session, user and auth epoch, tenant, panel and build as the token this
     * response carries. The user and epoch because a role change or an
     * impersonation can keep the session id: what was drawn under the old
     * role is not to be claimed under the new one.
     * Without a token of its own a response honours nothing: the browser
     * keeps once content under that binding, and could not file it.
     *
     * Never a way to see more: a held key sends less than the same request
     * without it. And it skips only the field's own resolver -- every
     * middleware, check and loader ran before this (ADR 0020).
     */
    private function withHeldOnce(LoadedRoute $loaded, Request $request, ?string $token): LoadedRoute
    {
        $signer = $this->support?->routeSigner;
        $presented = $request->header('x-once-binding');
        if ($token === null || $signer === null || $presented === null || !$request->wantsRscPayload()) {
            return $loaded;
        }

        $keys = PayloadContext::listFrom($request->header('x-have-once'), PayloadContext::KEY);
        if ($keys === []) {
            return $loaded;
        }

        try {
            $theirs = RouteToken::decode($presented, $signer)->claims;
            $ours = RouteToken::decode($token, $signer)->claims;
        } catch (TokenError) {
            return $loaded;
        }

        $same = hash_equals($ours->sessionHash, $theirs->sessionHash)
            && hash_equals($ours->authHash, $theirs->authHash)
            && $ours->tenant === $theirs->tenant
            && $ours->panel === $theirs->panel
            && $ours->build === $theirs->build;

        return $same ? $loaded->withContext($loaded->context->withHeldOnce($keys)) : $loaded;
    }

    /**
     * The minter for a page render, or null when the render may not mint.
     *
     * ADR 0009: "A page with no deferred boundaries cannot be talked into
     * minting." Every named page with a session used to get a minter, so a
     * compromised renderer could have the signing key used from any page
     * at all. Now only a route the manifest says contains a boundary gets
     * one; every other render passes none, and a mint frame from it is a
     * protocol violation.
     *
     * Null too without fragment support, without a name -- the fragment
     * endpoint finds the originating route by name -- and without a
     * session, which the minter refuses to sign for.
     */
    private function minterFor(LoadedRoute $loaded, Request $request): ?FragmentMinter
    {
        $name = $loaded->chain->page->name;
        if ($this->support === null || $this->manifest === null || $name === null
            || !$this->manifest->defersOn($name)
            || ($this->support->sessionId)($request) === '') {
            return null;
        }

        // And only for the components the build found the route's
        // boundaries naming, where it could read them all.
        return $this->support->minterFor(
            $request,
            route: $name,
            params: $loaded->chain->parameters,
            components: $this->manifest->componentsFor($name),
        );
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
            user: $this->auth->id(),
            authEpoch: $this->auth->epoch(),
        ))->mint($loaded->chain);
    }
}
