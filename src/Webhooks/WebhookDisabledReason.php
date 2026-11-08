<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/** Why the framework disabled a subscription (ADR 0197), kept beside it and shown on its page. */
enum WebhookDisabledReason: string
{
    /** As many deliveries in a row as the store allows ended undelivered. */
    case ConsecutiveFailures = 'consecutive_failures';

    /** The receiver answered 410: it asked to be sent nothing more. */
    case ReceiverGone = 'receiver_gone';

    public function describe(): string
    {
        return match ($this) {
            self::ConsecutiveFailures => 'Too many deliveries in a row failed.',
            self::ReceiverGone => 'The receiver answered 410 Gone: it asked to be sent nothing more.',
        };
    }
}
