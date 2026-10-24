<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Routing\RouteChain;
use Tinvar\Support\Signer;

/**
 * Mints the route token a page hands its client.
 *
 * A route token is only useful if the client has one, and the client only
 * gets one if the page render issues it — the same relationship a fragment
 * token has to a `<Deferred>` boundary. Written at the same time as the
 * token itself rather than after, because the last three features in this
 * codebase shipped a signed artefact that nothing produced, and each took a
 * review to notice.
 *
 * Like FragmentMinter, this exists so the binding fields are the
 * FRAMEWORK's. Route and params come from the chain that was actually
 * matched, not from anything a caller passed; panel, tenant, build, session,
 * user, auth epoch and expiry come from the request being served. A page
 * cannot mint itself a token for a different route.
 */
final readonly class RouteMinter
{
    public function __construct(
        private Signer $signer,
        private string $panel,
        private string $tenant,
        private string $build,
        // Sensitive: the refusals below throw, and their trace would
        // otherwise carry the session id.
        #[\SensitiveParameter]
        private string $sessionId,
        private int $ttl = RouteToken::DEFAULT_TTL,
        // Who is signed in, and the auth epoch the application keeps for
        // them (AuthContext): client-held data is bound to the user, and
        // cleared on a role change or impersonation the session id survives.
        private ?string $user = null,
        private string $authEpoch = '',
    ) {
        if ($this->sessionId === '') {
            // One constant hash would make every visitor's token
            // interchangeable, which is the whole value of the binding.
            throw new \InvalidArgumentException('A session is required before route tokens can be minted.');
        }

        if ($ttl < 1 || $ttl > RouteToken::DEFAULT_TTL) {
            throw new \InvalidArgumentException(
                'A route token TTL must be between 1 second and ' . RouteToken::DEFAULT_TTL . '.',
            );
        }
    }

    /**
     * The token for a chain that has just been matched and rendered.
     *
     * An unnamed route gets none: a token whose route cannot be named
     * cannot be re-rendered from, so issuing one would be issuing something
     * the revalidator will always discard.
     */
    public function mint(RouteChain $chain): ?string
    {
        $name = $chain->page->name;
        if ($name === null) {
            return null;
        }

        $now = time();

        return (new RouteToken(new TokenClaims(
            route: $name,
            params: $chain->parameters,
            panel: $this->panel,
            tenant: $this->tenant,
            build: $this->build,
            sessionHash: TokenClaims::hashSession($this->signer, $this->sessionId),
            expiresAt: $now + $this->ttl,
            issuedAt: $now,
            authHash: TokenClaims::hashAuth($this->signer, $this->user, $this->authEpoch),
        )))->encode($this->signer);
    }
}
