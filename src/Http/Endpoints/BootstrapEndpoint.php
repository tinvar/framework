<?php

declare(strict_types=1);

namespace Tinvar\Http\Endpoints;

use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentTokenError;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;

/**
 * POST /_bootstrap
 *
 * tinvar-spec.md: "Fragments inside static pages get their tokens from a
 * small per-visitor bootstrap request, never from the build."
 *
 * A statically-built page has no render to mint during and no session at
 * build time, so its deferred boundaries ship without tokens and ask for
 * them on arrival. "Per-visitor" is the whole point: a token minted once at
 * build time would be every visitor's, and the session binding — the thing
 * that makes a token lifted from page source inert — would not exist.
 *
 * What the caller may ask for is the part worth getting right. It names a
 * ROUTE and its params, and nothing else. The component ids and props come
 * from the build manifest, server-side, so a caller editing a copy of the
 * HTML cannot have arbitrary props signed. That is the same shape as the
 * route and per-route query allowlists: the client names something and the
 * server looks it up, rather than trusting the description that came with it.
 *
 * Minting is not authorization and is not treated as any. The tokens this
 * hands back still have to survive the originating route's full #[Authorize]
 * chain when they are used, so bootstrapping a page a caller may not read
 * gets them tokens that will be refused.
 */
final class BootstrapEndpoint
{
    public const string PATH = '/_bootstrap';

    /** Small, as the spec says: this is a page's boundary list, not a payload. */
    public const int MAX_BODY_BYTES = 8192;

    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly FragmentManifest $manifest,
        private readonly FragmentSupport $fragments,
    ) {}

    public function matches(Request $request): bool
    {
        return $request->path === self::PATH;
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        // POST, though it reads nothing: the response is per-session and
        // must never be cached or prefetched, and a GET with a body is not a
        // thing. It is covered by CSRF for the same reason every other POST
        // is, and the spec's own bootstrap is a request, not a link.
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'Bootstrap is POST only.', 'method_not_allowed');
        }

        $body = Limits::input($request, $rawBody, self::MAX_BODY_BYTES);

        $route = $body['route'] ?? null;
        if (!\is_string($route) || $route === '') {
            throw new HttpException(400, 'Bootstrap needs the route the page was built for.', 'bad_request');
        }

        $params = $body['params'] ?? [];
        if (!\is_array($params)) {
            throw new HttpException(400, 'Bootstrap params must be an object.', 'bad_request');
        }

        // Validated, not coerced. FragmentToken::params() carries a comment
        // explaining why strval was wrong here -- true becomes "1", null
        // becomes "", a nested array becomes the literal "Array" plus a
        // warning that any handler promoting warnings turns into a 500,
        // escaping the one-status rule. The fix was applied in one place and
        // not the other.
        foreach ($params as $name => $value) {
            if (!\is_string($value) && !\is_int($value)) {
                throw new HttpException(400, 'Bootstrap params must be scalars.', 'bad_request');
            }
            $params[(string) $name] = (string) $value;
        }

        /** @var array<string, string> $params */

        // The page, not the route: a statically-built page is a concrete URL,
        // and the props the build recorded belong to THAT page. Looking up by
        // route alone let a caller pair any params with those props, minting
        // a token for a combination that was never rendered -- and the
        // fragment endpoint authorizes the route chain against the params,
        // not the props, so nothing downstream would have caught it.
        $boundaries = $this->manifest->boundariesFor($route, $params);
        if ($boundaries === []) {
            // Empty rather than 404: a page with no deferred boundaries and a
            // route that was never built statically are the same answer here,
            // and telling them apart would say which routes exist.
            return self::json(['tokens' => []]);
        }

        // The route has to exist and its params have to fit, because the
        // token binds them and the fragment endpoint will rebuild this chain
        // from them later. A mismatch is the caller's error, not a 500.
        // The SAME answer as an unbuilt page. Distinguishing "in the
        // manifest but the params do not form" from "not in the manifest"
        // confirmed which pages were built statically -- reintroducing, in
        // the caller, the oracle FragmentManifest::boundariesFor() returns
        // empty specifically to avoid.
        try {
            $this->routes->chainFor($route, $params);
        } catch (RoutingException) {
            return self::json(['tokens' => []]);
        }

        // A visitor arriving at a statically-served page may have no
        // session yet, and that is the ordinary case rather than an error --
        // but a token with no session to bind to is one every visitor would
        // share, so it cannot be minted. FragmentEndpoint refuses the same
        // condition cleanly; this one let the exception out as a 500,
        // remotely triggerable without authentication.
        try {
            $minter = $this->fragments->minterFor($request, $route, $params);
        } catch (\InvalidArgumentException) {
            throw new HttpException(
                409,
                'A session is required before fragment tokens can be minted.',
                'no_session',
            );
        }

        $tokens = [];
        foreach ($boundaries as $boundary) {
            try {
                $tokens[$boundary['id']] = $minter->mint($boundary['component'], $boundary['props']);
            } catch (FragmentTokenError $e) {
                // A boundary the build recorded but this server will not sign
                // is a build problem, not a request problem. The others are
                // still useful, so this one is omitted rather than failing
                // the page's whole bootstrap.
                continue;
            }
        }

        return self::json(['tokens' => $tokens]);
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload): Response
    {
        return Response::make(
            200,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            [
                'content-type' => 'application/json',
                // Per visitor, per session. A shared cache holding one
                // visitor's tokens and handing them to the next would undo
                // the session binding this endpoint exists to provide.
                'cache-control' => 'private, no-store',
                'vary' => 'Cookie',
            ],
        );
    }
}
