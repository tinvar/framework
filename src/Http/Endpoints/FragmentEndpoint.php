<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Http\BuildRegistry;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Http\Response;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Auth\PanelContext;
use Tinvar\Routing\RouteChain;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Support\Signer;

/**
 * GET /_fragment/{token}
 *
 * tinvar-spec.md, "Deferred components":
 *
 *   "On the trigger, the client requests `GET /_fragment/<token>` with
 *    `RSC: 1`. PHP verifies the token, runs the originating route's full
 *    middleware, layout and page `#[Authorize]` chain, and opens a render
 *    channel for that subtree only."
 *
 * The order is the security property, and it is the same order a page render
 * uses: verify what the request claims, resolve the models it names, then
 * authorize, then do any work. A fragment is part of a page, so reaching it
 * has to mean being allowed to reach that page -- the token says WHICH
 * fragment, never WHETHER.
 *
 * "Props are signed, not trusted for access." A token is readable in page
 * source. Anyone holding one already had the page it came from, so the
 * signature is an anti-tampering device, not a capability: it stops a caller
 * swapping `unit=mine` for `unit=yours`, and the #[Authorize] chain that runs
 * afterwards is what stops them reading someone else's unit.
 *
 * Every rejection is the same rejection. A caller able to tell "expired" from
 * "wrong session" from "wrong build" from "no such route" could use the
 * difference to probe; the reason goes to the log with a digest instead.
 */
final class FragmentEndpoint
{
    public const string PREFIX = '/_fragment/';

    /** Tokens are long, but not unbounded: this is a path segment. */
    public const int MAX_TOKEN_BYTES = 4096;

    /** "The response is RSC payload only." */
    public const string CONTENT_TYPE = 'text/x-component';

