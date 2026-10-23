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

    /**
     * @param \Closure(FragmentToken, Request, array<string, mixed>): Response $render
     *        opens a render channel for the subtree and returns its response
     * @param \Closure(Request): string $sessionId
     * @param \Closure(class-string): Middleware $middlewareResolver
     * @param list<class-string> $globalMiddleware
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
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    public function handle(Request $request): Response
    {
        // A fragment is a read. Anything else would be an action wearing a
        // fragment's clothes, and actions are POST with CSRF.
        if ($request->method !== 'GET') {
            throw new HttpException(405, 'Fragments are GET only.', 'method_not_allowed');
        }

        $token = $this->verify($request);

        $chain = $this->routes->match(
            $this->routes->named($token->route)->url($token->params)
        ) ?? throw self::refuse('the originating route no longer matches');

        // The route's own middleware, under the global stack, exactly as a
        // page render would run them -- a fragment that skipped them would be
        // a hole in whatever they enforce for that route.
        $pipeline = new Pipeline(array_map(
            fn (string $class): Middleware => ($this->middlewareResolver)($class),
            array_values(array_unique([
                ...$this->globalMiddleware,
                ...array_diff($chain->middleware(), $this->globalMiddleware),
            ])),
        ));

        return $pipeline->run($request, function (Request $inner) use ($chain, $token): Response {
            // Model binding and every #[Authorize] in the chain, before the
            // subtree renders anything.
            $models = $this->loaders->bindAndAuthorize($chain, $inner);

            $response = ($this->render)($token, $inner, $models);

            // "sent private, no-store with a refreshed token", so a refresh
            // boundary outlives the first hour without a page load.
            return $response
                ->withHeader('cache-control', 'private, no-store')
                ->withHeader('x-fragment-token', $token->refreshed()->encode($this->signer))
                ->withAddedHeader('vary', 'RSC');
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
        if (!$token->belongsTo($this->signer, ($this->sessionId)($request))) {
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
