<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A reader who marked a message as spam, as their mailbox provider told
 * the sender's (a feedback loop). The suppression list takes it: no more
 * marketing to that address.
 */
final readonly class MailComplained implements MailEvent
{
    public string $recipient;

    /** @param string|null $reason the complaint's feedback type, where the provider says it: `abuse`, `not-spam`... */
    public function __construct(
        public string $provider,
        string $recipient,
        public ?string $messageId,
        public int $at,
        public ?string $reason = null,
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
