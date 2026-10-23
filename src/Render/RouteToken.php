<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Support\Signer;

/**
 * A route this session was rendering, signed so it can be re-rendered.
 *
 * tinvar-spec.md, "Actions and revalidation": "The response carries a fresh
 * RSC payload for affected mounted routes, each rendered through its own
 * full pipeline from a signed route token." And the security model: "The
 * routes to re-render after an action are identified by signed route tokens
 * minted during the original render (route, params, panel, session hash).
 * Each re-render runs that route's full GET pipeline: middleware, layout and
 * page #[Authorize], loaders. If the pipeline denies, the action result is
 * returned without a payload."
 *
 * The token exists for the same reason the fragment token does, and means
 * the same thing: it says WHICH route the client was looking at, never
 * WHETHER it may look again. A client holds it, so it is not a capability --
 * the re-render runs the whole GET pipeline, and a session that has lost
 * access since the page loaded gets the denial it would get by navigating.
 *
 * It is deliberately the same shape as a fragment token, minus the component
 * and props. Both are `v1.<payload>.<mac>` over TokenClaims, both verify in
 * the same order, and both fail the same way -- so a check added for one is
 * a check both have.
 */
final readonly class RouteToken
{
    public const string PURPOSE = 'route-token';

    /** Matches the fragment token: a page outlives its action by far less. */
    public const int DEFAULT_TTL = 3600;
    public const int MAX_LIFETIME = 86400;

    public function __construct(public TokenClaims $claims) {}

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, $this->claims->toMap());
    }

    /** @throws TokenError */
    public static function decode(string $token, Signer $signer, ?int $now = null): self
    {
        $claims = TokenClaims::fromMap(SignedToken::decode($token, $signer, self::PURPOSE));
        $claims->assertFresh(self::DEFAULT_TTL, self::MAX_LIFETIME, $now);

        return new self($claims);
    }

    /** Null when the absolute lifetime has run out: the client must re-bootstrap. */
    public function refreshed(?int $now = null): ?self
    {
        $claims = $this->claims->refreshed(self::DEFAULT_TTL, self::MAX_LIFETIME, $now);

        return $claims === null ? null : new self($claims);
    }
}
