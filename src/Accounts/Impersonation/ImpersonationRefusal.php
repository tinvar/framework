<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

/**
 * Why an impersonation was not started, arrived or stopped, or why a
 * request was refused for being one (ADR 0194).
 */
enum ImpersonationRefusal: string
{
    case NotSignedIn = 'not_signed_in';
    case ApiToken = 'api_token';
    case AlreadyImpersonating = 'already_impersonating';
    case WrongPanel = 'wrong_panel';
    case NotPermitted = 'not_permitted';
    case Themselves = 'themselves';
    case Outranked = 'outranked';
    case UnknownHost = 'unknown_host';
    case GrantRefused = 'grant_refused';
    case NotImpersonating = 'not_impersonating';
    case WhileImpersonating = 'while_impersonating';
    case SessionEnded = 'session_ended';

    /** What the refusal says to whoever was refused. */
    public function message(): string
    {
        return match ($this) {
            self::NotSignedIn => 'Sign in to impersonate anyone.',
            self::ApiToken => 'An API token cannot impersonate anyone: only a signed-in session can.',
            self::AlreadyImpersonating => 'You are impersonating someone already. Stop first, then start again from your own account.',
            self::WrongPanel => 'An impersonation is started from the panel the app names for it, and this is not that panel.',
            self::NotPermitted => 'You may not impersonate anyone.',
            self::Themselves => 'You cannot impersonate yourself.',
            self::Outranked => 'You may not impersonate this user: they have as much privilege as you, or more.',
            self::UnknownHost => 'That is not one of this app\'s own hosts, so no impersonation goes there.',
            self::GrantRefused => 'This impersonation link has expired, has been used, or is not yours. Start again.',
            self::NotImpersonating => 'You are not impersonating anyone.',
            self::WhileImpersonating => 'This cannot be done while impersonating: it changes how the account signs in, which is its owner\'s alone. Stop impersonating first.',
            self::SessionEnded => 'Your session ended while this was being done. Sign in again.',
        };
    }
}
