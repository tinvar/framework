<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * Which of the tenant in force's subscriptions an event goes to (ADR
 * 0197): what OutboundWebhooks::publish() sends one delivery to each of.
 * DatabaseWebhookSubscriptions answers with the active ones that chose it.
 */
interface WebhookSubscribers
{
    /** @return list<string> the subscriptions' ids */
    public function subscribedTo(string $event): array;
}
