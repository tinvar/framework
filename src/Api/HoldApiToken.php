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
 * requires and where it runs (ADR 0151): every scope its #[RequiresScope]
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
 */
final readonly class HoldApiToken implements Middleware
{
    /** @param list<string> $scopes what the endpoint requires */
    public function __construct(
        private array $scopes,
        private Orm $orm,
        private ?PanelContext $panels = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $presented = SignInWithApiToken::presentedBy($request)
            ?? throw new \LogicException('An endpoint requiring API token scopes was reached with no token signed in: SignInWithApiToken runs first.');
        $token = $presented->token;

        if (!$token->grants($this->scopes)) {
            throw SignInWithApiToken::insufficientScope($this->scopes);
        }
        if ($this->orm->isUnscoped() || !hash_equals($token->tenant, $this->orm->tenant())) {
            throw new HttpException(403, 'An API token was presented in a tenant it was not issued in.', 'api_token_tenant');
        }
        if (!hash_equals($token->panel, $this->panels?->currentOrNull() ?? '')) {
            throw new HttpException(403, 'An API token was presented in a panel it was not issued in.', 'api_token_panel');
        }

        return $next($request);
    }
}
