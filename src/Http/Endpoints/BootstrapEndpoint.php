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

        $body = $rawBody === '' ? $request->body : Limits::decodeJson($rawBody, self::MAX_BODY_BYTES);

        $route = $body['route'] ?? null;
        if (!\is_string($route) || $route === '') {
            throw new HttpException(400, 'Bootstrap needs the route the page was built for.', 'bad_request');
        }

        $params = $body['params'] ?? [];
        if (!\is_array($params)) {
            throw new HttpException(400, 'Bootstrap params must be an object.', 'bad_request');
        }

        $boundaries = $this->manifest->boundariesFor($route);
        if ($boundaries === []) {
            // Empty rather than 404: a page with no deferred boundaries and a
            // route that was never built statically are the same answer here,
            // and telling them apart would say which routes exist.
            return self::json(['tokens' => []]);
        }

        // The route has to exist and its params have to fit, because the
        // token binds them and the fragment endpoint will rebuild this chain
        // from them later. A mismatch is the caller's error, not a 500.
        try {
            $this->routes->chainFor($route, array_map(strval(...), $params));
        } catch (RoutingException) {
            throw new HttpException(400, 'That route and those params do not go together.', 'bad_request');
        }

        $minter = $this->fragments->minterFor($request, $route, array_map(strval(...), $params));

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
