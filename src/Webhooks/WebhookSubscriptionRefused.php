<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * A subscription change DatabaseWebhookSubscriptions will not make (ADR
 * 0197): its message is words a tenant may be shown, and `field` the
 * part of the form it is about -- `url`, `events`, `description` -- or
 * `limit` and `conflict` for the whole.
 */
final class WebhookSubscriptionRefused extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
