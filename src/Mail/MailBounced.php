<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A message that did not reach its reader: for good -- a hard bounce, the
 * address does not exist or refuses mail for good, which the suppression
 * list takes -- or for now, a soft bounce, a mailbox full or a server down,
 * which it does not.
 */
final readonly class MailBounced implements MailEvent
{
    public string $recipient;

    /**
     * @param bool $permanent a hard bounce: suppressed from then on
     * @param string|null $reason the provider's diagnosis -- `5.1.1 user unknown` -- for whoever reads it
     */
    public function __construct(
        public string $provider,
        string $recipient,
        public ?string $messageId,
        public int $at,
        public bool $permanent,
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
