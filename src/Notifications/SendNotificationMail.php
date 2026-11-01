<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Mail\SendMail;

/**
 * A notification's email, on the mail queue with every other send and tried
 * as one is: a provider that is down is tried again with backoff, one that
 * refuses the message fails it at once. Its own job, so a retry of the mail
 * never writes the inbox entry or queues the pushes again.
 */
#[Queue(SendMail::QUEUE, tries: 5, backoff: [10, 60, 300, 900], timeout: 60)]
final readonly class SendNotificationMail implements Job
{
    public function __construct(public string $user, public MailNotification $notification) {}

    public function handle(NotificationDelivery $delivery): void
    {
        $delivery->mail($this->user, $this->notification);
    }
}
