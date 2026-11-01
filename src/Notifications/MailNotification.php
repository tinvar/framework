<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Auth\Authenticatable;
use Tinvar\Jobs\Queueable;
use Tinvar\Mail\Email;

/**
 * A notification that sends an email (ADR 0150).
 *
 * mail() is asked on the mail queue's worker, when the email is sent --
 * after the user's preference for this kind was read again there -- with
 * the user it is for, found again by id, and the one-click unsubscribe URL
 * that turns this kind of notification's email off for them: link to it
 * from the body (MER-36.3), as the headers already do. Null when the
 * application gave Notifications no UnsubscribeLinks.
 *
 * The Email goes through the Mailer as any other: rendered by the
 * renderer, held to the suppression list by its category -- transactional
 * unless it says otherwise -- and sent by the configured providers. One with
 * no unsubscribe of its own is given this kind's List-Unsubscribe headers.
 * Return null to send nothing to this user.
 */
interface MailNotification extends Queueable
{
    public function mail(Authenticatable $user, ?string $unsubscribeUrl): ?Email;
}
