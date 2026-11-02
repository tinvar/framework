<?php

declare(strict_types=1);

namespace Tinvar\Audit;

/**
 * The security events an application records with AuditLog::security()
 * (ADR 0153): the value is the event the entry is filed under. An event
 * of the application's own is a string, or a backed enum of its own,
 * handed to AuditLog::event().
 */
enum SecurityEvent: string
{
    case SignedIn = 'auth.signed_in';
    case SignedOut = 'auth.signed_out';
    case SignedOutEverywhere = 'auth.signed_out_everywhere';
    case SignInFailed = 'auth.sign_in_failed';
    case PasswordChanged = 'auth.password_changed';
    case PasswordResetRequested = 'auth.password_reset_requested';
    case EmailChanged = 'auth.email_changed';
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorDisabled = 'auth.two_factor_disabled';
    case ImpersonationStarted = 'auth.impersonation_started';
    case ImpersonationStopped = 'auth.impersonation_stopped';
    case RoleChanged = 'auth.role_changed';
}
