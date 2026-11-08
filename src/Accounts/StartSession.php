<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;

/**
 * The global middleware that opens the session for every request: signs its
 * user in for the request (AuthContext::login(), with their auth epoch),
 * runs the rest of it as the tenant the session holds, if it holds one,
 * carries the SessionState on the request for the actions that rotate it,
 * and sets the cookie a rotation made.
 *
 * The new session's CSRF token goes with the new cookie, since whatever
 * page the browser lands on next may be one that issues none: without it,
 * a sign-in landing on a 404 left the header's sign-out form posting the
 * old session's token, refused with a 419.
 *
 * The session's tenant is the one its user chose (Sessions::switchTenant(),
 * ADR 0181), entered through Orm::actAs() for exactly as long as the
 * request runs. It is a choice, never a grant: a panel's membership check
 * asks in it on every request, and an entry point's #[TenantFrom] source
 * only constrains it (ResolveTenant). Fails closed: a session holding a
 * tenant, where this was given no Orm with tenancy to run it as, is refused
 * with a LogicException rather than run as no tenant.
 */
final readonly class StartSession implements Middleware
{
    /**
     * @param Orm|null $orm the application's, with its tenancy registered where its sessions
     *        switch tenant; an app whose sessions never hold one needs none
     */
    public function __construct(
        private Sessions $sessions,
        private AuthContext $auth,
        private ?CsrfTokens $csrf = null,
        private ?Orm $orm = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $state = $this->sessions->open($request);
        // With who is acting as the user, while staff impersonate them (ADR
        // 0194): the audit log, the job dispatcher and ApiTokens read it.
        $this->auth->login($state->user(), $state->epoch(), impersonator: $state->impersonator(), impersonation: $state->impersonation()?->id);

        $inner = $request->withAttribute(SessionState::ATTRIBUTE, $state);
        $tenant = $state->tenant();
        if ($tenant === null) {
            $response = $next($inner);
        } else {
            $orm = $this->orm;
            if ($orm === null || !$orm->hasTenancy()) {
                throw new \LogicException(
                    'This session runs as a tenant, and StartSession has no Orm with tenancy to run it as: '
                    . 'give AccountsKit the application\'s Orm (orm:), with Tenancy::tree() registered.',
                );
            }
            $response = $orm->actAs($tenant, static fn (): Response => $next($inner));
        }

        // Inside the pipeline, before the Kernel's teardown: the state is
        // gone once handle() returns, and with it the cookie a sign-in made.
        $cookie = $state->replacement();

        return $cookie === null ? $response : $this->sessions->cookie()->set($response, $cookie, $this->csrf);
    }
}
