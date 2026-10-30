<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Config\Secret;

/**
 * What a provider's token endpoint granted: an access token, perhaps a
 * refresh token, the ID token as it came, and when the access token runs
 * out.
 *
 * Each is a Secret: a bearer token reaches the provider's APIs as the
 * person, and the ID token names them, so none of them is dumped, logged,
 * JSON-encoded or serialized by accident. The framework keeps none of them
 * (ADR 0124). An application that wants userinfo later, a refresh, or an
 * `id_token_hint` at logout keeps what it needs where it keeps the session
 * -- server side, never in a cookie or a page -- and drops it at sign-out.
 */
final readonly class OidcTokens
{
    public function __construct(
        public Secret $accessToken,
        /** Null where the provider granted none, or a refresh kept the one it had. */
        public ?Secret $refreshToken,
        /** The ID token as the provider signed it: an `id_token_hint` at logout. Null where a refresh answered with none. */
        public ?Secret $idToken,
        /** Seconds since the epoch the access token runs out at; null where the provider did not say. */
        public ?int $expiresAt,
    ) {}
}
