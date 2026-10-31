<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A transport that says what became of a send: the provider's id for the
 * message, and its own name (SentMessage).
 *
 * Transport stays as it was -- send(), returning nothing -- so a transport
 * written against it still is one. One that can say more implements this
 * as well, and its send() is deliver() with the answer dropped. The Mailer
 * asks a Delivers transport for the answer and hands it back; any other it
 * sends through as before (SentMessage::through()).
 */
interface Delivers extends Transport
{
    /** @throws MailTransportException retryable or not, as the refusal says */
    public function deliver(Message $message): SentMessage;
}
