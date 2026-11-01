<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * A page an emailed link lands on, with its token in the query string: sent
 * with no Referer to wherever it links, and kept by no cache, so the token
 * goes nowhere the page does not send it (ADR 0076).
 */
final readonly class PrivateLinkPage implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request)
            ->withHeader('referrer-policy', 'no-referrer')
            ->withHeader('cache-control', 'no-store');
    }
}
