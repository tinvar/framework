<?php

declare(strict_types=1);

namespace Tinvar\Push;

/**
 * The push subscriptions the application keeps, each bound to the user who
 * made it and the tenant they made it in.
 *
 * Every read and write names the tenant, and every one about a user names
 * the user: a subscription is found for a push only in the tenant the push
 * is sent in and only for the user it is to, and removed only by the user
 * who holds it. One browser's endpoint is held once per tenant -- a browser
 * subscribed again, by whoever is signed in on it now, is theirs alone,
 * so a shared machine does not go on receiving the last user's pushes.
 */
interface PushSubscriptions
{
    /**
     * Keeps $endpoint for $user in $tenant, replacing whatever held that
     * endpoint in that tenant before.
     *
     * @throws \InvalidArgumentException for an endpoint or keys that are not a subscription's
     */
    public function subscribe(string $tenant, string $user, string $endpoint, string $p256dh, string $auth): PushSubscription;

    /** Removes $user's subscription at $endpoint in $tenant; true when there was one. */
    public function unsubscribe(string $tenant, string $user, string $endpoint): bool;

    /** @return list<PushSubscription> $user's subscriptions in $tenant, oldest first */
    public function forUser(string $tenant, string $user): array;

    public function find(string $tenant, string $id): ?PushSubscription;

    /** Removes a subscription its push service says is gone. */
    public function remove(string $tenant, string $id): void;
}
