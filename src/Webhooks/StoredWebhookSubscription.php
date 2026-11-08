<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Carbon\CarbonImmutable;

/**
 * One of a tenant's stored webhook subscriptions (ADR 0197), as its page
 * lists it: never its secret, which is shown once -- when it is made, and
 * when it is rotated -- and is not kept anywhere to be read back.
 */
final readonly class StoredWebhookSubscription
{
    /** @param list<string> $events the event names it is sent */
    public function __construct(
        public string $id,
        public string $tenant,
        public string $url,
        public string $description,
        public array $events,
        public WebhookSubscriptionStatus $status,
        /** Which of its secrets signs: the first is 1, and each rotation adds one. */
        public int $secretVersion,
        /** Until when the secret it replaced signs too; null when none does. */
        public ?CarbonImmutable $previousSecretUntil,
        /** Deliveries in a row that ended undelivered. */
        public int $consecutiveFailures,
        /** What the last undelivered delivery met: `status_503`, `timeout`, `destination_refused`. */
        public ?string $lastFailure,
        public ?WebhookDisabledReason $disabledReason,
        public ?CarbonImmutable $disabledAt,
        /** Who made it: an account's identifier. */
        public string $createdBy,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}

    public function active(): bool
    {
        return $this->status === WebhookSubscriptionStatus::Active;
    }

    public function subscribesTo(string $event): bool
    {
        return \in_array($event, $this->events, true);
    }

    /** Whether the secret it replaced still signs at $now. */
    public function overlapping(CarbonImmutable $now): bool
    {
        return $this->previousSecretUntil !== null && $now->lessThan($this->previousSecretUntil);
    }
}
