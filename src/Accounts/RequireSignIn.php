<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * A page for signed-in users: anyone else is sent to the sign-in page,
 * which remembers where they were going.
 *
 *     #[Page('/account', view: 'account/settings'), Middleware(RequireSignIn::class)]
 */
final readonly class RequireSignIn implements Middleware
{
    public function __construct(private AuthContext $auth, private AccountPaths $paths) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        return Response::redirect($this->paths->signInFor($request->path, $request->rawQuery));
    }
}
