<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A reader who marked a message as spam, as their mailbox provider told
 * the sender's (a feedback loop). The suppression list takes it: no more
 * marketing to that address.
 *
 * $ambiguous: the provider named several readers and did not say which
 * one complained, so the suppression list does not take it (MailBounced).
 */
final readonly class MailComplained implements MailEvent
{
    public string $recipient;

    /**
     * @param string|null $reason the complaint's feedback type, where the provider says it: `abuse`, `not-spam`...
     * @param bool $ambiguous one of several readers the provider named together: not suppressed
     */
    public function __construct(
        public string $provider,
        string $recipient,
        public ?string $messageId,
        public int $at,
        public ?string $reason = null,
        public bool $ambiguous = false,
    ) {
        $this->recipient = strtolower($recipient);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function at(): int
    {
        return $this->at;
    }
}
