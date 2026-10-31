<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * What a mail provider reported about a message it sent, read from its
 * webhook and verified (MailEventsEndpoint): MailBounced, MailComplained or
 * MailDelivered, the same whichever provider said it.
 *
 * Dispatched on the application's event bus, for its listeners -- a
 * customer's record marked undeliverable, a newsletter's numbers -- after
 * the suppression list has had the bounce or complaint written to it.
 *
 * $messageId is the provider's id for the message, as SentMessage had it
 * when it was sent; $recipient the address it was about, lowercased; $at
 * when the provider says it happened.
 */
interface MailEvent
{
    /** `resend`, `ses`, `postmark`, `mailgun` or `sendgrid`. */
    public function provider(): string;

    public function recipient(): string;

    public function messageId(): ?string;

    public function at(): int;
}
