<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Auth\Authenticatable;

/**
 * A token a request presented that holds -- its secret matched, neither
 * revoked nor expired, its user still there on the epoch it was issued on,
 * presented on its own host -- with the user it signs in and the auth
 * epoch the request runs on (TokenHolderLookup), which is not always the
 * epoch the token is held to.
 * What it may do beyond signing them in is held where it is used: its
 * scopes, tenant and panel (ApiCalls, HoldApiToken).
 */
final readonly class PresentedApiToken
{
    public function __construct(
        public ApiToken $token,
        public Authenticatable $user,
        public string $epoch,
    ) {}
}
