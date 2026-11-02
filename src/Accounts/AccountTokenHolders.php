<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\UserLookup;

/**
 * The application's users as an API token's holder is found again (ADR
 * 0159): by id, at their credential epoch (CredentialEpochs) -- which moves
 * when they sign out everywhere, their password is reset or changed, their
 * address changes or they turn two-factor off, ending every token they
 * hold, and not at each sign-in, as their auth epoch does.
 *
 *     apiAccess: new ApiAccess(new DatabaseApiTokens($connection), $accounts->tokenHolders),
 */
final readonly class AccountTokenHolders implements UserLookup
{
    public function __construct(private AccountUsers $users, private CredentialEpochs $epochs) {}

    public function find(string $id): ?Authenticatable
    {
        return $this->users->find($id);
    }

    public function epoch(Authenticatable $user): string
    {
        return $this->epochs->of($user->authIdentifier());
    }
}
