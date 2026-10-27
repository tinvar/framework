<?php

declare(strict_types=1);

namespace Tinvar\Actions;

use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\RequestAttributes;
use Tinvar\Http\Response;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\TagsRead;
use Tinvar\Orm\Orm;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Support\Signer;

/**
 * Re-renders the routes an action said it invalidated.
 *
 * "revalidate('racks/show') targets routes ... The response carries a fresh
 * RSC payload for affected mounted routes, each rendered through its own
 * full pipeline from a signed route token, so the page updates without
 * manual state syncing."
 *
 * The client sends the route tokens it is holding — the pages it currently
 * has mounted — and the action names the routes it invalidated. The
 * intersection is what gets re-rendered. Neither side decides alone: the
 * client cannot ask for a route the action did not invalidate, and the
 * action cannot cause a render of a page this client was never on.
 *
 * "If the pipeline denies, the action result is returned WITHOUT a payload."
 * That clause is the reason this returns outcomes rather than throwing. A
 * revalidation is a courtesy attached to a write that has already happened;
 * a page the session may no longer see must not turn a successful action
 * into a failed request. The client gets the result and no payload, which it
 * reads as "reload that route yourself" — the same thing it would do if the
 * token had expired.
 *
 * With live updates, each re-render also comes back with the live token for
 * what it read (ADR 0048), minted as a page render's is: by the framework,
 * bound to the request as it stands once the action has run -- its tenant,
 * panel, user and auth epoch -- from the tags its loaders and its queries
 * read. A page re-rendered in place used to keep the token it was first
 * drawn with, listening on for what it no longer showed and deaf to what it
 * now did, under a binding the action may have moved.
 */
