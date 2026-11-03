<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * What became of one send: the message, the id the transport that took it
 * gave it -- Resend's, SES's, Postmark's `MessageID`, Mailgun's `<...>` --
 * and which transport that was.
 *
 * The id is what a provider's later word about the message names: its
 * bounce, its complaint, its delivery (MailBounced and the rest). A
 * transport that gives none -- SMTP, the log -- leaves it null.
 *
 * $suppressed are the readers the Mailer left off because the suppression
 * list holds them (Suppressions). A message whose To readers were all
 * suppressed was not sent at all: it is $skipped, with no id, and its
 * transport is `none`.
 */
final readonly class SentMessage
{
    /**
     * @param string $transport the name of the transport that took it: `resend`, `ses`, `smtp`, `array`...
     * @param list<string> $suppressed the addresses left off, as the message named them
     */
    public function __construct(
        public Message $message,
        public ?string $messageId,
        public string $transport,
        public array $suppressed = [],
        public bool $skipped = false,
    ) {}

    /**
     * Sends $message through $transport: what a Delivers transport reports,
     * or -- for one that says nothing back -- the message, with no id, under
     * the transport's class name, lowercased and less the word Transport:
     * `smtp`, `log`, as their exceptions name them.
     */
    public static function through(Transport $transport, Message $message): self
    {
        if ($transport instanceof Delivers) {
            return $transport->deliver($message);
        }

        $transport->send($message);
        $name = substr($transport::class, (int) strrpos($transport::class, '\\') + 1);
        $name = str_ends_with($name, 'Transport') && $name !== 'Transport' ? substr($name, 0, -\strlen('Transport')) : $name;

        return new self($message, null, strtolower($name));
    }

    /**
     * The same send, with the readers the Mailer left off.
     *
     * @param list<string> $suppressed
     */
    public function withSuppressed(array $suppressed): self
    {
        return new self($this->message, $this->messageId, $this->transport, $suppressed, $this->skipped);
    }
}
