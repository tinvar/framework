<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * A page for visitors -- signing in, signing up -- that a signed-in user is
 * sent on from: to the `next` it was given, or home.
 */
final readonly class RedirectIfSignedIn implements Middleware
{
    public function __construct(private AuthContext $auth, private AccountPaths $paths) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->auth->check()) {
            return $next($request);
        }

        return Response::redirect($this->paths->next($request->query['next'] ?? null));
    }
}
