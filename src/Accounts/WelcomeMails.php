<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Mail\Mailable;

/**
 * The email that welcomes an account the application made for someone --
 * by an operator, a billing system, an import -- with a link to choose its
 * password (ADR 0189). An app that makes accounts only by sign-up writes
 * nothing for it, and the kit refuses to send one for it
 * (Accounts::sendWelcome()).
 */
interface WelcomeMails
{
    /** The link that sets the account's first password, good once, for $days. */
    public function welcome(AccountUser $user, string $url, int $days): Mailable;
}
