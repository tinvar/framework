<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Http\Request;

/**
 * The host a request was answered as (ADR 0149): which of the application's
 * host patterns it matched -- null for the primary host -- the host itself,
 * normalized, and the parameters the pattern took from it.
 *
 * Resolved once, at the front of the Kernel, and carried on the request
 * under ATTRIBUTE, so the routing, the entry points' stacks, the tokens a
 * page mints and the tokens /_file and /_upload check all read the one
 * answer.
 */
final readonly class HostMatch
{
    public const string ATTRIBUTE = 'tinvar.host';

    /**
     * @param string|null $pattern the #[Domain] pattern matched, as written; null for the primary host
     * @param string $host the host as the request named it, normalized; '' for a request that named none
     * @param array<string, string> $parameters what the pattern's parameters took
     */
    public function __construct(
        public ?string $pattern,
        public string $host,
        public array $parameters = [],
    ) {}

    /** The match carried on $request, or null when none was resolved. */
    public static function of(Request $request): ?self
    {
        $match = $request->attribute(self::ATTRIBUTE);

        return $match instanceof self ? $match : null;
    }

    /**
     * The host a token minted for $request is bound to: the matched host,
     * or '' where the application routes on no host.
     */
    public static function hostOf(Request $request): string
    {
        return self::of($request)->host ?? '';
    }

    /** Whether this is the host a route declaring $pattern (null: none) is served on. */
    public function serves(?string $pattern): bool
    {
        return $this->pattern === $pattern;
    }
}
