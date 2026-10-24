<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * Subscriptions the application is built with, the same in every tenant:
 * for an application with none or one -- every tenant's deliveries would
 * go to the same place -- and for tests.
 */
final readonly class StaticSubscriptions implements WebhookSubscriptions
{
    /** @param array<string, WebhookSubscription> $subscriptions by id */
    public function __construct(private array $subscriptions) {}

    public function find(string $subscription): ?WebhookSubscription
    {
        return $this->subscriptions[$subscription] ?? null;
    }
}
