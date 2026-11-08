<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * A subscription just made, or its secret just rotated, with the secret
 * (ADR 0197): in the answer to the action that made it, for the page to
 * show once, and nowhere else. Never dumped or encoded with it.
 */
final readonly class IssuedWebhookSecret implements \JsonSerializable
{
    public function __construct(
        public StoredWebhookSubscription $subscription,
        #[\SensitiveParameter]
        public string $secret,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['subscription' => $this->subscription, 'secret' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['subscription' => $this->subscription->id, 'secret' => '[redacted]'];
    }
}
