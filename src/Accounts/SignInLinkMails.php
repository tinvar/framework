<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Mail\Mailable;

/**
 * The email with a sign-in link in it, for an application that lets its
 * accounts sign in by one (ADR 0162): its AccountMails implements this too.
 * An app that offers no such link -- the skeleton -- writes nothing for it,
 * and the kit refuses to send one for it (Accounts::requestSignInLink()).
 */
interface SignInLinkMails
{
    /** The link that signs the account in, good once, for $minutes. */
    public function signInLink(AccountUser $user, string $url, int $minutes): Mailable;
}
