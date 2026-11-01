<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * The global middleware that opens the session for every request: signs its
 * user in for the request (AuthContext::login(), with their auth epoch),
 * carries the SessionState on the request for the actions that rotate it,
 * and sets the cookie a rotation made.
 *
 * The new session's CSRF token goes with the new cookie, since whatever
 * page the browser lands on next may be one that issues none: without it,
 * a sign-in landing on a 404 left the header's sign-out form posting the
 * old session's token, refused with a 419.
 */
final readonly class StartSession implements Middleware
{
    public function __construct(
        private Sessions $sessions,
        private AuthContext $auth,
        private ?CsrfTokens $csrf = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $state = $this->sessions->open($request);
        $this->auth->login($state->user(), $state->epoch());

        $response = $next($request->withAttribute(SessionState::ATTRIBUTE, $state));

        // Inside the pipeline, before the Kernel's teardown: the state is
        // gone once handle() returns, and with it the cookie a sign-in made.
        $cookie = $state->replacement();

        return $cookie === null ? $response : $this->sessions->cookie()->set($response, $cookie, $this->csrf);
    }
}
