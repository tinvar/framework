<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Mail\Mailable;

/**
 * The application's account emails: what each says, in its own words and
 * templates. The kit decides when one goes and builds every link in it; the
 * application's implementation only dresses it (the skeleton's is
 * App\Mail\Account\AccountMails, its templates resources/emails/).
 *
 * Every one is sent by the queue worker (SendAccountMail), and the ones
 * with a link in them are made there and sent at once, never queued again:
 * a queued mailable is a row in the jobs table until it is sent, and the
 * link is a credential. So each is an essential message (MailCategory),
 * sent whatever the suppression list holds for the address typed a moment
 * ago.
 */
interface AccountMails
{
    /** To an address a new account was asked for at: the link that makes it. */
    public function signUp(string $email, string $name, string $url, int $minutes): Mailable;

    /**
     * To an account whose address someone signed up with again: no link that
     * does anything, only the pages to sign in or reset from. The sign-up
     * form answered as it always does, so this is how the owner hears of it.
     */
    public function alreadyRegistered(AccountUser $user, string $signInUrl, string $resetUrl): Mailable;

    /** The link that chooses a new password. */
    public function passwordReset(AccountUser $user, string $url, int $minutes): Mailable;

    /** To the new address an account asked to move to: the link that moves it. */
    public function emailChange(AccountUser $user, string $newEmail, string $url, int $minutes): Mailable;

    /** To the address an account moved away from: that it did, and where to reset from if it was not them. */
    public function emailChanged(AccountUser $user, string $oldEmail, string $resetUrl): Mailable;

    /** That the account's password was changed or reset, and where to reset from if it was not them. */
    public function passwordChanged(AccountUser $user, string $resetUrl): Mailable;
}
