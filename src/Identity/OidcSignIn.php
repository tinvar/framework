<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * A completed sign-in through an OpenID provider: whom the provider vouched
 * for (the ID token, verified), and the path on this application the
 * sign-in began from, to go on to. The application finds or links its own
 * account by the token's issuer and subject, and starts its own session --
 * a new one, as every sign-in does.
 */
final readonly class OidcSignIn
{
    public function __construct(
        public IdToken $idToken,
        public string $returnTo,
    ) {}
}
