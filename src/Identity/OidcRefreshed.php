<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * A refresh the provider answered: the tokens it granted, and whom they
 * are for -- the ID token it sent with them, verified against the sign-in
 * being renewed, or that sign-in's own where it sent none.
 */
final readonly class OidcRefreshed
{
    public function __construct(
        public IdToken $idToken,
        public OidcTokens $tokens,
    ) {}
}
