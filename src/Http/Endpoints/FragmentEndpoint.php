<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Http\BuildRegistry;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Http\Response;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Auth\AuthContext;
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
 *
 * GET /_fragment/{token}/{token}/... is one <Deferred group>: the boundaries
 * of one page that load together (ADR 0018). Each token is verified as a
 * lone one is, and they must all name the same page -- route, params, panel,
 * tenant, build -- so the route's chain that runs once is the chain every
 * one of them would have run. One that does not is the same 404 as any
 * other refusal, for the whole request.
 */
final class FragmentEndpoint
{
    public const string PREFIX = '/_fragment/';

    /** Tokens are long, but not unbounded: this is a path segment. */
    public const int MAX_TOKEN_BYTES = 4096;

    /**
     * How many boundaries one group request may carry. A group is charged a
     * hit per token, so this bounds the work one request can ask for and the
     * length of its path, not the budget.
     */
    public const int MAX_GROUP = 16;

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
     * @param (\Closure(list<FragmentToken>, Request, array<string, mixed>, list<string>): Response)|null $renderGroup
     *        renders a group's subtrees in one render; see FragmentSupport
     * @param EntryContext|null $entry what the originating route's declarations put
     *        around it -- its tenant, its panel and the panel's stack -- as a
     *        page render runs it
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
        private readonly ?\Closure $renderGroup = null,
        private readonly ?AuthContext $auth = null,
        private readonly ?EntryContext $entry = null,
    ) {}

    /**
     * How many tokens a request carries, for the throttle to charge -- read
     * from the path alone, before anything is verified, since the throttle
     * runs first. Capped where verification would refuse the rest.
     */
    public static function tokenCount(Request $request): int
    {
        if (!str_starts_with($request->path, self::PREFIX)) {
            return 1;
        }

        return min(self::MAX_GROUP, substr_count($request->path, '/', \strlen(self::PREFIX)) + 1);
    }

    /**
     * Refuses any method but GET, saying which would do; see
     * ActionEndpoint::assertMethod().
     *
     * A fragment is a read. Anything else would be an action wearing a
     * fragment's clothes, and actions are POST with CSRF.
     */
    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'GET') {
            throw new HttpException(405, 'Fragments are GET only.', 'method_not_allowed', ['allow' => 'GET']);
        }
    }

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
            self::assertMethod($passed);

            // A group's tokens agree on everything below, so the first
            // stands for all of them; see verify().
            $tokens = $this->verify($passed);
            $token = $tokens[0];

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

            // And held to the panel the route itself is in, before that
            // panel's stack runs: a token naming another panel, or none, was
            // minted for a page this route is not.
            $routePanel = $this->entry?->panelForChain($chain);
            if ($routePanel !== null && $routePanel->name !== $token->panel) {
                throw self::refuse('token was minted outside the panel its route is in');
            }

            $run = fn (): Response => $this->throughRoute($chain, $passed, $tokens);

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
     *
     * @param non-empty-list<FragmentToken> $tokens one, or a group that agrees on its page
     */
    private function throughRoute(RouteChain $chain, Request $passed, array $tokens): Response
    {
        $token = $tokens[0];
        $routeOnly = array_values(array_diff($chain->middleware(), $this->globalMiddleware));
        $pipeline = new Pipeline([
            // Inner middleware first: the per-user throttle belongs here,
            // after the global stack has established a session and before
            // the route's own work. Fragments had only the address pass,
            // and they are the most expensive endpoint to leave without
            // an account ceiling -- one request is a full subtree render.
            ...$this->extraInner,
            // Then the route's tenant, as a page render resolves it -- from
            // the request, never from the token, which the check below holds
            // to it -- and its panel's stack: "the originating route's full
            // middleware" includes the guard, MFA and allowlist in front of it.
            ...($this->entry?->stackForChain($chain) ?? []),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                $routeOnly,
            ),
        ]);

        return $pipeline->run($passed, function (Request $inner) use ($chain, $token, $tokens): Response {
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

            // And for whoever is signed in, at the auth epoch the application
            // keeps for them, as a route token is bound -- once the route's
            // middleware has said who that is. A role changed, or an
            // impersonation started, with the session id kept: the page the
            // token came from was drawn for someone else, and the client
            // holding it is to be purged, not served more of it. Required,
            // not merely checked when present: every minter that knows the
            // user writes a binding -- a signed-out visitor's included -- so
            // a token without one was minted where nobody was known, and is
            // not one to serve to whoever is signed in now.
            if ($this->auth !== null
                && !hash_equals($token->authHash, TokenClaims::hashAuth($this->signer, $this->auth->id(), $this->auth->epoch()))) {
                throw self::refuse('token was minted for another user, or before their auth epoch moved');
            }

            return $this->renderWithin($chain, $inner, $tokens);
        });
    }

    /**
     * The subtree itself -- or a group's subtrees -- once the panel is
     * established.
     *
     * @param non-empty-list<FragmentToken> $tokens
     */
    private function renderWithin(RouteChain $chain, Request $inner, array $tokens): Response
    {
        $token = $tokens[0];

        // Model binding and every #[Authorize] in the chain, before the
        // subtree renders anything. Once for a group: its tokens name one
        // page, so this is the chain each would have run alone.
        $models = $this->loaders->bindAndAuthorize($chain, $inner);

        // "PHP rejects any other name on that route's socket, so a
        // compromised dependency can't call queries the page never uses."
        // Derived here, from the route the token names.
        $allowed = $this->allowedQueries === null ? [] : ($this->allowedQueries)($token->route);

        if (\count($tokens) === 1) {
            $response = ($this->render)($token, $inner, $models, $allowed);
        } elseif ($this->renderGroup !== null) {
            $response = ($this->renderGroup)($tokens, $inner, $models, $allowed);
        } else {
            // Not a refusal: the tokens are good and the page has a group on
            // it. The application has not said how to render one.
            throw new \LogicException(
                'A <Deferred group> was requested, and this application\'s FragmentSupport has no renderGroup.',
            );
        }

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
        $refreshed = array_map(static fn (FragmentToken $t): ?FragmentToken => $t->refreshed(), $tokens);

        // A group's in token order, comma-separated. All or none: a group's
        // tokens were minted together and expire together, and a partial
        // list would leave the client pairing tokens with the wrong
        // boundaries.
        if (\in_array(null, $refreshed, true)) {
            return $response;
        }

        return $response->withHeader('x-fragment-token', implode(',', array_map(
            fn (?FragmentToken $t): string => $t === null ? '' : $t->encode($this->signer),
            $refreshed,
        )));
    }

    /**
     * Everything the tokens have to satisfy before any of them is acted on.
     *
     * @return non-empty-list<FragmentToken>
     */
    private function verify(Request $request): array
    {
        $raw = substr($request->path, \strlen(self::PREFIX));
        $parts = explode('/', $raw);

        if ($raw === '' || \count($parts) > self::MAX_GROUP) {
            throw self::refuse('token missing, or more of them than one group may carry');
        }

        // The same token twice is the same subtree twice: work asked for,
        // and nothing a page sends.
        if (\count(array_unique($parts)) !== \count($parts)) {
            throw self::refuse('a token repeated within one group');
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

        $tokens = [];
        foreach ($parts as $part) {
            $tokens[] = $this->verifyOne($part, $session);
        }

        // One page's boundaries: everything that decides who may use a token
        // is the same for all of them, so the chain run once for the first
        // is the chain each of the others would have run alone. Tokens from
        // two pages -- even two the caller may see -- are not a group.
        $first = $tokens[0];
        foreach ($tokens as $token) {
            if ($token->route !== $first->route
                || $token->params !== $first->params
                || $token->panel !== $first->panel
                || $token->tenant !== $first->tenant
                || $token->build !== $first->build
                // The user binding too: only the first is checked against
                // who is signed in, so a member minted for someone else
                // rode along with it.
                || !hash_equals($first->authHash, $token->authHash)) {
                throw self::refuse('tokens in one group were minted for different pages');
            }
        }

        return $tokens;
    }

    /** One token, verified exactly as a lone one always was. */
    private function verifyOne(string $raw, #[\SensitiveParameter] string $session): FragmentToken
    {
        if ($raw === '' || \strlen($raw) > self::MAX_TOKEN_BYTES) {
            throw self::refuse('token missing or over the size cap');
        }

        try {
            // Signature first, then expiry: see FragmentToken::decode.
            $token = FragmentToken::decode($raw, $this->signer);
        } catch (FragmentTokenError $e) {
            throw self::refuse($e->getMessage());
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
