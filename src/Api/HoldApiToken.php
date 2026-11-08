<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Auth\PanelContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;

/**
 * Holds the token that signed an endpoint's caller in to what the endpoint
 * requires and where it runs (ADR 0159): every scope its #[RequiresScope]
 * names, and the tenant and the panel the entry checks put the request in,
 * which must be the token's own.
 *
 * Run after those checks, which resolve the tenant from the request and the
 * panel from the endpoint, and run the panel's guard and membership for the
 * token's user. A token can narrow where a call runs, never move it: one
 * issued in one tenant reaches no endpoint resolved to another, and one
 * issued outside a panel reaches none inside it. The host is held already:
 * the token was refused at sign-in on any host but its own, and the
 * endpoint runs on its #[Domain]'s alone.
 *
 * A tenant's token (ADR 0193) is held the same way, and refused outright by
 * an endpoint that does not say #[AcceptsTenantTokens].
 */
final readonly class HoldApiToken implements Middleware
{
    /**
     * @param list<string> $scopes what the endpoint requires
     * @param bool $tenantTokens whether the endpoint accepts a tenant's token (#[AcceptsTenantTokens])
     */
    public function __construct(
        private array $scopes,
        private Orm $orm,
        private ?PanelContext $panels = null,
        private bool $tenantTokens = false,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = SignInWithApiToken::tenantTokenPresentedBy($request);
        if ($tenant !== null && !$this->tenantTokens) {
            throw new HttpException(403, 'An organization\'s API token was presented where only a user\'s is taken.', 'tenant_token_not_accepted');
        }
        $presented = $tenant
            ?? SignInWithApiToken::presentedBy($request)
            ?? throw new \LogicException('An endpoint requiring API token scopes was reached with no token signed in: SignInWithApiToken runs first.');
        $token = $presented->token;

        if (!$token->grants($this->scopes)) {
            throw SignInWithApiToken::insufficientScope($this->scopes);
        }
        $panel = $this->panels?->currentOrNull() ?? '';
        $resolved = $this->orm->isUnscoped() ? null : $this->orm->tenant();
        // A tenant's token, at an endpoint that resolves no tenant and is in
        // no panel, narrows the call to its own tenant, as /_api does: it is
        // never anyone's but its tenant's. Anywhere a tenant was resolved,
        // or a panel's membership checked, it must be the token's own.
        $narrows = $tenant !== null && $resolved === '' && $panel === '';
        if ($resolved === null || (!$narrows && !hash_equals($token->tenant, $resolved))) {
            throw new HttpException(403, 'An API token was presented in a tenant it was not issued in.', 'api_token_tenant');
        }
        if (!hash_equals($token->panel, $panel)) {
            throw new HttpException(403, 'An API token was presented in a panel it was not issued in.', 'api_token_panel');
        }

        return $narrows
            ? $this->orm->actAs($token->tenant, static fn (): Response => $next($request))
            : $next($request);
    }
}
