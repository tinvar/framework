<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Attributes\PublicAccess;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * "Actions and queries require an authenticated user" unless the entry point
 * opts out with #[PublicAccess].
 *
 * The opt-out used to exist only in this sentence: the attribute was declared,
 * referenced by nothing but this docblock, and the middleware 401'd
 * unconditionally. It is honoured now, which needs knowing WHICH entry point a
 * request is for -- the middleware cannot work that out from the path alone,
 * so whoever mounts it supplies the lookup. Without one, nothing is public,
 * which is the right direction to default in.
 *
 * A 401 with a clear code, so the client knows to purge client-held data
 * rather than retry: "Any mismatch between stored and current user or tenant
 * also purges it, which the server signals with a 401 and a clear code."
 */
final class RequireAuthentication implements Middleware
{
    /**
     * @param (\Closure(Request): (array{0: class-string, 1: string|null}|null))|null $entryPointFor
     *        the class, and method for an action, this request targets
     */
    public function __construct(
        private readonly AuthContext $auth,
        private readonly ?\Closure $entryPointFor = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->isPublic($request)) {
            return $next($request);
        }

        if (!$this->auth->check()) {
            throw new HttpException(401, 'Authentication required.', 'unauthenticated');
        }

        return $next($request);
    }

    private function isPublic(Request $request): bool
    {
        if ($this->entryPointFor === null) {
            return false;
        }

        $entryPoint = ($this->entryPointFor)($request);
        if ($entryPoint === null) {
            // An unknown name is not a public one. It gets a 401 here and the
            // endpoint would refuse it anyway; saying "no such action" to an
            // anonymous caller would be an enumeration oracle.
            return false;
        }

        return PublicAccess::declaredOn($entryPoint[0], $entryPoint[1]);
    }
}
