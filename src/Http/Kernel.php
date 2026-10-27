<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Actions\Revalidator;
use Tinvar\Auth\AuthContext;
use Tinvar\Channels\ChannelEndpoint;
use Tinvar\Channels\ChannelRegistry;
use Tinvar\Channels\ChannelRunner;
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
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\Endpoints\QueryEndpoint;
use Tinvar\Http\Middleware\CheckVersion;
use Tinvar\Http\Middleware\TranslateRedirects;
use Tinvar\Container\Container;
use Tinvar\Dev\DevToolbar;
use Tinvar\Dev\Loopback;
use Tinvar\Events\Events;
use Tinvar\Http\Middleware\RequireAuthentication;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realms;
use Tinvar\Identity\TlsAskEndpoint;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Live\TagsRead;
use Tinvar\Mail\UnsubscribeEndpoint;
use Tinvar\Observability\Logger;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\Probes;
use Tinvar\Observability\Telemetry;
use Tinvar\Observability\TelemetryEndpoint;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\StaticPages;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\RenderFailed;
use Tinvar\Render\RenderFailure;
use Tinvar\Render\RendererException;
use Tinvar\Render\RouteMinter;
use Tinvar\Render\RouteToken;
use Tinvar\Render\SharedHold;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenError;
use Tinvar\Data\PayloadContext;
use Tinvar\Data\SharedProviders;
use Tinvar\Data\SharedReads;
use Tinvar\Data\SharedRegistry;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteChain;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\DevMode;
use Tinvar\Support\StandIns;
use Tinvar\Transfers\DownloadEndpoint;
use Tinvar\Transfers\TransferEndpoint;
use Tinvar\Transfers\TransferRunner;
use Tinvar\Transfers\Transfers;
use Tinvar\Transfers\TransferStatus;
use Tinvar\Uploads\FileEndpoint;
use Tinvar\Uploads\FileUrls;
use Tinvar\Uploads\UploadBinder;
use Tinvar\Uploads\UploadEndpoint;
use Tinvar\Uploads\Uploads;
use Tinvar\Cache\TagVersions;
use Tinvar\Data\SharedTags;
use Tinvar\Endpoints\CompiledEndpoint;
use Tinvar\Endpoints\EndpointHandler;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Endpoints\EndpointRunner;
use Tinvar\Uploads\UploadTickets;
use Tinvar\Validation\Validator;
use Tinvar\Webhooks\WebhookEndpoint;

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

    /** /_channel/{name}, mounted only when an application has #[Channel]s and live updates. */
    public readonly ?ChannelEndpoint $channels_;

    /** An application with #[Channel]s and no hub: /_channel answers that there is nothing to hear. */
    private readonly bool $channelsUnheard;

    /** /_upload and /_file, mounted only when an application supplies Uploads. */
    public readonly ?UploadEndpoint $uploads_;
    public readonly ?FileEndpoint $files_;

    /**
     * /_export, /_import and /_download (ADR 0070), and what a loader reads
     * a user's transfers through: mounted only when an application supplies
     * Transfers.
     */
    public readonly ?TransferEndpoint $transfers_;
    public readonly ?DownloadEndpoint $downloads_;
    public readonly ?TransferStatus $transferStatus;

    /** /_vitals and /_errors, mounted only when an application asks for client telemetry. */
    public readonly ?TelemetryEndpoint $clientTelemetry_;

    /**
     * What a loader issues upload tickets and file URLs with, also in the
     * container by type; null without Uploads.
     */
    public readonly ?UploadTickets $uploadTickets;
    public readonly ?FileUrls $fileUrls;

    /**
     * What a page's loaders read, by segment, for its live token -- given
     * to a loader that asks for it by type.
     */
    public readonly TagsRead $tagsRead;

    /**
     * The gate every #[Authorize] is asked through, and the query cache the
     * runners share: read by the testing layer, which runs a query in PHP
     * with the same checks and cache a render's call meets.
     */
    public readonly Gate $gate;

    public readonly QueryCache $cache;

    /**
     * What stands in for a query's or an action's body: a test's fakes, or
     * null -- always null in production, where the constructor refuses one.
     * Read by the testing layer, which installs fakes in it and runs its own
     * direct query calls through it as the Kernel's runners do.
     */
    public readonly ?StandIns $standIns;

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
        // Three steps per move between hosts, and a move is a person
        // following a link: a minute's budget is twenty moves.
        'handoff' => 60,
        // Each one a file written to disk: fewer than actions.
        'uploads' => 30,
        // Images on a page, each its own request; and an export's download.
        'files' => 600,
        // Starting an export or an import: each a job, and an import a file
        // written to disk -- fewer than uploads.
        'transfers' => 10,
        // /_vitals and /_errors: a vitals report when a page is left, and
        // the errors it saw, gathered -- a handful a minute from anyone
        // reading the site, and the first budget a flood of fabricated
        // reports exhausts.
        'telemetry' => 60,
        // The application's own #[Get], #[Post] and #[Endpoint] routes,
        // one bucket for all of them: twice an action's, since a GET among
        // them is a page's fetch or a download, and a write among them
        // spends the same bucket as the reads.
        'endpoints' => 120,
    ];

    private readonly ExceptionHandler $exceptions;

    /** @var list<\Closure(RenderFailed): void> */
    private readonly array $renderFailed;

    /** The application's event bus, which hears RenderFailed after the closures. */
    private readonly ?Events $events;

    /** The dev toolbar, under `tinvar dev` only; never on a Kernel not built for dev. */
    private readonly ?DevToolbar $toolbar;

    /** Whether a failed render escapes handle() rather than becoming an error page. */
    private readonly bool $throwOnRenderError;

    /** Runs an #[ErrorPage]'s layouts and loader, as the router runs a page's. */
    private readonly LoaderRunner $errorLoaders;

    /** The page PHP writes alone when the error page cannot be rendered. */
    private readonly ErrorShell $errorShell;

    /** The application's shared data (ADR 0060); null for none. */
    private readonly ?SharedRegistry $shared;

    /**
     * The tags once-shared holds are bound to, on the server (ADR 0074): for
     * a job to invalidate() what it changed, so no browser is believed to
     * hold a value from before. Null when the Kernel was given no
     * TagVersions.
     */
    public readonly ?SharedTags $sharedTags;

    /**
     * Each shared key's panel, worked out once from its declarations and
     * namespace: null for a key every page may read.
     *
     * @var array<string, string|null>
     */
    private readonly array $sharedPanels;

    /** Which request each provider instance served: one handed to a later request is refused. */
    private readonly SharedProviders $sharedProviders;

    /** Builds a shared data provider, as it builds pages and queries. */
    private readonly \Closure $resolver;

    /** The application's global middleware, alone: what errorPage() runs an error page behind. */
    private readonly Pipeline $globalStack;

    /**
     * What every entry point's declarations put around it; see EntryContext.
     * Public for the testing layer, which places a query called in PHP in
     * the panel its declarations or namespace give it.
     */
    public readonly EntryContext $entry;

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
    private readonly Pipeline $channelStack;
    private readonly Pipeline $handoffStack;
    private readonly Pipeline $uploadStack;
    private readonly Pipeline $fileStack;
    private readonly Pipeline $transferStack;
    private readonly Pipeline $telemetryStack;

    /**
     * The application's #[Get], #[Post] and #[Endpoint] routes (ADR 0067):
     * an action's stack -- the global middleware, CSRF on a method that
     * changes something, `throttle:endpoints`, authentication -- or, for a
     * #[WithoutSession] endpoint, the throttle alone, its own middleware
     * standing in for the session.
     */
    private readonly Pipeline $endpointStack;
    private readonly Pipeline $sessionlessStack;

    /** A CORS preflight's: the flood guard alone (ADR 0074). */
    private readonly Pipeline $preflightStack;

    /** Null when the application declares no endpoints. */
    public readonly ?EndpointHandler $endpoints_;

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
     * The request attribute marking a render whose client may hand back
     * the holds of once-shared values it keeps (ADR 0065): a payload
     * request for a page handed a route token, and a fragment request
     * behind its verified token (ADR 0074). shared() believes holds on such
     * a request alone, each verified against the binding the render is made
     * for. An action's re-render, a document or a page drawn any other way
     * produces every key it reads.
     */
    public const string HELD_SHARED = 'tinvar.held_shared';

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
     * @param WebhookEndpoint|null $webhooks the application's inbound webhooks, answered
     *        ahead of everything else: a delivery is verified by its signature, not by
     *        a session, so none of the global stack applies to it
     * @param Events|null $events the application's event bus: every RenderFailed is
     *        dispatched on it, after the $renderFailed closures, so a #[Listener] for
     *        it hears each failed render as they do
     * @param DevToolbar|null $toolbar the dev toolbar `tinvar dev` asked for: each
     *        request is recorded, documents carry the toolbar, and /_dev/toolbar
     *        answers for them -- on loopback, with its token. Refused unless built
     *        for dev ($dev), so no production Kernel can carry one.
     * @param HandoffEndpoint|null $handoff moving a session between the application's
     *        hosts (ADR 0039): mounted at /_handoff/*, behind the global stack -- which
     *        starts the session and signs its user in -- CSRF and the handoff throttle.
     *        One that may reach a custom domain asks consent with a form, and needs $csrf
     * @param Uploads|null $uploads where uploaded files and their records are kept, and
     *        the keys their tickets are signed with. Given, /_upload and /_file are
     *        mounted, actions are handed their `Upload` parameters, and UploadTickets
     *        and FileUrls are in the container; without them, an action taking an
     *        upload is refused when called
     * @param Telemetry|null $telemetry where each request is reported (ADR 0041): its id,
     *        on the request as RequestId::ATTRIBUTE and on the response as X-Request-Id;
     *        a log line, metrics and spans for it; and, without a `log` of its own, every
     *        failure the exception handler classifies, through its Logger
     * @param Probes|null $probes /_health, /_ready and /_metrics, answered ahead of
     *        everything else -- the dev toolbar's endpoint and webhooks included --
     *        since a probe is not a visitor
     * @param UnsubscribeEndpoint|null $unsubscribe one-click unsubscribe from email
     *        (ADR 0048): mounted at /_mail/unsubscribe/*, beside webhooks and as they
     *        are -- a mail provider's server POSTs it, with a signed link and no
     *        session, so none of the global stack applies to it
     * @param Realms|null $realms the application's hosts (ADR 0039), the handoff's unless
     *        given: an entry point in a panel only the platform's hosts may carry is
     *        refused, as a 404, on any other host -- a tenant's custom domain, or a host
     *        the application does not know
     * @param StaticPages|null $staticPages the static pages an action's invalidated
     *        tags take out of service, once its writes commit
     * @param int $maxBodyBytes the largest body /_action and /_query take, uploads
     *        apart: the endpoints table's 1 MB unless given. "The limits are proposed
     *        defaults, configurable per app" -- up to Limits::MAX_BODY_BYTES_CEILING,
     *        and anything else is refused here, so a misconfiguration stops the
     *        application starting rather than opening the endpoints
     * @param int $maxJsonDepth how deep a body to either may nest: the table's 32
     *        unless given, up to Limits::MAX_JSON_DEPTH_CEILING, refused as above
     * @param list<string> $redirectOrigins the external origins ActionResult::location()
     *        may send a browser to, each `https://host` or `https://host:port`
     *        (ExternalOrigins), matched exactly; none unless given, and then
     *        location() is refused wherever it points. One that is not an https
     *        origin is refused here
     * @param TlsAskEndpoint|null $tlsAsk Caddy's on-demand TLS `ask` at /_tls/ask, where a
     *        custom domain is checked before a certificate is obtained or renewed for it:
     *        answered beside the probes, ahead of everything else, since Caddy is not a
     *        visitor either
     * @param StandIns|null $standIns what answers in place of a query's or an action's
     *        body: a test's fakes (the testing layer's BodyFakes), asked by both
     *        runners once a call has passed every check before its body. Refused
     *        wherever the environment says production, as the testing layer is
     * @param bool $clientTelemetry mount POST /_vitals and POST /_errors (TelemetryEndpoint):
     *        a page's web vitals and the errors its script saw, as @tinvar/react/telemetry
     *        reports them, behind the global stack, CSRF and the telemetry throttle, and
     *        recorded in $telemetry's metrics and log. Needs $telemetry, with metrics.
     * @param string|null $clientAssets the directory the build's client assets are served
     *        from at /assets/: a reported error's source file is kept only when it is one
     *        of them, and without one, none is
     * @param TenantResolver|null $tenants the tenancy the application registered (tinvar/tenancy,
     *        ADR 0056): how the tenant a #[TenantFrom] names is read from a request. Without
     *        it, an entry point declaring one is refused here. Given, the Orm must have been
     *        given the tenancy's tree too, or the Kernel refuses to be built
     * @param SharedRegistry|null $shared what every page render may read with `shared()`
     *        (ADR 0060). Given, shared() hands each render the keys of its panel and of
     *        none; without it, a render reads none, and each read is refused
     * @param EndpointRegistry|null $endpoints the application's #[Get], #[Post] and
     *        #[Endpoint] routes (ADR 0067), answered after the framework's own endpoints
     *        and before the pages. One whose path a page's also matches is refused here
     * @param ChannelRegistry|null $channels the application's #[Channel]s (ADR 0069). Given
     *        with live updates, /_channel/{name} subscribes a client to one -- let in through
     *        its tenant, panel stack, middleware, model binding and #[Authorize] -- and the
     *        live trade lets each subscription in again; without live updates, nothing is
     *        mounted and nothing is heard
     * @param TagVersions|null $tagVersions where the versions of the tags once-shared holds
     *        are bound to are kept (ADR 0074). Given, a hold is believed only while its key's
     *        tags are as they were when it was minted; an action's invalidated tags bump
     *        them, and so does a job through $sharedTags. Without it, a hold is believed until
     *        its `until` whatever no response announced
     * @param Transfers|null $transfers the application's exports and imports (ADR 0070): given,
     *        POST /_export/{name} and POST /_import/{name} are mounted behind an action's stack
     *        and the `transfers` throttle, GET /_download/{token} behind the global stack and
     *        the `files` throttle, and Transfers, TransferRunner -- which the jobs run with --
     *        and TransferStatus are in the container
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
        private readonly ?WebhookEndpoint $webhooks = null,
        ?Events $events = null,
        ?DevToolbar $toolbar = null,
        private readonly ?HandoffEndpoint $handoff = null,
        ?Uploads $uploads = null,
        private readonly ?Telemetry $telemetry = null,
        private readonly ?Probes $probes = null,
        private readonly ?UnsubscribeEndpoint $unsubscribe = null,
        ?Realms $realms = null,
        ?StaticPages $staticPages = null,
        int $maxBodyBytes = Limits::MAX_BODY_BYTES,
        int $maxJsonDepth = Limits::MAX_JSON_DEPTH,
        array $redirectOrigins = [],
        private readonly ?TlsAskEndpoint $tlsAsk = null,
        ?StandIns $standIns = null,
        bool $clientTelemetry = false,
        ?string $clientAssets = null,
        ?TenantResolver $tenants = null,
        ?SharedRegistry $shared = null,
        ?EndpointRegistry $endpoints = null,
        ?ChannelRegistry $channels = null,
        ?TagVersions $tagVersions = null,
        ?Transfers $transfers = null,
    ) {
        $this->resolver = $resolver;
        $this->shared = $shared;
        $this->sharedTags = $tagVersions === null ? null : new SharedTags($tagVersions, $orm, $this->report(...));

        // Half registered is neither: entry points would run as tenants the
        // Orm reads no rows of, under tokens that name none of them.
        if ($tenants !== null && !$orm->hasTenancy()) {
            throw new \InvalidArgumentException(
                'The Kernel was given tenancy, and its Orm none: register the tree with the Orm (Tenancy::tree()) too.',
            );
        }

        if ($toolbar !== null && !$dev) {
            throw new \InvalidArgumentException(
                'The dev toolbar was given to a Kernel not built for dev. It shows every request\'s props and frames, '
                . 'and exists only under `tinvar dev`.',
            );
        }
        $this->toolbar = $toolbar;
        // Raw SQL on a tenant table, flagged on the request it ran in.
        if ($toolbar !== null) {
            $this->orm->flagRawStatements($toolbar->rawStatement(...));
            $this->orm->flagLazyLoads($toolbar->lazyLoad(...));
            $this->orm->flagAcrossTenants($toolbar->acrossTenants(...));
        }
        $this->events = $events;

        // A fake answers for a query or an action without its body, so it
        // exists only under test. Fail closed where the environment says this
        // is production, as the testing Client does.
        $marker = $standIns === null ? null : DevMode::productionMarker(getenv());
        if ($marker !== null) {
            throw new \InvalidArgumentException(
                "A Kernel was given stand-ins for query and action bodies under {$marker}=production. "
                . 'They are a test\'s fakes, and never answer in production.',
            );
        }
        $this->standIns = $standIns;
        // A report with nowhere to be recorded would be taken and dropped:
        // refused here, rather than an endpoint that answers 204 to nothing.
        if ($clientTelemetry && $telemetry?->metrics === null) {
            throw new \InvalidArgumentException(
                'Client telemetry records vitals as metrics and errors in the log: give this Kernel a Telemetry with Metrics.',
            );
        }

        // Failures go to the structured log unless the application said
        // where else they go.
        $log ??= $telemetry?->logger->forExceptions();

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

        // The platform host's half of a handoff asks who is signed in; one
        // reading another context would see nobody, ever, and send every
        // visitor to sign in again and again.
        if ($handoff !== null && !$handoff->reads($auth)) {
            throw new \InvalidArgumentException(
                'The HandoffEndpoint reads a different AuthContext than this Kernel, so it would never see who is '
                . 'signed in. Build it with the same one.',
            );
        }

        // A handoff that may reach a custom domain asks consent first, with
        // forms that POST. Without session-bound tokens the post would be
        // checked by a double submit alone, and the screen would have no
        // token to draw its forms with: refused here, rather than a consent
        // anyone's page could answer, or one nobody can.
        if ($handoff !== null && $handoff->asksConsent() && $csrf === null) {
            throw new \InvalidArgumentException(
                'The HandoffEndpoint may hand sessions to custom domains, which it asks consent for with a form: '
                . 'give this Kernel CsrfTokens, so the form carries its session\'s token and the post is held to it.',
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
        // So an action, a query or a page can take the request's logger by
        // type, and write under its id.
        if ($telemetry !== null) {
            $container?->instance(Telemetry::class, $telemetry)->instance(Logger::class, $telemetry->logger);
            if ($telemetry->metrics !== null) {
                $container?->instance(Metrics::class, $telemetry->metrics);
            }
        }
        if ($panels !== null) {
            $container?->instance(PanelContext::class, $panels);
        }
        if ($uploads !== null) {
            $container?->instance(Uploads::class, $uploads);
        }

        $this->panels = $panels;
        $gate ??= new PolicyGate($this->policies, $this->auth);
        // Keyed by the build too, as any cache should be: this worker's own
        // memory holds one build's entries, but it is the default to copy.
        $cache ??= new QueryCache(new \Tinvar\Cache\ArrayStore(), $this->auth, $panels, build: $this->builds->current ?? '');
        $this->gate = $gate;
        $this->cache = $cache;

        // What every entry point's declarations put around it -- the tenant
        // its #[TenantFrom] names, the panel its #[Panel] or namespace puts
        // it in, and that panel's stack -- shared by every path that runs
        // one, so a page, its fragments, its re-render and an action all
        // resolve them the same way. Checked now: a panel nobody defined, or
        // a requirement nothing can hold, is refused here rather than found
        // by whoever first reaches it.
        $entry = new EntryContext($this->orm, $resolver, $panels, $panelRegistry, $memberships, $this->auth, $realms ?? $handoff?->realms, $tenants);
        $entry->check($this->routes, $this->actions, $this->queries, $endpoints, $channels, $transfers?->registry);
        // And every endpoint's path against the pages': a path both match
        // would be answered by whichever the dispatch happened to ask first.
        $endpoints?->assertNoConflicts($this->routes);
        // And against the webhooks', which are answered ahead of everything:
        // an endpoint at one of their paths would never be reached by the
        // method a sender uses, and reached by the rest.
        foreach ($webhooks?->paths() ?? [] as $path) {
            foreach ($endpoints?->all() ?? [] as $endpoint) {
                if ($endpoint->match($path) !== null) {
                    throw new \InvalidArgumentException("{$endpoint->where()} answers at {$path}, which is a webhook's.");
                }
            }
        }
        // Each shared key's panel, now: one naming a panel nobody defined
        // is refused here, as an entry point's is, rather than read as
        // shared with every page.
        $sharedPanels = [];
        if ($shared !== null) {
            foreach ($shared->keys() as $key) {
                $sharedPanels[$key] = $entry->panelFor($shared->panelsOf($key), $shared->classOf($key))?->name;
            }
        }
        $this->sharedPanels = $sharedPanels;
        // And every route's query allowlist the Kernel is shown -- the one
        // fragment support carries, by route name -- against the panels of
        // the queries it names: a render runs them behind its page's panel.
        // And a query with no panel binding that only routes in a panel may
        // call, which /_query would run behind no panel's stack.
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
            // By the route's name, or its view when it has none.
            $telemetry === null ? null : static function (RouteChain $chain) use ($telemetry): void {
                $telemetry->route($chain->page->name ?? $chain->page->view);
            },
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
                // Telling the loaders' tags apart by segment, for the live
                // token each re-render answers with.
                loaders: new LoaderRunner($this->orm, $resolver, $gate, $this->tagsRead),
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
                tags: $this->tagsRead,
                liveMinter: $live === null
                    ? null
                    : fn (Request $request, string $route, array $params, ?TagsRead $loaders): ?LiveMinter => $this->liveMinter($request, $route, $params, $loaders),
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
                $uploads === null ? null : new UploadBinder($uploads, $this->auth, $this->orm, $panels),
                $staticPages,
                $standIns === null ? null : $standIns->action(...),
                $this->sharedTags,
            ),
            maxBodyBytes: $maxBodyBytes,
            revalidator: $revalidator,
            sessionId: $fragments?->sessionId,
            middlewareResolver: self::middlewareFrom($resolver),
            globalMiddleware: $globalMiddleware,
            flashes: $flashes,
            entry: $entry,
            maxJsonDepth: $maxJsonDepth,
            externalOrigins: new ExternalOrigins(...$redirectOrigins),
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
            standIn: $standIns === null ? null : $standIns->query(...),
        );

        $this->queries_ = new QueryEndpoint(
            $queryRunner,
            $allowedQueries,
            maxBodyBytes: $maxBodyBytes,
            middlewareResolver: self::middlewareFrom($resolver),
            globalMiddleware: $globalMiddleware,
            entry: $entry,
            maxJsonDepth: $maxJsonDepth,
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

        // A handoff's three steps are GETs a person follows from host to
        // host: the global stack for the session and its user, the handoff
        // budget, and no authentication, since being signed out on the
        // target is why a handoff starts. CSRF for the one POST, a consent
        // screen's answer; the GETs pass it untouched -- the step among them
        // that changes something is held to the session that began it by
        // PKCE, which is what a CSRF token would have said.
        $this->handoffStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'handoff', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'handoff', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'handoff', $limits, byAddress: false),
        ]);

        // An upload gets an action's stack: it writes, so CSRF and its own
        // throttle; and authentication, with no opt-out -- a ticket is
        // issued to a signed-in user and bound to them, so nobody else has
        // one to use. The action's tenant and panel stack run inside, once
        // the ticket says which action it is for.
        $this->uploadStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'uploads', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'uploads', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'uploads', $limits, byAddress: false),
            new RequireAuthentication($this->auth),
        ]);

        // A file is a GET with no body, so no CSRF; the session, for the
        // token's binding, and its throttle. Its token says who may use it.
        $this->fileStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'files', $limits),
            ...$global,
            ...$this->throttleFor($throttle, 'files', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'files', $limits, byAddress: false),
        ]);

        // "`throttle:telemetry`, CSRF": a report is a POST from a page, so it
        // meets the check every POST does, and its own budget after it. No
        // authentication -- a visitor with no account has a page to report
        // on too -- and nothing the endpoint does needs one: it names a
        // route the registry has, and writes a metric and a log line.
        $this->telemetryStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'telemetry', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'telemetry', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'telemetry', $limits, byAddress: false),
        ]);

        // An endpoint gets an action's stack. CSRF lets a GET and a HEAD
        // through and holds every other method to the session's token, so a
        // #[Post] is protected as an action is -- and authentication reads
        // #[PublicAccess] off the endpoint the request was matched to.
        $this->endpointStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'endpoints', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'endpoints', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'endpoints', $limits, byAddress: false),
            new RequireAuthentication($this->auth, EndpointHandler::entryPoint(...)),
        ]);

        // A #[WithoutSession] endpoint gets the budget and nothing that reads
        // a cookie: no global stack, so no session and no one signed in by
        // one, and so no CSRF token to ask for. Its own middleware signs its
        // caller in, and EndpointHandler asks for authentication after it.
        $this->sessionlessStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'endpoints', $limits),
            ...$this->throttleFor($throttle, 'endpoints', $limits, byAddress: true),
        ]);

        // A CORS preflight runs nothing of an endpoint's and opens no
        // session; the flood guard keeps a stream of them from costing more
        // than the lookup that answers them (ADR 0074).
        $this->preflightStack = new Pipeline($this->floodGuardFor($throttle, 'endpoints', $limits));

        foreach ($endpoints?->all() ?? [] as $endpoint) {
            // A bucket nothing counts would look enforced and limit nothing.
            if ($endpoint->throttle !== null && $throttle === null) {
                throw new \InvalidArgumentException(
                    "{$endpoint->where()} names the throttle bucket '{$endpoint->throttle->bucket}', and the Kernel was given "
                    . 'no Throttle to count it in.',
                );
            }
            // Global middleware is what opens the session and signs in whoever
            // it names; declared again on a sessionless endpoint, it would run
            // there -- over a request with no cookie, but run.
            $declaredGlobal = $endpoint->sessionless ? array_intersect($endpoint->middleware, $globalMiddleware) : [];
            if ($declaredGlobal !== []) {
                throw new \InvalidArgumentException(
                    "{$endpoint->where()} is #[WithoutSession] and declares the global middleware " . implode(', ', $declaredGlobal)
                    . ', which opens the session it runs without. Authenticate its caller with middleware of its own.',
                );
            }
        }

        $this->endpoints_ = $endpoints === null ? null : new EndpointHandler(
            registry: $endpoints,
            runner: new EndpointRunner($resolver, $this->orm, $gate, new Validator($this->orm), $encoder),
            auth: $this->auth,
            middlewareResolver: self::middlewareFrom($resolver),
            globalMiddleware: $globalMiddleware,
            entry: $entry,
            maxBodyBytes: $maxBodyBytes,
            maxJsonDepth: $maxJsonDepth,
            throttle: $throttle,
            endpointsLimit: $limits['endpoints'] ?? 0,
        );

        // Checked above to have the Telemetry and Metrics it records into.
        $this->clientTelemetry_ = $clientTelemetry
            ? new TelemetryEndpoint($telemetry->logger, $telemetry->metrics, $this->routes, $clientAssets)
            : null;

        $this->uploads_ = $uploads === null ? null : new UploadEndpoint(
            uploads: $uploads,
            actions: $this->actions,
            entry: $entry,
            auth: $this->auth,
            orm: $this->orm,
            middlewareResolver: self::middlewareFrom($resolver),
            panels: $panels,
            globalMiddleware: $globalMiddleware,
        );
        $this->files_ = $uploads === null ? null : new FileEndpoint($uploads, $this->auth);
        $this->uploadTickets = $uploads === null ? null : new UploadTickets($this->actions, $entry, $this->auth, $uploads);
        $this->fileUrls = $uploads === null ? null : new FileUrls($uploads, $this->auth);
        if ($this->uploadTickets !== null && $this->fileUrls !== null) {
            $container?->instance(UploadTickets::class, $this->uploadTickets)
                ->instance(FileUrls::class, $this->fileUrls);
        }

        // An export or import is started as an action is called: it writes,
        // so CSRF and its own budget; and authentication, with no opt-out --
        // it runs as a user, and its link is bound to them. Its own tenant
        // and panel stack run inside, once the path says which it is.
        $this->transferStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'transfers', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'transfers', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'transfers', $limits, byAddress: false),
            new RequireAuthentication($this->auth),
        ]);
        if ($transfers === null) {
            $this->transfers_ = null;
            $this->downloads_ = null;
            $this->transferStatus = null;
        } else {
            $transferRunner = new TransferRunner(
                transfers: $transfers,
                orm: $this->orm,
                gate: $gate,
                validator: new Validator($this->orm),
                auth: $this->auth,
                resolver: $resolver,
                panels: $panels,
                report: function (\Throwable $e): void {
                    $this->report($e);
                },
            );
            $this->transfers_ = new TransferEndpoint(
                transfers: $transfers,
                runner: $transferRunner,
                entry: $entry,
                auth: $this->auth,
                orm: $this->orm,
                middlewareResolver: self::middlewareFrom($resolver),
                panels: $panels,
                globalMiddleware: $globalMiddleware,
                maxBodyBytes: $maxBodyBytes,
                maxJsonDepth: $maxJsonDepth,
            );
            $this->downloads_ = new DownloadEndpoint(
                transfers: $transfers,
                entry: $entry,
                auth: $this->auth,
                orm: $this->orm,
                middlewareResolver: self::middlewareFrom($resolver),
                panels: $panels,
                globalMiddleware: $globalMiddleware,
            );
            $this->transferStatus = new TransferStatus($transfers, $this->auth, $this->orm, $panels);
            $container?->instance(Transfers::class, $transfers)
                ->instance(TransferRunner::class, $transferRunner)
                ->instance(TransferStatus::class, $this->transferStatus);
        }

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
            shared: $this->sharedForFragment(...),
        );

        // Both, or neither: a manifest says which boundaries exist, and
        // FragmentSupport is what can sign for them. Either alone cannot
        // answer a bootstrap request.
        $this->bootstrap_ = $fragments === null || $manifest === null || !$manifest->hasAny()
            ? null
            : new BootstrapEndpoint($this->routes, $manifest, $fragments, $entry);

        $this->support = $fragments;

        // A channel is heard through the hub, so with no hub there is nothing
        // to subscribe to: /_channel is not mounted, and publishing sends
        // nothing (Channels).
        $this->channelsUnheard = $live === null && $channels !== null;
        $this->channels_ = $live === null || $channels === null ? null : new ChannelEndpoint(
            registry: $channels,
            runner: new ChannelRunner($this->orm, $gate),
            live: $live,
            topics: $live->channelTopics ?? throw new \InvalidArgumentException(
                'The Kernel was given #[Channel]s and live updates with no channel topics: build them with LiveUpdates::fromKey().',
            ),
            auth: $this->auth,
            orm: $this->orm,
            entry: $entry,
            middlewareResolver: self::middlewareFrom($resolver),
            panels: $panels,
            builds: $this->builds,
            globalMiddleware: $globalMiddleware,
        );

        // A subscription to a channel gets the live trade's budget -- it is
        // one step of the same thing -- and an action's authentication, read
        // off the channel the path names: someone signed in, unless the
        // channel is #[PublicAccess].
        $this->channelStack = new Pipeline([
            ...$this->floodGuardFor($throttle, 'live', $limits),
            ...$global,
            new VerifyCsrfToken(tokens: $csrf),
            ...$this->throttleFor($throttle, 'live', $limits, byAddress: true),
            ...$this->throttleFor($throttle, 'live', $limits, byAddress: false),
            new RequireAuthentication($this->auth, $this->channels_ === null ? null : $this->channels_->entryPoint(...)),
        ]);

        // Every trade runs the route each token names through its tenant,
        // panel stack, middleware and #[Authorize] chain, as /_fragment does:
        // a session taken out of the panel stops being granted its topics.
        // And each channel subscription's channel, the same way.
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
            channels: $this->channels_,
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

        // Nor is a shared data provider: one the resolver hands out again
        // after this is refused, however it was bound.
        $this->sharedProviders = new SharedProviders();
        $this->scope->add('shared', $this->sharedProviders->endRequest(...));

        // Nor is a request's id, should one escape without being ended.
        if ($telemetry !== null) {
            $this->scope->add('telemetry', $telemetry->forget(...));
        }
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
        // First, so everything after -- the session, the routing, a failure
        // in either, the toolbar's recording -- is under the request's id.
        $request = $this->telemetry?->begin($request) ?? $request;
        $request = $this->withPreviousUrl($request);
        $this->toolbar?->begin($request);
        $failure = null;
        $ended = false;

        try {
            $response = $this->dispatch($request, $respond, $rawBody, $failure);
            $response = $errorPage ? $this->withErrorPage($request, $response, $respond, $failure) : $response;
            // Whatever answered -- an action, an endpoint, a page, a
            // handoff, an error page -- if it moved who the browser is.
            $response = $this->revokeLiveIfRebound($request, $response);
            // The toolbar draws into the document while the session is still
            // known, before the reset; telemetry ends after it, so what it
            // counts, logs and stamps with X-Request-Id is the response that
            // leaves, toolbar and all.
            $response = $this->withToolbar($request, $response);
            $response = $this->telemetry?->end($response, $failure) ?? $response;
            $ended = true;

            return $response;
        } finally {
            // A failure that escaped -- throw_on_error's, say -- is still a
            // request that was answered, with a 500 by whoever caught it.
            if (!$ended) {
                $this->telemetry?->end(null, $failure);
            }
            $this->toolbar?->end();
            $this->scope->reset();
        }
    }

    /**
     * The response, its request recorded for the dev toolbar and the toolbar
     * drawn into it when it is a document -- before the scope resets, while
     * the session and the user it belongs to are known. A toolbar that fails
     * is reported and the response goes out as it was: it is a window onto
     * the request, not part of it.
     */
    private function withToolbar(Request $request, Response $response): Response
    {
        if ($this->toolbar === null) {
            return $response;
        }

        try {
            return $this->toolbar->finish($request, $response, $this->auth);
        } catch (\Throwable $e) {
            $this->report($e);

            return $response;
        }
    }

    /**
     * The work a request leaves for after its response has gone: telemetry
     * to its store and its exporter. A worker calls it through its
     * application's Http\Terminable; a front controller after sending.
     */
    public function terminate(): void
    {
        $this->telemetry?->terminate();
    }

    /**
     * Whether $request is for /_health, /_ready or /_metrics, or Caddy's
     * /_tls/ask -- for an application to leave its own per-visitor work,
     * such as issuing a session, out of a probe's request.
     */
    public function answersProbe(Request $request): bool
    {
        return ($this->probes?->matches($request) ?? false) || ($this->tlsAsk?->matches($request) ?? false);
    }

    /**
     * Whether $request opens no session here: a probe (answersProbe()), or
     * a call to a #[WithoutSession] endpoint -- for an application to issue
     * no session cookie, write no session row and set nothing per visitor
     * on its answer (ADR 0075).
     *
     * The Kernel already runs such an endpoint with no global middleware and
     * every cookie taken off its request, so no session reaches it. What it
     * cannot reach is the application's own work outside the pipeline: the
     * cookie a front controller issues to every request without one, which
     * a server calling with a token would be handed with every answer and a
     * session table would keep for it. Matched as dispatch matches it --
     * path and method -- so a call this says opens none is the call the
     * endpoint answers.
     */
    public function opensNoSession(Request $request): bool
    {
        if ($this->answersProbe($request)) {
            return true;
        }

        $matched = $this->endpoints_?->registry->match($request);

        return isset($matched['endpoint']) && $matched['endpoint']->sessionless;
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
            // The probes, first: a probe every few seconds is not a visitor,
            // and pays for no session, no stack and no throttle (ADR 0041).
            // Their paths and the toolbar's are disjoint, so the order costs
            // neither anything.
            if ($this->probes !== null && $this->probes->matches($request)) {
                $this->telemetry?->route(Probes::route($request));

                return $this->probes->handle($request);
            }

            // Caddy asking whether it may obtain or renew a certificate: an
            // internal caller as a probe is, answered as one. Counted by the
            // endpoint, never by the domain asked about.
            if ($this->tlsAsk !== null && $this->tlsAsk->matches($request)) {
                $this->telemetry?->route(TlsAskEndpoint::ROUTE);

                return $this->tlsAsk->handle($request);
            }

            // The dev toolbar's endpoint: its prefix is its own. Not from
            // loopback it is not here at all, and the global stack is not run
            // for it; from loopback it runs behind that stack, which opens the
            // session and signs in the user its token is bound to.
            if ($this->toolbar !== null && $this->toolbar->matches($request)) {
                $this->telemetry?->route('_dev_toolbar');

                return Loopback::request($request)
                    ? $this->globalStack->run($request, fn (Request $passed): Response => $this->toolbar->respond($passed, $this->auth))
                    : throw new NotFoundException();
            }

            if ($this->webhooks !== null && $this->webhooks->matches($request)) {
                $this->telemetry?->route('_webhook');

                return $this->webhooks->handle($request, $rawBody);
            }

            if ($this->unsubscribe !== null && $this->unsubscribe->matches($request)) {
                $this->telemetry?->route('_unsubscribe');

                return $this->unsubscribe->handle($request, $rawBody);
            }

            if ($this->handoff !== null && $this->handoff->matches($request)) {
                $this->telemetry?->route('_handoff');
                HandoffEndpoint::assertMethod($request);

                // A client navigation reaches a handoff when a guard sends it
                // to one; the step answers with a redirect to another host,
                // which the router can follow only as a document load.
                return TranslateRedirects::translate(
                    $request,
                    $this->handoffStack->run($request, fn (Request $passed): Response => $this->handoffStep($passed)),
                );
            }

            // A page's own reports about itself. Their paths are their own;
            // the method is checked before the stack runs, as an action's is.
            if ($this->clientTelemetry_ !== null && $this->clientTelemetry_->matches($request)) {
                $this->telemetry?->route(TelemetryEndpoint::endpoint($request));
                TelemetryEndpoint::assertMethod($request);

                return $this->telemetryStack->run(
                    $request,
                    fn (Request $passed): Response => $this->clientTelemetry_->handle($passed, $rawBody),
                );
            }

            // Each endpoint's method is checked before its stack runs: a
            // GET to an action that needs a user was answered 401 by the
            // stack's authentication, where the answer is 405 and the method
            // that would do.
            // Endpoints are counted by endpoint, never by the name in the
            // path: an action or query name a client made up is not a label.
            if ($this->actions_->matches($request)) {
                $this->telemetry?->route('_action');
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
                    // made it may not be the person it is shown to. And in
                    // the flash cookie, never the store: such a post may be
                    // anyone's, and each one kept was a write on the
                    // application's database anybody could ask for.
                    if ($e->status === 419 && ActionEndpoint::isFormNavigation($request)) {
                        $action = substr($request->path, \strlen(ActionEndpoint::PREFIX));

                        return $this->actions_->expired($request, $this->actions->has($action) ? $action : null);
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
                $this->telemetry?->route('_bootstrap');
                BootstrapEndpoint::assertMethod($request);

                return $this->bootstrapStack->run(
                    $request,
                    fn (Request $passed): Response => $this->bootstrap_->handle($passed, $rawBody),
                );
            }

            if ($this->live_ !== null && $this->live_->matches($request)) {
                $this->telemetry?->route('_live');
                SubscribeEndpoint::assertMethod($request);

                return $this->liveStack->run(
                    $request,
                    fn (Request $passed): Response => $this->live_->handle($passed, $rawBody),
                );
            }

            // With no hub -- under php -S -- nothing is heard, and a page's
            // subscription is told so rather than met with a 404 it would
            // report: a 204, no token, for any name, having run nothing.
            if ($this->channelsUnheard && str_starts_with($request->path, ChannelEndpoint::PREFIX)) {
                $this->telemetry?->route('_channel');
                ChannelEndpoint::assertMethod($request);

                return Response::make(204, '', ['cache-control' => 'no-store']);
            }

            // By the channel's name in the label, never its parameters: the
            // body carries those, and no log line does.
            $channels = $this->channels_;
            if ($channels !== null && $channels->matches($request)) {
                $this->telemetry?->route('_channel');
                ChannelEndpoint::assertMethod($request);

                return $this->channelStack->run(
                    $request,
                    fn (Request $passed): Response => $channels->handle($passed, $rawBody),
                );
            }

            if ($this->uploads_ !== null && $this->uploads_->matches($request)) {
                $this->telemetry?->route('_upload');
                UploadEndpoint::assertMethod($request);

                return $this->uploadStack->run(
                    $request,
                    fn (Request $passed): Response => $this->uploads_->handle($passed),
                );
            }

            // A file's token is in its path, which the access line and the
            // span write redacted, and the label never holds.
            if ($this->files_ !== null && $this->files_->matches($request)) {
                $this->telemetry?->route('_file');
                FileEndpoint::assertMethod($request);

                return $this->fileStack->run(
                    $request,
                    fn (Request $passed): Response => $this->files_->handle($passed),
                );
            }

            // Counted by endpoint, never by the name in the path, as an
            // action's are; a download's token is in its path, which the
            // access line and the span write redacted.
            if ($this->transfers_ !== null && $this->transfers_->matches($request)) {
                $this->telemetry?->route('_transfer');
                TransferEndpoint::assertMethod($request);

                return $this->transferStack->run(
                    $request,
                    fn (Request $passed): Response => $this->transfers_->handle($passed, $rawBody),
                );
            }

            if ($this->downloads_ !== null && $this->downloads_->matches($request)) {
                $this->telemetry?->route('_download');
                DownloadEndpoint::assertMethod($request);

                return $this->fileStack->run(
                    $request,
                    fn (Request $passed): Response => $this->downloads_->handle($passed),
                );
            }

            if ($this->fragments_ !== null && $this->fragments_->matches($request)) {
                $this->telemetry?->route('_fragment');
                FragmentEndpoint::assertMethod($request);

                return $this->fragments_->handle($request);
            }

            if ($this->queries_->matches($request)) {
                $this->telemetry?->route('_query');
                QueryEndpoint::assertMethod($request);

                return $this->queryStack->run(
                    $request,
                    fn (Request $passed): Response => $this->queries_->handle($passed, $rawBody),
                );
            }

            // The application's endpoints, after the framework's -- whose
            // paths are all under /_, where no endpoint may be -- and before
            // the pages, whose paths no endpoint's may overlap. The method is
            // checked before the stack runs, as an action's is: a DELETE to
            // a GET is a 405 naming what would do, not a 401 or a 419 first.
            $handler = $this->endpoints_;
            $matched = $handler?->registry->match($request);
            if ($handler !== null && $matched !== null) {
                if (!isset($matched['endpoint'])) {
                    $this->telemetry?->route('_endpoint');

                    // A CORS preflight, answered only for an origin an
                    // endpoint here names, asking for what it allows (ADR
                    // 0074); every other OPTIONS is the 405 it always was.
                    // Behind the flood guard alone: it opens no session and
                    // runs nothing of the endpoint's.
                    if ($request->method === 'OPTIONS') {
                        $preflight = $this->preflightStack->run(
                            $request,
                            static fn (Request $passed): Response => $handler->preflight($passed)
                                ?? throw EndpointHandler::notAllowed($matched['allow']),
                        );

                        return $preflight;
                    }

                    throw EndpointHandler::notAllowed($matched['allow']);
                }
                $endpoint = $matched['endpoint'];
                // By its name, never its path: a path carries ids.
                $this->telemetry?->route($endpoint->name);

                try {
                    $response = $this->runEndpoint($handler, $endpoint, $matched['parameters'], $request, $rawBody);
                } catch (\Throwable $e) {
                    // Classified here rather than below, so a refusal -- a
                    // 401, a 403, a 429 -- carries what the endpoint's #[Cors]
                    // tells the origin, and the page that called can read why.
                    $failure = $e;
                    $this->renderFailed($e, $request);
                    $response = $this->exceptions->handle($e, $request);
                }

                return EndpointHandler::crossOrigin($request, $endpoint, $response);
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
                $passed = $this->withHeldShared($passed, $token);
                $this->toolbar?->loaded($loaded);
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
        // Dev listens on loopback, and a page that points a name of its own
        // at 127.0.0.1 -- DNS rebinding -- reaches it same-origin from its
        // visitor's machine: the raw failure goes only to a request naming
        // this machine, or naming nothing, as a client on it may.
        $host = $request->header('host');
        $overlaid = $this->dev && $failure !== null && DevOverlay::shows($failure) && ($host === null || Loopback::name($host));
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
     * Tells the listeners -- the closures, then the event bus -- when $e is a
     * failed render, and rethrows it under throw_on_error.
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
        $this->toolbar?->failed($event);
        foreach ($this->renderFailed as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $thrown) {
                $this->report($thrown);
            }
        }

        // And the application's bus, whose listeners run as the bus runs
        // them: one that throws stops the dispatch, as it would anywhere,
        // and is reported here -- the visitor is still shown the error page.
        if ($this->events !== null) {
            try {
                $this->events->dispatch($event);
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
     * The build this code is, as a client sends it in `X-Version`; null when
     * the application declared no builds. A test's client navigation sends
     * it as the router does, read from the page it holds.
     */
    public function build(): ?string
    {
        return $this->builds?->current;
    }

    /**
     * One matched endpoint, through its stack (ADR 0067).
     *
     * A #[WithoutSession] endpoint takes no CSRF token because no cookie
     * signs anyone in for it, and that is held here rather than assumed
     * (ADR 0074). Its request reaches its stack with every cookie taken
     * off -- none in the request, no Cookie header, no previous URL read
     * from the session -- so nothing it runs, its own middleware included,
     * can sign anyone in from one. And someone signed in already, before
     * its stack ran, was signed in by something outside every stack -- by
     * the application, from the cookie -- so the request is refused rather
     * than run with the token skipped for a caller a cookie authenticated.
     *
     * @param array<string, string> $parameters
     */
    private function runEndpoint(EndpointHandler $handler, CompiledEndpoint $endpoint, array $parameters, Request $request, string $rawBody): Response
    {
        if ($endpoint->sessionless) {
            if ($this->auth->check()) {
                throw new \LogicException(
                    "{$endpoint->where()} is #[WithoutSession], and someone was signed in before its stack ran. "
                    . 'Sign in from the session in global middleware only, so no cookie reaches it.',
                );
            }
            $request = $request->withoutCookies();
        }

        return ($endpoint->sessionless ? $this->sessionlessStack : $this->endpointStack)->run(
            $request->withAttribute(EndpointHandler::MATCHED, $endpoint),
            fn (Request $passed): Response => $handler->handle($passed, $endpoint, $parameters, $rawBody),
        );
    }

    /**
     * The shared data one page render may read, for the application's
     * responder to hand its render channel beside the frame; null when the
     * application shares none.
     *
     * Called inside the request, where the router calls the responder:
     * behind the page's tenant and panel stack. The keys are those of no
     * panel and those of the panel the request is inside now -- the one
     * whose stack ran, not merely the one the page declares -- so an error
     * page drawn after a panel's stack refused its request reads none of
     * that panel's. Made per render and never kept: what it produces dies
     * with it (ADR 0060).
     */
    public function shared(LoadedRoute $loaded, Request $request): ?SharedReads
    {
        return $this->sharedFor($loaded->chain->page, $loaded->parameters, $request);
    }

    /**
     * The shared data one fragment render may read: a <Deferred>
     * component's `shared()` (ADR 0065). The fragment endpoint asks for it
     * once the token's route has run its tenant, its panel's stack, its own
     * middleware and every #[Authorize] in its chain, and the token has
     * been held to the panel, tenant, user and auth epoch the request is in
     * -- where a page render's responder asks. So the keys are those of no
     * panel and of the panel the fragment renders in, and the reads are
     * bound to that panel, tenant and user as a page's are.
     *
     * A once key the client proves it holds is read as held, as on a
     * navigation (ADR 0074): the fragment request carries the page's holds
     * in X-Have-Shared, and each is verified here against the same session,
     * user and auth epoch, tenant, panel and build the fragment's own token
     * was just held to. A hold minted for any other is passed over, and its
     * key produced.
     */
    public function sharedForFragment(RouteChain $chain, Request $request): ?SharedReads
    {
        return $this->sharedFor($chain->page, $chain->parameters, $request->withAttribute(self::HELD_SHARED, true));
    }

    /** @param array<string, string> $parameters */
    private function sharedFor(CompiledRoute $page, array $parameters, Request $request): ?SharedReads
    {
        if ($this->shared === null) {
            return null;
        }

        $panel = $this->panels?->currentOrNull();
        $keys = [];
        foreach ($this->sharedPanels as $key => $placed) {
            if ($placed === null || $placed === $panel) {
                $keys[$key] = true;
            }
        }

        // The once keys this render reads that the navigation's holds
        // prove the browser keeps -- each verified now, against the very
        // session, user and auth epoch, tenant, panel and build the reads
        // below are bound to.
        $hold = $this->sharedHold($request);
        $held = $hold !== null && $request->attribute(self::HELD_SHARED) === true
            ? $hold->held($request->header(SharedHold::HEADER), SharedReads::onceAmong($this->shared, $keys))
            : [];

        return new SharedReads(
            registry: $this->shared,
            keys: $keys,
            resolver: $this->resolver,
            request: $request,
            page: $page,
            parameters: $parameters,
            orm: $this->orm,
            auth: $this->auth,
            panels: $this->panels,
            panel: $panel,
            served: $this->sharedProviders,
            held: $held,
            hold: $hold === null ? null : $hold->mint(...),
        );
    }

    /**
     * What once-shared values are held by for this request, as it stands:
     * its session, user and auth epoch, tenant, panel and build. Null
     * without a session or a route signer -- there is nothing to bind a
     * hold to, so a once value is sent to be shown, never kept.
     */
    private function sharedHold(Request $request): ?SharedHold
    {
        $support = $this->support;
        if ($support === null || $support->routeSigner === null) {
            return null;
        }
        $session = ($support->sessionId)($request);
        if ($session === '') {
            return null;
        }

        return new SharedHold(
            signer: $support->routeSigner,
            panel: $this->panels?->currentOrNull() ?? '',
            tenant: TenantClaim::current($this->orm),
            build: $support->builds->current,
            sessionId: $session,
            user: $this->auth->id(),
            authEpoch: $this->auth->epoch(),
            stamp: $this->tagStamps(),
        );
    }

    /**
     * How a hold is stamped with its key's tags for this render (ADR 0074),
     * each key read from the store once however many times it is minted or
     * verified; null when the Kernel keeps no tag versions. A key the
     * registry does not share once has no stamp, and no hold verifies for it.
     *
     * @return (\Closure(string): ?string)|null
     */
    private function tagStamps(): ?\Closure
    {
        $tags = $this->sharedTags;
        $shared = $this->shared;
        if ($tags === null || $shared === null) {
            return null;
        }

        $tenant = $this->orm->tenant();
        $stamps = [];

        return static function (string $key) use ($tags, $shared, $tenant, &$stamps): ?string {
            if (!\array_key_exists($key, $stamps)) {
                $once = $shared->has($key) ? $shared->onceOf($key) : null;
                $stamps[$key] = $once === null ? null : $tags->stamp($once['tags'], $tenant);
            }

            return $stamps[$key];
        };
    }

    /**
     * The request, marked as one whose once-shared holds may be believed
     * (HELD_SHARED): a payload request for a page handed a route token, as
     * #[Once] fields are, so a session is there to bind them to. Marked
     * here and not trusted from anywhere else, since only the navigation
     * sets it; shared() checks every hold, and one that fails any check
     * proves nothing (SharedHold::held()).
     */
    private function withHeldShared(Request $request, ?string $token): Request
    {
        if ($this->shared === null || $token === null || !$request->wantsRscPayload()) {
            return $request;
        }

        return $request->withAttribute(self::HELD_SHARED, true);
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
            tenant: TenantClaim::current($this->orm),
            build: $this->builds->current ?? 'dev',
            sessionId: $session,
            user: $this->auth->id(),
            authEpoch: $this->auth->epoch(),
            loaders: $loaders,
        );
    }

    /**
     * The response, taking away the hub's grant when the request moved who
     * the browser is, whatever answered it: another user or auth epoch than
     * the stack first signed in (AuthContext::rebound()), or a session
     * cookie naming another session. Asked once, around every dispatch,
     * rather than on each path that runs application code: an endpoint, a
     * handoff, a test sign-in signs people in and out as well as an action. The hub cannot be told to
     * forget a grant it gave -- it checks a signature and an expiry -- but
     * the browser can be told to drop it, so no stream it opens after this
     * response is let in on the session it no longer has (ADR 0069). The
     * next trade, under the new binding, is the 401 that purges the rest.
     */
    private function revokeLiveIfRebound(Request $request, Response $response): Response
    {
        if ($this->live === null) {
            return $response;
        }

        if ($this->auth->rebound()) {
            return $this->live->hub->revoke($response);
        }

        // The cookies the response sets, over the ones the request came
        // with: the session the browser holds once it has read this.
        $set = [];
        foreach ($response->cookies() as $name => $line) {
            $pair = explode(';', $line, 2)[0];
            $set[$name] = substr($pair, \strlen($name) + 1);
        }
        if ($set === []) {
            return $response;
        }
        $after = new Request('GET', '/', cookies: [...$request->cookies, ...$set]);

        return ($this->live->sessionId)($after) === ($this->live->sessionId)($request)
            ? $response
            : $this->live->hub->revoke($response);
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
     * One handoff step, handed the session's CSRF token as a page is. A
     * consent screen draws its forms with it, and is the one step answered
     * with a page rather than a redirect -- so it alone carries the cookie
     * the token is checked against when one of them is posted.
     */
    private function handoffStep(Request $request): Response
    {
        \assert($this->handoff !== null);

        $token = $this->csrf?->for($request);
        $passed = $token === null ? $request : $request->withAttribute(self::CSRF_TOKEN, $token);
        $response = $this->handoff->handle($passed);

        return $response->status === 200 ? $this->withCsrfCookie($passed, $response) : $response;
    }

    /**
     * The request, carrying what a page's forms need from the server: the
     * session's CSRF token, for the hidden field a form posted without
     * script sends, and the flash such a post left for this page.
     *
     * The flash is read only when the cookie says one is waiting, so a page
     * with nothing flashed asks the store nothing -- and a page expired, the
     * cookie carries itself (Flash::expired()); and only by a document
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

        $cookie = $request->cookie(Flash::COOKIE);
        if ($cookie === null || $request->isPrefetch() || $request->wantsRscPayload()) {
            return [$request, false];
        }

        // A page expired, which the cookie carries itself: shown for an
        // action there is, and read without asking the store anything.
        $expired = Flash::expiredAction($cookie);
        if ($expired !== null) {
            return [$this->actions->has($expired) ? $request->withAttribute(self::FLASH, Flash::expiredForm($expired)) : $request, true];
        }

        if ($this->flashes === null) {
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
            TenantClaim::current($this->orm),
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
            tenant: TenantClaim::current($this->orm),
            build: $this->support->builds->current,
            sessionId: $session,
            user: $this->auth->id(),
            authEpoch: $this->auth->epoch(),
        ))->mint($loaded->chain);
    }
}
