<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Support\Uuid;

/** Push subscriptions in memory, for tests: DatabasePushSubscriptions' rules without its table. */
final class ArrayPushSubscriptions implements PushSubscriptions
{
    /** @var array<string, PushSubscription> by id */
    public array $subscriptions = [];

    public function __construct(private readonly PushServices $services = new PushServices()) {}

    public function subscribe(string $tenant, string $user, string $epoch, string $endpoint, string $p256dh, string $auth): PushSubscription
    {
        if ($user === '') {
            throw new \InvalidArgumentException('A push subscription is held by a user.');
        }
        if (!$this->services->allows($endpoint)) {
            throw new \InvalidArgumentException('A push endpoint is at a push service the application names.');
        }
        $subscription = new PushSubscription(Uuid::v7(), $tenant, $user, $epoch, $endpoint, $p256dh, $auth, time());
        foreach ($this->subscriptions as $id => $held) {
            if ($held->tenant === $tenant && $held->endpoint === $endpoint) {
                unset($this->subscriptions[$id]);
            }
        }
        $this->subscriptions[$subscription->id] = $subscription;

        return $subscription;
    }

    public function unsubscribe(string $tenant, string $user, string $endpoint): bool
    {
        foreach ($this->subscriptions as $id => $held) {
            if ($held->tenant === $tenant && $held->user === $user && $held->endpoint === $endpoint) {
                unset($this->subscriptions[$id]);

                return true;
            }
        }

        return false;
    }

    public function forUser(string $tenant, string $user): array
    {
        return array_values(array_filter($this->subscriptions, static fn (PushSubscription $s): bool => $s->tenant === $tenant && $s->user === $user));
    }

    public function find(string $tenant, string $id): ?PushSubscription
    {
        $held = $this->subscriptions[$id] ?? null;

        return $held !== null && $held->tenant === $tenant ? $held : null;
    }

    public function remove(string $tenant, string $id): void
    {
        if ($this->find($tenant, $id) !== null) {
            unset($this->subscriptions[$id]);
        }
    }
}
