<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * "Actions and queries require an authenticated user" unless the entry point
 * opts out with #[PublicAccess].
 *
 * A 401 with a clear code, so the client knows to purge client-held data
 * rather than retry: "Any mismatch between stored and current user or tenant
 * also purges it, which the server signals with a 401 and a clear code."
 */
final class RequireAuthentication implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->auth->check()) {
            throw new HttpException(401, 'Authentication required.', 'unauthenticated');
        }

        return $next($request);
    }
}
