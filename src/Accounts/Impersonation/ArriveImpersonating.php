<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * The page an impersonation started on another of the app's hosts arrives
 * at (ADR 0194): it takes the grant in the query, turns this host's session
 * -- the impersonator's own -- into the impersonated user's, and sends the
 * browser on to the page the impersonation was started for. Behind
 * RequireSignIn, so an impersonator not signed in on this host yet is
 * brought here by a handoff first (ADR 0039):
 *
 *     #[Page('/impersonate', view: 'app/impersonate'),
 *       Middleware(RequireSignIn::class, ArriveImpersonating::class)]
 *
 * The page's own view is never drawn. Sent with no Referer and kept by no
 * cache, as an emailed link's page is, so the grant goes nowhere further.
 */
final readonly class ArriveImpersonating implements Middleware
{
    public function __construct(private Impersonations $impersonations) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $grant = $request->query[Impersonations::PARAMETER] ?? null;
        if (!\is_string($grant)) {
            throw new ImpersonationRefused(ImpersonationRefusal::GrantRefused);
        }

        return Response::redirect($this->impersonations->arrive($request, $grant), 303)
            ->withHeader('referrer-policy', 'no-referrer')
            ->withHeader('cache-control', 'no-store');
    }
}
