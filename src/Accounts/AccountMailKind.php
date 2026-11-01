<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/** Which account email a SendAccountMail job sends (AccountMails). */
enum AccountMailKind: string
{
    case SignUp = 'sign-up';
    case PasswordReset = 'password-reset';
    case EmailChange = 'email-change';
    case EmailChanged = 'email-changed';
    case PasswordChanged = 'password-changed';
}
