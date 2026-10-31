<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** A message the reader's mail server accepted: delivered, as far as any sender can know. */
final readonly class MailDelivered implements MailEvent
{
    public string $recipient;

    public function __construct(
        public string $provider,
        string $recipient,
        public ?string $messageId,
        public int $at,
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
