<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realms;

/**
 * A page for signed-in users: anyone else is sent to the sign-in page,
 * which remembers where they were going.
 *
 *     #[Page('/account', view: 'account/settings'), Middleware(RequireSignIn::class)]
 *
 * On another of the app's hosts -- an admin panel's own, say (ADR 0039) --
 * nobody is asked to sign in there: "sign-in happens on the platform host,
 * then redirects back". A handoff starts instead, which signs them in on the
 * authority's host if they are not already, and brings them back here under
 * a session of this host's own (HandoffArrivals). AccountsKit builds it with
 * the app's Realms, where it has them.
 */
final readonly class RequireSignIn implements Middleware
{
    /** @param Realms|null $realms the app's hosts, where it has more than one */
    public function __construct(private AuthContext $auth, private AccountPaths $paths, private ?Realms $realms = null) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        $realms = $this->realms;
        $realm = $realms?->forRequest($request);
        if ($realms !== null && $realm !== null && !$realms->isAuthority($realm)) {
            $here = $request->rawQuery === null || $request->rawQuery === '' ? $request->path : "{$request->path}?{$request->rawQuery}";

            // Held as a sign-in's next is: a path on this site, or home.
            return Response::redirect(HandoffEndpoint::START . '?return=' . rawurlencode($this->paths->next($here)));
        }

        return Response::redirect($this->paths->signInFor($request->path, $request->rawQuery));
    }
}
