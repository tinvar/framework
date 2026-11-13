<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/** Which account email a SendAccountMail job sends (AccountMails). */
enum AccountMailKind: string
{
    case SignUp = 'sign-up';
    case SignInLink = 'sign-in-link';
    case PasswordReset = 'password-reset';
    case Welcome = 'welcome';
    case EmailChange = 'email-change';
    case EmailChanged = 'email-changed';
    case PasswordChanged = 'password-changed';
    case PasskeyAdded = 'passkey-added';
    case PasskeyRemoved = 'passkey-removed';
    case TwoFactorEnabled = 'two-factor-enabled';
    case TwoFactorDisabled = 'two-factor-disabled';
    case SecondFactorFailures = 'second-factor-failures';
    case ApiTokenCreated = 'api-token-created';
}