final class Revalidator
{
    /**
     * @param \Closure(LoadedRoute, Request): (string|Response) $render produces
     *        the payload for one re-rendered route: the payload alone, or a
     *        response whose body is the payload and whose `x-live-token` is
     *        the live token it minted with the request's
     *        RequestAttributes::LIVE_MINTER, as a page render answers
     * @param \Closure(class-string): Middleware $middlewareResolver required,
     *        not optional: null meant "run no middleware", which is silently
     *        the pre-fix behaviour for anyone constructing this directly
     * @param list<class-string> $globalMiddleware already applied by the action
     *        stack this runs inside, so they are not applied twice
     * @param (\Closure(\Throwable): void)|null $log receives a re-render that
     *        failed for a reason that is NOT a denial -- without it, a broken
     *        loader looks exactly like a permission result
     * @param EntryContext|null $entry what each route's declarations put around
     *        it -- its tenant, its panel and the panel's stack -- as navigation
     *        runs it
     * @param TagsRead|null $tags what the loaders read, by segment: the one
     *        $loaders attributes to segments and loaders are handed, emptied
     *        before each re-render so no page's tags reach another's token
     * @param (\Closure(Request, string, array<string, string>, TagsRead|null): ?LiveMinter)|null $liveMinter
     *        the minter for one re-render, bound to the request as it stands
     *        (the Kernel's liveMinter()); none without live updates
     */
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly LoaderRunner $loaders,
        private readonly Signer $signer,
        private readonly Orm $orm,
        private readonly \Closure $render,
        private readonly BuildRegistry $builds,
        private readonly \Closure $middlewareResolver,
        private readonly ?PanelContext $panels = null,
        private readonly array $globalMiddleware = [],
        private readonly ?\Closure $log = null,
        private readonly ?EntryContext $entry = null,
        private readonly ?TagsRead $tags = null,
        private readonly ?\Closure $liveMinter = null,
    ) {}

    /**
     * How many distinct pages one action may re-render.
     *
     * Each is a full GET pipeline -- middleware, authorization, loaders --
     * so this bounds the work a single write can buy. Applied AFTER
     * deduplication, because the thing worth bounding is renders: the cap
     * used to sit on the endpoint's raw token list, so eight copies of one
     * token were eight full pipelines for one payload.
     */
    public const int MAX_ROUTES = 8;

    /**
     * The route names an action's revalidate() targets refer to: each a
     * route name or the view of named pages, as the spec writes it --
     * `revalidate('racks/show')`.
     *
     * Throws on a target that refers to nothing. Checked whether or not the
     * client holds a token, so a typo fails the first time the action runs
     * rather than only when someone happens to have the page open.
     *
     * @param list<string> $targets
     * @return list<string>
     */
    public function routesFor(array $targets): array
    {
        $names = [];
        foreach ($targets as $target) {
            foreach ($this->routes->revalidationTargets($target) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param list<string> $invalidated what the action revalidated: route
     *        names or view paths, see routesFor()
     * @param list<string> $tokens      route tokens the client is holding
     * @param \Closure(Request): string $sessionId
     * @return array<string, string> page key => payload, for those that rendered
     */
    public function rerender(array $invalidated, array $tokens, Request $request, \Closure $sessionId): array
    {
        return $this->rerendered($invalidated, $tokens, $request, $sessionId)['payloads'];
    }

    /**
     * As rerender(), with each re-render's live token beside its payload:
     * for every page whose render answered with one -- null when it read
     * nothing to listen for -- and none for a render that answered with
     * its payload alone, whose client keeps the token it holds.
     *
     * @param list<string> $invalidated
     * @param list<string> $tokens
     * @param \Closure(Request): string $sessionId
     * @return array{payloads: array<string, string>, live: array<string, string|null>}
     */
    public function rerendered(array $invalidated, array $tokens, Request $request, \Closure $sessionId): array
    {
        $wanted = array_flip($this->routesFor($invalidated));

        if ($wanted === [] || $tokens === []) {
            return ['payloads' => [], 'live' => []];
        }

        // Deduplicated BEFORE anything renders, and keyed by route AND
        // params. Both halves were wrong: the cap bounded tokens looked at
        // rather than renders performed, and keying by route name alone
        // meant two tokens for the same route with different params
        // rendered twice and the second silently overwrote the first --
        // handing the client a payload for the wrong row with no way to
        // tell.
        $pages = [];
        foreach ($tokens as $raw) {
            $token = $this->verified($raw, $request, $sessionId);
            if ($token === null) {
                continue;
            }

            $claims = $token->claims;

            // Only what the action named. A client holding ten tokens does
            // not get ten re-renders because one row changed.
            if (!isset($wanted[$claims->route])) {
                continue;
            }

            $pages[self::pageKey($claims->route, $claims->params)] ??= $claims;
        }

        $payloads = [];
        $live = [];
        foreach (\array_slice($pages, 0, self::MAX_ROUTES, preserve_keys: true) as $key => $claims) {
            $rendered = $this->renderOne($claims, $request);
            if ($rendered === null) {
                continue;
            }
            $payloads[$key] = $rendered[0];
            if ($rendered[1]) {
                $live[$key] = $rendered[2];
            }
        }

        return ['payloads' => $payloads, 'live' => $live];
    }

    /**
     * A page: a route and the params it was mounted with.
     *
     * The client matches payloads against the tokens it sent, so the key
     * has to identify the same thing a token does.
     *
     * @param array<string, string> $params
     */
    public static function pageKey(string $route, array $params): string
    {
        ksort($params);

        return $route . '?' . http_build_query($params);
    }

    /**
     * A token this server will act on, or null.
     *
     * Null rather than an exception, throughout: every reason a token is
     * unusable — forged, expired, another session's, another tenant's —
     * leaves the action successful and the payload absent, which is what
     * the spec asks for and what the client already knows how to handle.
     */
    private function verified(string $raw, Request $request, \Closure $sessionId): ?RouteToken
    {
        try {
            $token = RouteToken::decode($raw, $this->signer);
        } catch (TokenError) {
            return null;
        }

        $session = $sessionId($request);
        if ($session === '' || !$token->claims->belongsTo($this->signer, $session)) {
            return null;
        }

        // The tenant is held to the route's own, once its #[TenantFrom] has
        // resolved -- see renderOne(). Compared here, against the action's,
        // a route that names its tenant was refused whenever the action did
        // not name one the same way, and accepted on the action's word when
        // it did.

        // The build. FragmentEndpoint refuses a revoked one because "a
        // revoked build means a full page load, which is the only way to
        // get a browser off code it already has" -- and that reasoning does
        // not stop applying because the token names a route instead of a
        // component. The claim was signed and read by nothing.
        if (!$this->builds->accepts($token->claims->build)) {
            return null;
        }

        $ambient = $this->panels?->currentOrNull();
        if ($ambient !== null && $ambient !== $token->claims->panel) {
            return null;
        }

        return $token;
    }

    /**
     * One page re-rendered: its payload, whether its render answered for live
     * updates, and the live token it answered with.
     *
     * @return array{string, bool, string|null}|null
     */
    private function renderOne(TokenClaims $claims, Request $request): ?array
    {
        $route = $claims->route;
        $params = $claims->params;
        $panel = $claims->panel;

        $run = function () use ($route, $params, $request, $claims): ?array {
            try {
                $chain = $this->routes->chainFor($route, $params);
            } catch (RoutingException) {
                // The route was renamed or removed since the page loaded.
                // Ordinary deploy skew, not a failure of the action.
                return null;
            }

            // "Each re-render runs that route's full GET pipeline:
            // MIDDLEWARE, layout and page #[Authorize], loaders." The
            // middleware was the part missing: FragmentEndpoint runs a
            // route's own stack before its subtree renders, and this ran
            // only the loaders -- so anything a route enforces in
            // middleware rather than in a policy (a panel guard, MFA, a
            // network allowlist, email verification) applied on navigation
            // and not here. Two re-render paths, one of them weaker.
            //
            // The global stack is excluded: the action request is already
            // inside it, and running it twice would double every counter
            // and every side effect it has.
            $routeOnly = array_values(array_diff($chain->middleware(), $this->globalMiddleware));

            // The route's tenant and panel stack ahead of its middleware, as
            // on navigation.
            $pipeline = new Pipeline([
                ...($this->entry?->stackForChain($chain) ?? []),
                ...array_map(
                    fn (string $class): Middleware => ($this->middlewareResolver)($class),
                    $routeOnly,
                ),
            ]);

            // A GET for the ROUTE, not the action's POST.
            //
            // The spec says "the full GET pipeline", and what ran saw
            // method POST, path /_action/..., and the action's body -- so a
            // loader reading the request got action input, and middleware
            // keyed on method or path behaved differently here than on
            // navigation. That asymmetry is the one this was supposed to
            // close. Only the cross-cutting parts of the original carry
            // over: the cookies and headers that identify the caller.
            $asGet = new Request(
                'GET',
                $this->routes->url($route, $params),
                $request->headers(),
                [],
                [],
                $request->cookies,
            );

            try {
                // Response is the pipeline's currency; the payload is
                // carried through it rather than returned from it, because
                // middleware may short-circuit and that must read as "no
                // payload" rather than as an empty one.
                $payload = null;

                $pipeline->run($asGet, function (Request $inner) use ($chain, $claims, &$payload): Response {
                    // The same bindings a fragment is checked against, for
                    // the same reasons: a token is client-held state, and
                    // client-held state is bound to (user, tenant). Against
                    // the tenant the route runs as now -- resolved from this
                    // request, never from the token.
                    if (TenantClaim::current($this->orm) !== $claims->tenant) {
                        return Response::make(200, '');
                    }

                    // This page's tags alone: whatever the action, or the
                    // page re-rendered before this one, read is not this
                    // page's to listen for.
                    $this->tags?->forget();
                    try {
                        $loaded = $this->loaders->run($chain, $inner);

                        // Here, inside the route's stack, so the binding is the
                        // one the page is drawn under: its tenant resolved, its
                        // panel entered, and whoever the action left signed in
                        // at whatever epoch it left them.
                        $minter = $this->liveMinter === null
                            ? null
                            : ($this->liveMinter)($inner, $chain->page->name ?? $claims->route, $chain->parameters, $this->tags);
                        $rendered = ($this->render)($loaded, $minter === null ? $inner : $inner->withAttribute(RequestAttributes::LIVE_MINTER, $minter));
                    } finally {
                        $this->tags?->forget();
                    }

                    if (!$rendered instanceof Response) {
                        $payload = [$rendered, false, null];
                    } elseif ($rendered->status >= 200 && $rendered->status < 300) {
                        $payload = [$rendered->body(), $minter !== null, $minter === null ? null : $rendered->header('x-live-token')];
                    }

                    return Response::make(200, '');
                });

                return $payload;
            } catch (HttpException|RoutingException $e) {
                // A denial, a 404, a refusal from middleware. Expected, and
                // the reason the payload is absent rather than the request
                // failing.
                return null;
            } catch (\Throwable $e) {
                // Anything else is a BUG -- a mistyped loader, a dead
                // database, a broken render closure -- and it used to look
                // identical to a permission result. The payload is still
                // omitted, because the write has already happened, but it
                // is reported rather than swallowed.
                if ($this->log !== null) {
                    ($this->log)($e);
                }

                return null;
            }
        };

        return $panel === '' || $this->panels === null
            ? $run()
            : $this->panels->within($panel, $run);
    }
}
