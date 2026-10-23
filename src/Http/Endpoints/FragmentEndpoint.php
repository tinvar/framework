<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Http\BuildRegistry;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Routing\LoaderRunner;
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
     * @param \Closure(FragmentToken, Request, array<string, mixed>): Response $render
     *        opens a render channel for the subtree and returns its response
     * @param \Closure(Request): string $sessionId
     * @param \Closure(class-string): Middleware $middlewareResolver
     * @param list<class-string> $globalMiddleware
     * @param list<Middleware> $extraGlobal already-built middleware to run outermost
     */
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly LoaderRunner $loaders,
        private readonly Signer $signer,
        private readonly BuildRegistry $builds,
        private readonly \Closure $render,
        private readonly \Closure $sessionId,
        private readonly \Closure $middlewareResolver,
        private readonly array $globalMiddleware = [],
        private readonly array $extraGlobal = [],
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
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_unique($this->globalMiddleware)),
            ),
            // Already-constructed middleware the mounting kernel adds --
            // throttle:fragments, which needs a store the endpoint has no
            // business knowing about.
            ...$this->extraGlobal,
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

            $routeOnly = array_values(array_diff($chain->middleware(), $this->globalMiddleware));
            $pipeline = new Pipeline(array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                $routeOnly,
            ));

            return $pipeline->run($passed, function (Request $inner) use ($chain, $token): Response {
                // Model binding and every #[Authorize] in the chain, before
                // the subtree renders anything.
                $models = $this->loaders->bindAndAuthorize($chain, $inner);

                $response = ($this->render)($token, $inner, $models);

                // A refreshed token only on a response that succeeded. Handing
                // one back with a failure the renderer produced itself would
                // extend the life of a boundary that just failed.
                if ($response->status >= 400) {
                    return $response;
                }

                // "always private, no-store with Vary: Cookie". The body is
                // session-bound -- the token is -- so a shared cache has to be
                // told, not merely asked not to store.
                // The client refuses anything that is not this, so the
                // endpoint has to promise it rather than leave it to whatever
                // the render closure happened to set.
                return $response
                    ->withHeader('content-type', self::CONTENT_TYPE)
                    ->withHeader('cache-control', 'private, no-store')
                    ->withHeader('x-fragment-token', $token->refreshed()->encode($this->signer))
                    ->withAddedHeader('vary', 'RSC')
                    ->withAddedHeader('vary', 'Cookie');
            });
        });
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
