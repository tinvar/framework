<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Api\TokenHolderLookup;
use Tinvar\Auth\Authenticatable;

/**
 * The application's users as an API token's holder is found again (ADR
 * 0159): by id, at their credential epoch (CredentialEpochs) -- which moves
 * when they sign out everywhere, their password is reset or changed, their
 * address changes or they turn two-factor off, ending every token they
 * hold, and not at each sign-in, as their auth epoch does. A token's
 * request runs on their auth epoch (AuthEpochs), as a session's does: what
 * it binds to them -- a job, a link, a cached answer -- is checked against
 * that.
 *
 *     apiAccess: new ApiAccess(new DatabaseApiTokens($connection), $accounts->tokenHolders),
 */
final readonly class AccountTokenHolders implements TokenHolderLookup
{
    public function __construct(private AccountUsers $users, private CredentialEpochs $epochs, private AuthEpochs $authEpochs) {}

    public function find(string $id): ?Authenticatable
    {
        return $this->users->find($id);
    }

    public function epoch(Authenticatable $user): string
    {
        return $this->epochs->of($user->authIdentifier());
    }

    public function authEpoch(Authenticatable $user): string
    {
        return $this->authEpochs->of($user->authIdentifier());
    }
}
