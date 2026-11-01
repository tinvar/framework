<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * How far a session's row has got: signed in, or waiting on a second
 * factor after a password that was right. A session at the second stage
 * signs nobody in -- every request it makes is a signed-out visitor's --
 * until a code or a recovery code moves it on, under a new id.
 */
enum SessionStage: string
{
    case SignedIn = 'signed-in';
    case SecondFactor = 'second-factor';
}
