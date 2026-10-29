<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * An OpenID provider as its discovery document described it, once
 * OidcDiscovery had held the document to its issuer: every endpoint an
 * https URL, and the signing algorithms without `none`.
 */
final readonly class OidcProvider
{
    /**
     * @param list<string> $responseTypes
     * @param list<string> $signingAlgorithms the ID token algorithms offered, `none` never among them
     * @param list<string> $codeChallengeMethods empty when the document names none
     * @param list<string> $scopes empty when the document names none
     */
    public function __construct(
        /** Exactly the issuer it was discovered for, which an ID token's `iss` must equal. */
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userinfoEndpoint,
        public ?string $endSessionEndpoint,
        public array $responseTypes,
        public array $signingAlgorithms,
        public array $codeChallengeMethods,
        public array $scopes,
    ) {}

    /** Whether the provider says it takes PKCE's S256, as a sign-in from here sends. */
    public function supportsPkce(): bool
    {
        return \in_array('S256', $this->codeChallengeMethods, true);
    }
}
