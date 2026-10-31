<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A webhook delivery that verified: the nonce that makes it this delivery
 * and no other -- the provider's own id where it signs one, a digest of
 * what it signed where it does not -- until when a replay of it would still
 * verify, and what it reports.
 */
final readonly class MailWebhookDelivery
{
    /** @var list<MailEvent> */
    public array $events;

    /**
     * @param string $nonce at most 128 bytes, as NonceStore keeps them
     * @param int $expiresAt seconds since the epoch: past it, the delivery no longer verifies anyway
     * @param list<MailEvent> $events
     */
    public function __construct(public string $nonce, public int $expiresAt, array $events)
    {
        if ($nonce === '' || \strlen($nonce) > 128) {
            throw new \InvalidArgumentException('A webhook delivery\'s nonce is 1 to 128 bytes.');
        }
        $this->events = $events;
    }
}
