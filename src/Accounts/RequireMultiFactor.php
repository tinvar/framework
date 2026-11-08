<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * A page, or a panel, for sessions whose sign-in proved two factors (ADR
 * 0184): a passkey, or a password or an emailed link and a code. A visitor
 * is sent to sign in, as RequireSignIn sends them; a session of one factor
 * -- a password a thief may have -- is sent to the app's page for proving a
 * second (AccountPaths::$verify), which confirms with a code or a passkey
 * (Accounts::confirmWithCode(), confirmWithPasskey()) and goes on to `next`.
 *
 *     #[Page('/admin', view: 'admin/home'), Middleware(RequireMultiFactor::class)]
 *
 * or, for a panel, from its guard:
 *
 *     return $this->multiFactor->handle($request, fn (Request $r) => ...the guard's own checks...);
 *
 * What a session proved is read from its row, sealed (Sessions); a request
 * signed in some other way -- an API token, which has no session -- has
 * proved nothing here, and is sent on too.
 */
final readonly class RequireMultiFactor implements Middleware
{
    public function __construct(private AuthContext $auth, private AccountPaths $paths, private RequireSignIn $signIn) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->auth->check()) {
            return $this->signIn->handle($request, $next);
        }

        $state = $request->attribute(SessionState::ATTRIBUTE);
        $user = $this->auth->user();
        if ($state instanceof SessionState && $user !== null && $state->user()?->authIdentifier() === $user->authIdentifier()
            && $state->factors()->multiFactor()) {
            return $next($request);
        }

        return Response::redirect($this->paths->verifyFor($request->path, $request->rawQuery));
    }
}
