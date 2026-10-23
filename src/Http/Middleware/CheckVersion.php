<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * `X-Version`: detect a client running code from another build.
 *
 * tinvar-spec.md, "Protocol": `X-Version` (client manifest hash) -- "Detect
 * deploys; client-reference IDs break across builds" -- and 409 +
 * `X-Location`: "Full page load: version mismatch on GET". The rules kept
 * as-is: "non-GET requests never get a version 409".
 *
 * Why a mismatch matters rather than being a nuisance: an RSC payload refers
 * to client components by ID, and those IDs are a property of a build. A
 * client on build A that receives a payload rendered by build B gets
 * references to modules it does not have, and fails at hydration in ways
 * that look like application bugs. The only reliable fix is a full page
 * load, which is the one thing that replaces the code a browser is already
 * holding -- so that is what this answers with.
 *
 * This applies to CLIENT navigations only -- requests carrying `RSC: 1`. A
 * full page load is already the remedy, so a document request is never
 * refused for its version.
 *
 * Before this existed BuildRegistry was reachable only from inside a fragment
 * token, so a client on a revoked build kept navigating, calling actions and
 * running queries indefinitely.
 */
final class CheckVersion implements Middleware
{
    public function __construct(private readonly BuildRegistry $builds) {}

    public function handle(Request $request, \Closure $next): Response
    {
        // "Non-GET requests never get a version 409." A POST that was turned
        // away for its version would lose the user's input; it runs, and the
        // NEXT navigation picks up the new build.
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return $next($request);
        }

        // A document load IS the remedy; refusing one would be a loop.
        if ($request->header('rsc') !== '1') {
            return $next($request);
        }

        // A client navigation that cannot say which build it is running is
        // not trusted to be running the current one. Missing is a mismatch,
        // not a pass -- the same direction BuildRegistry::accepts() takes for
        // an unknown or empty build.
        if (!$this->builds->accepts($request->header('x-version'))) {
            return self::reload($request);
        }

        return $next($request);
    }

    /**
     * 409 + X-Location: the client does a full page load of this URL.
     *
     * The location is the request's OWN path and query, never anything the
     * client supplied as a destination, so this cannot become an open
     * redirect. The client may still decline a background reload (a poll, a
     * deferred load) and show a "new version" notice instead -- that choice
     * is the client's, and the server's answer is the same either way.
     */
    private static function reload(Request $request): Response
    {
        $location = $request->path;
        $query = self::queryOf($request);
        if ($query !== '') {
            $location .= '?' . $query;
        }

        return Response::make(409, '', [
            'x-location' => $location,
            'cache-control' => 'private, no-store',
            'vary' => 'RSC',
        ]);
    }

    /**
     * The query string to send the client back to.
     *
     * The one the browser sent, when the adapter kept it. Rebuilding it from
     * the parsed array is lossy: `?tag=a&tag=b` comes back as
     * `?tag%5B0%5D=a&tag%5B1%5D=b`, a space as `+`, a bare `?flag` as
     * `flag=` -- and the full load then asks for a different page than the
     * one the user was on.
     *
     * It goes into a response header, so it is used only if every byte is
     * visible ASCII: a browser percent-encodes everything else, and anything
     * that is not is not a query string a browser sent.
     */
    private static function queryOf(Request $request): string
    {
        $raw = $request->rawQuery;
        if ($raw !== null && preg_match('/^[\x21-\x7e]*$/', $raw) === 1 && !str_contains($raw, '#')) {
            return $raw;
        }

        return $request->query === [] ? '' : http_build_query($request->query);
    }
}
