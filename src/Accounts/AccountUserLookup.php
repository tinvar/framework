<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\UserLookup;

/**
 * The application's users as the framework finds one again by id, at the
 * auth epoch they are on now: what an emailed link is checked against, and
 * a job running as its dispatcher (JobRunner).
 */
final readonly class AccountUserLookup implements UserLookup
{
    public function __construct(private AccountUsers $users, private AuthEpochs $epochs) {}

    public function find(string $id): ?Authenticatable
    {
        return $this->users->find($id);
    }

    public function epoch(Authenticatable $user): string
    {
        return $this->epochs->of($user->authIdentifier());
    }
}
