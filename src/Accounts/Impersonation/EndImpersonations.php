<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Tinvar\Attributes\Schedule;

/**
 * Every minute, ends the impersonations whose time is up (ADR 0194): each
 * one's session and the impersonator's parked one deleted, its user's auth
 * epoch moved and its end recorded, whether or not its browser ever comes
 * back. Without it a time box held only for a browser that asked again:
 * one closed mid-impersonation left the user's epoch where the
 * impersonation put it, and every link, grant and subscription bound to it
 * counting after the time was up.
 *
 * A task an application that impersonates lists among its scheduled tasks,
 * beside the kit's `impersonation:`.
 */
#[Schedule('* * * * *')]
final class EndImpersonations
{
    public function __invoke(Impersonations $impersonations): void
    {
        $impersonations->endExpired();
    }
}
