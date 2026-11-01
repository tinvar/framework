<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/** Where a password that was right leaves the browser. */
enum SignInResult: string
{
    /** Signed in, under a new session id. */
    case SignedIn = 'signed-in';

    /** Waiting on a second factor: two-factor sign-in is on for the account. */
    case SecondFactor = 'second-factor';
}
