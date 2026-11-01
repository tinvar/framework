<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A message that did not reach its reader: for good -- a hard bounce, the
 * address does not exist or refuses mail for good, which the suppression
 * list takes -- or for now, a soft bounce, a mailbox full or a server down,
 * which it does not.
 *
 * $ambiguous: the provider named several readers and did not say which
 * one the bounce was about -- Resend's `data.to` is the message's whole
 * To list -- so the suppression list does not take it, for any of them.
 * It is dispatched all the same, once for each reader named.
 */
final readonly class MailBounced implements MailEvent
{
    public string $recipient;

    /**
     * @param bool $permanent a hard bounce: suppressed from then on
     * @param string|null $reason the provider's diagnosis -- `5.1.1 user unknown` -- for whoever reads it
     * @param bool $ambiguous one of several readers the provider named together: not suppressed
     */
    public function __construct(
        public string $provider,
        string $recipient,
        public ?string $messageId,
        public int $at,
        public bool $permanent,
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
