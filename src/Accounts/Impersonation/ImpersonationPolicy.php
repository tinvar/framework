<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Tinvar\Accounts\AccountUser;

/**
 * Who may act as whom, and in which tenant (ADR 0194): the application's
 * answer, since only it knows its roles. Asked when an impersonation
 * starts, and again when it arrives on another of the app's hosts, with the
 * tenant it was started in -- the one it runs in, and never leaves -- or ''
 * for an app without tenancy, or a request in no tenant.
 *
 * RankedImpersonationPolicy is the usual answer: a permission to impersonate
 * at all, and never someone of the impersonator's own rank or above.
 */
interface ImpersonationPolicy
{
    /** Whether $impersonator may impersonate anyone in $tenant: holds the app's permission to there. */
    public function mayImpersonate(AccountUser $impersonator, string $tenant): bool;

    /**
     * Whether $impersonator may act as $target. An answer that lets someone
     * act as a user of their own privilege or more lets them do whatever
     * that user may -- to other staff, to the policy itself -- so the usual
     * answer is no. And $target is someone of $tenant's, where it is a tenant
     * the impersonator administers: the impersonation runs in it alone.
     */
    public function mayImpersonateUser(AccountUser $impersonator, AccountUser $target, string $tenant): bool;
}
