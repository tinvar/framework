<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Tinvar\Accounts\AccountUser;

/**
 * Who may act as whom (ADR 0194): the application's answer, since only it
 * knows its roles. Asked when an impersonation starts, and again when it
 * arrives on another of the app's hosts.
 *
 * RankedImpersonationPolicy is the usual answer: a permission to impersonate
 * at all, and never someone of the impersonator's own rank or above.
 */
interface ImpersonationPolicy
{
    /** Whether $impersonator may impersonate anyone: holds the app's permission to. */
    public function mayImpersonate(AccountUser $impersonator): bool;

    /**
     * Whether $impersonator may act as $target. An answer that lets someone
     * act as a user of their own privilege or more lets them do whatever
     * that user may -- to other staff, to the policy itself -- so the usual
     * answer is no.
     */
    public function mayImpersonateUser(AccountUser $impersonator, AccountUser $target): bool;
}