    /**
     * @param \Closure(FragmentToken, Request, array<string, mixed>, list<string>): Response $render
     *        opens a render channel for the subtree and returns its response.
     *        The allowlist is the FOURTH argument because the endpoint
     *        computes it from the originating route; a closure that chose its
     *        own would make this a query endpoint without one.
     * @param \Closure(Request): string $sessionId
     * @param \Closure(class-string): Middleware $middlewareResolver
     * @param list<class-string> $globalMiddleware
     * @param list<Middleware> $extraGlobal already-built middleware to run outermost
     * @param list<Middleware> $extraInner  already-built middleware to run AFTER the
     *        application's global stack -- where a per-user throttle has to go,
     *        because the session it reads is established by that stack
     */
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly LoaderRunner $loaders,
        private readonly Orm $orm,
        private readonly Signer $signer,
        private readonly BuildRegistry $builds,
        private readonly \Closure $render,
        private readonly \Closure $sessionId,
        private readonly \Closure $middlewareResolver,
        private readonly array $globalMiddleware = [],
        private readonly array $extraGlobal = [],
        private readonly ?PanelContext $panels = null,
        private readonly array $extraInner = [],
        private readonly ?\Closure $allowedQueries = null,
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    public function handle(Request $request): Response
    {
        // The GLOBAL stack wraps everything, including a refusal.
        //
        // Verification used to run first, which meant a junk token skipped
        // throttling, security headers, request logging and tenant resolution
        // entirely -- an attacker could hammer this endpoint with garbage at
        // full rate for free. Router::handle()'s own docblock argues exactly
        // this ordering and this endpoint was doing the thing it warns about.
        //
        // The route's own middleware necessarily stays inside, because which
        // route it is only becomes known once the token verifies.
        $global = new Pipeline([
            // Already-constructed middleware the mounting kernel adds --
            // throttle:fragments, which needs a store the endpoint has no
            // business knowing about. FIRST, so a refused request does not
            // pay for session start or tenant resolution on the way to being
            // told no.
            ...$this->extraGlobal,
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_unique($this->globalMiddleware)),
            ),
        ]);

        return $global->run($request, function (Request $passed): Response {
            // A fragment is a read. Anything else would be an action wearing
            // a fragment's clothes, and actions are POST with CSRF.
            if ($passed->method !== 'GET') {
                throw new HttpException(405, 'Fragments are GET only.', 'method_not_allowed');
            }

            $token = $this->verify($passed);

            // By NAME, never by rebuilding a URL and matching it back. The
            // round trip is not identity -- /sites/{site} with site="new"
            // matches a static /sites/new if one exists -- so a token issued
            // for one route could run another route's middleware and
            // #[Authorize] chain, possibly an empty one.
            //
            // Every way this can fail is a token this server will not act on,
            // not a bug: a route renamed or removed since the token was issued
            // is the ordinary deploy-skew case this feature exists for, and it
            // takes the same 404 as everything else. Letting RoutingException
            // out would make it a 500, which breaks the one-status rule and
            // tells a caller whether the route still exists.
            try {
                $chain = $this->routes->chainFor($token->route, $token->params);
            } catch (RoutingException $e) {
                throw self::refuse($e->getMessage());
            }

            // The panel the page was rendered in, CHECKED against what the
            // request has established and then ESTABLISHED, before the
            // route's middleware runs -- the order the Revalidator uses.
            //
            // Checked, because within() is an unconditional set: using the
            // token's panel directly replaced whatever the request had
            // established, and a token minted while a session was in a wider
            // panel then re-asserted that panel after the session narrowed,
            // revoked a membership, or switched. A token is client-held
            // state; the spec purges client-held state on a panel switch.
            //
            // Established before the route's middleware, because that
            // middleware is where a panel is guarded -- membership, MFA, a
            // network allowlist. Applied only after it, as this used to be,
            // a guard saw no panel at all and could not refuse the one the
            // fragment then rendered in.
            $ambient = $this->panels?->currentOrNull();
            if ($ambient !== null && $ambient !== $token->panel) {
                throw self::refuse('token was minted in a different panel');
            }

            $run = fn (): Response => $this->throughRoute($chain, $passed, $token);

            // Already in it when the request decided first; a panel-less
            // token runs with no panel at all.
            $panels = $this->panels;
            if ($panels !== null && $token->panel !== '' && $ambient === null) {
                return $panels->within($token->panel, $run);
            }

            return $run();
        });
    }

    /**
     * The route's own middleware, then the subtree -- inside the token's
     * panel, which the caller has already checked and established.
     */
    private function throughRoute(RouteChain $chain, Request $passed, FragmentToken $token): Response
    {
        $routeOnly = array_values(array_diff($chain->middleware(), $this->globalMiddleware));
        $pipeline = new Pipeline([
            // Inner middleware first: the per-user throttle belongs here,
            // after the global stack has established a session and before
            // the route's own work. Fragments had only the address pass,
            // and they are the most expensive endpoint to leave without
            // an account ceiling -- one request is a full subtree render.
            ...$this->extraInner,
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                $routeOnly,
            ),
        ]);

        return $pipeline->run($passed, function (Request $inner) use ($chain, $token): Response {
            // And still the token's panel once the route's middleware
            // has run. Middleware may set a panel of its own -- the
            // route's panel binding, a switch the session made -- and
            // one that disagrees with the token's means the page this
            // token came from is not the page this request would render
            // now. A panel-less token agrees only with no panel.
            if ($this->panels !== null && ($this->panels->currentOrNull() ?? '') !== $token->panel) {
                throw self::refuse('the request is in a different panel than the token was minted in');
            }

            // The same check for the tenant, which the spec names in the
            // same clause. Checked rather than applied: a token must
            // never be able to establish a tenant, only to agree with
            // the one the request is already in.
            //
            // UNCONDITIONAL. The first version skipped the comparison
            // when the live tenant was null or empty -- and the Orm was
            // never passed in, so it was null on every request and the
            // check never ran at all. An application with no tenancy
            // mints '' and matches '', so comparing always is correct
            // there too, and there is no state in which this is skipped.
            if ($this->orm->tenant() !== $token->tenant) {
                throw self::refuse('token was minted for a different tenant');
            }

            return $this->renderWithin($chain, $inner, $token);
        });
    }

    /** The subtree itself, once the panel is established. */
    private function renderWithin(RouteChain $chain, Request $inner, FragmentToken $token): Response
    {
        // Model binding and every #[Authorize] in the chain, before the
        // subtree renders anything.
        $models = $this->loaders->bindAndAuthorize($chain, $inner);

        // "PHP rejects any other name on that route's socket, so a
        // compromised dependency can't call queries the page never uses."
        // Derived here, from the route the token names.
        $allowed = $this->allowedQueries === null ? [] : ($this->allowedQueries)($token->route);

        $response = ($this->render)($token, $inner, $models, $allowed);

        // A refreshed token only on a response that succeeded. Handing one
        // back with a failure the renderer produced itself would extend the
        // life of a boundary that just failed.
        if ($response->status >= 400) {
            return $response;
        }

        // "always private, no-store with Vary: Cookie". The body is
        // session-bound -- the token is -- so a shared cache has to be told,
        // not merely asked not to store. The content type is promised here
        // rather than left to whatever the render closure happened to set,
        // because the client refuses anything else.
        $response = $response
            ->withHeader('content-type', self::CONTENT_TYPE)
            ->withHeader('cache-control', 'private, no-store')
            ->withAddedHeader('vary', 'RSC')
            ->withAddedHeader('vary', 'Cookie');

        // A refresh never extends past the absolute lifetime: near the end
        // its expiry is the deadline itself, after which the token is
        // refused and the client reloads.
        $refreshed = $token->refreshed();

        return $refreshed === null
            ? $response
            : $response->withHeader('x-fragment-token', $refreshed->encode($this->signer));
    }

    /**
     * Everything the token has to satisfy before any of it is acted on.
     */
    private function verify(Request $request): FragmentToken
    {
        $raw = substr($request->path, \strlen(self::PREFIX));

        if ($raw === '' || \strlen($raw) > self::MAX_TOKEN_BYTES) {
            throw self::refuse('token missing or over the size cap');
        }

        try {
            // Signature first, then expiry: see FragmentToken::decode.
            $token = FragmentToken::decode($raw, $this->signer);
        } catch (FragmentTokenError $e) {
            throw self::refuse($e->getMessage());
        }

        // "PHP rejects a token from another session, another build outside the
        // skew window, or past its expiry."
        //
        // An empty session id is refused rather than hashed: hashSession('')
        // is one constant, so every visitor without a session would share one
        // binding and their tokens would be interchangeable.
        $session = ($this->sessionId)($request);
        if ($session === '') {
            throw self::refuse('no session to bind the token to');
        }

        if (!$token->belongsTo($this->signer, $session)) {
            throw self::refuse('token belongs to another session');
        }

        if (!$this->builds->accepts($token->build)) {
            // A revoked or dropped build means a full page load, which is the
            // only way to get a browser off code it already has.
            throw self::refuse('token build is revoked or outside the skew window');
        }

        return $token;
    }

    /**
     * One status, one code, for every reason.
     *
     * 404 rather than 403: a token this server will not act on is, as far as
     * the caller may learn, a fragment that does not exist. 403 would confirm
     * that it does.
     */
    private static function refuse(string $why): HttpException
    {
        return new HttpException(404, "Fragment unavailable: {$why}.", 'fragment_unavailable');
    }
}
