<?php

declare(strict_types=1);

namespace Tinvar\Http\Middleware;

use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Routing\Domains;

/**
 * Keeps an entry point to the host it is declared on (ADR 0149).
 *
 * First in the stack EntryContext builds, ahead of the tenant and the
 * panel: a page re-rendered from a token, a fragment, a live subscription,
 * an action or a query, each run on the host its #[Domain] -- or its
 * panel's -- names, and nowhere else. Routing already matches a page only
 * on its host; this holds every other way a route or a call is reached,
 * so a token minted on one host and replayed on another finds nothing
 * there, as navigating would.
 *
 * With $parameters, the host's parameters must be the ones the entry point
 * was rendered with: a route token minted on acme's subdomain names acme,
 * and is refused on globex's.
 */
final readonly class RequireHost implements Middleware
{
    /**
     * @param string|null $pattern the #[Domain] pattern, as written; null for the primary host
     * @param array<string, string> $parameters the host parameters the entry point was rendered with
     */
    public function __construct(
        private Domains $domains,
        private ?string $pattern,
        private array $parameters = [],
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $host = $this->domains->resolve($request);
        if ($host->pattern !== $this->pattern) {
            throw new NotFoundException('This is not served on this host.');
        }
        foreach ($this->parameters as $name => $value) {
            if (($host->parameters[$name] ?? null) !== $value) {
                throw new NotFoundException('This was rendered for another host.');
            }
        }

        return $next($request);
    }
}
