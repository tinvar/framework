<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Mail\Email;
use Tinvar\Mail\Mailable;

/**
 * A notification's email, built on the worker, handed to the Mailer to
 * send there and then. Never queued: what travels is the notification, and
 * the email -- with the unsubscribe token it carries -- is made where it is
 * sent.
 */
final readonly class NotificationEmail implements Mailable
{
    public function __construct(private Email $email) {}

    public function email(): Email
    {
        return $this->email;
    }
}
