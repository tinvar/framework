<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Tinvar\Accounts\AccountUser;

/**
 * Impersonation by rank (ADR 0194): those $permitted says may impersonate
 * act as users ranked below them, and never as one of their own rank or
 * above -- unless $sameRank lets them act as their peers.
 *
 *     new RankedImpersonationPolicy(
 *         permitted: fn (AccountUser $user): bool => $admins->allows($user),
 *         rank: fn (AccountUser $user): int => $admins->allows($user) ? 1 : 0,
 *     );
 */
final readonly class RankedImpersonationPolicy implements ImpersonationPolicy
{
    /**
     * @param \Closure(AccountUser, string): bool $permitted whether a user holds the app's permission
     *        to impersonate, in the tenant (ADR 0194); a closure of the user alone is handed it too
     * @param \Closure(AccountUser, string): int $rank a user's privilege in the tenant, higher for more
     * @param bool $sameRank whether a user may act as one of their own rank; never above it
     */
    public function __construct(
        private \Closure $permitted,
        private \Closure $rank,
        private bool $sameRank = false,
    ) {}

    public function mayImpersonate(AccountUser $impersonator, string $tenant): bool
    {
        return ($this->permitted)($impersonator, $tenant) === true;
    }

    public function mayImpersonateUser(AccountUser $impersonator, AccountUser $target, string $tenant): bool
    {
        $mine = ($this->rank)($impersonator, $tenant);
        $theirs = ($this->rank)($target, $tenant);

        return $this->sameRank ? $theirs <= $mine : $theirs < $mine;
    }
}
