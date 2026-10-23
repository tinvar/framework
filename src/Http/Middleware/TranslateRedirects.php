<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * A redirect answering a client navigation becomes a 409 the router can read.
 *
 * The router fetches with `redirect: 'manual'`, so that a navigation never
 * follows a redirect off-site with RSC headers attached. The price is that
 * fetch hides a manual redirect's Location entirely -- the response is
 * opaque -- and the router can only full-load the URL it ASKED for, which
 * runs the same redirect again as a document load. Every redirect a route
 * middleware or page issued cost a full page load, and lost the fragment.
 *
 * tinvar-spec.md, "Protocol": 409 + `X-Redirect` is "Redirect with a
 * preserved fragment"; 409 + `X-Location` is "Full page load: ... external
 * redirect". So:
 *
 *   - a root-relative target -- the only kind Response::redirect() makes --
 *     becomes X-Redirect, and the router fetches it inside the same visit;
 *   - anything else -- Response::location()'s allowlisted external URLs --
 *     becomes X-Location, and the router loads it as a document.
 *
 * Only for `RSC: 1` requests. A document request gets the real redirect,
 * which the browser follows itself.
 */
final class TranslateRedirects implements Middleware
{
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    /** Headers whose effect is not the redirect itself, carried onto the 409. */
    private const array CARRIED = ['set-cookie', 'clear-site-data'];

    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        if ($request->header('rsc') !== '1' || !\in_array($response->status, self::REDIRECTS, true)) {
            return $response;
        }

        $location = $response->header('location');
        if ($location === null || $location === '') {
            return $response;
        }

        // Root-relative and nothing that a browser would read as another
        // host: Response::redirect()'s own rules, by the same function,
        // because a response can be built without going through it. A copy
        // of them here had already missed the `..` rule. A target that fails
        // is still followed -- as a document load, where the browser applies
        // its own rules.
        $header = Response::isSameOriginTarget($location) ? 'x-redirect' : 'x-location';

        $translated = Response::make(409, '', [
            $header => $location,
            'cache-control' => 'private, no-store',
            'vary' => 'RSC',
        ]);

        // What a redirect does besides redirecting -- a login setting the
        // session cookie, a logout clearing site data -- must survive the
        // translation, or it silently stops happening for client navigations.
        foreach (self::CARRIED as $name) {
            $value = $response->header($name);
            if ($value !== null) {
                $translated = $translated->withHeader($name, $value);
            }
        }

        return $translated;
    }
}
