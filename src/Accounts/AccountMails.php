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

    /**
     * That a passkey called $passkeyName was added to the account: a passkey
     * outlives every session, "sign out everywhere" included, so the owner
     * hears of each one -- with the account page to remove it from, and where
     * to reset the password from, if it was not them.
     */
    public function passkeyAdded(AccountUser $user, string $passkeyName, string $accountUrl, string $resetUrl): Mailable;

    /**
     * That a passkey called $passkeyName was removed from the account: the
     * owner hears of a way in taken away, as of one added, with the account
     * page and where to reset the password from, if it was not them.
     */
    public function passkeyRemoved(AccountUser $user, string $passkeyName, string $accountUrl, string $resetUrl): Mailable;

    /**
     * That two-factor sign-in was turned off, which signed out every other
     * device: a password alone signs in now, so the owner hears of it, with
     * where to reset the password from if it was not them.
     */
    public function twoFactorDisabled(AccountUser $user, string $resetUrl): Mailable;

    /**
     * That a sign-in to the account typed its password right -- or used an
     * emailed link -- and then too many wrong codes, so it was ended (ADR
     * 0203): whoever it was has the password, so the owner hears of it,
     * with where to reset the password from if it was not them.
     */
    public function secondFactorFailures(AccountUser $user, string $resetUrl): Mailable;

    /**
     * That an API token called $tokenName was made for the account (ADR
     * 0159): a token outlives every session and every sign-in, so the owner
     * hears of each one -- with the page to revoke it from, and where to
     * reset the password from (which ends every token), if it was not them.
     * Never the token's text: only its name.
     */
    public function apiTokenCreated(AccountUser $user, string $tokenName, string $tokensUrl, string $resetUrl): Mailable;
}
