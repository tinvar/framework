<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * The application's webhook subscriptions, found by id in the tenant in
 * force: a delivery runs as the tenant that sent it (ADR 0031), so one
 * tenant's events are looked up among that tenant's subscriptions alone.
 *
 * Asked when each delivery is tried, not when it is queued: a subscription
 * deleted in between stops the tries, and one moved to another URL gets
 * the retries there.
 */
interface WebhookSubscriptions
{
    public function find(string $subscription): ?WebhookSubscription;
}
