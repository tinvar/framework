<?php

declare(strict_types=1);

namespace Tinvar\Push;

/**
 * The push subscriptions the application keeps, each bound to the user who
 * made it, the auth epoch they were on, and the tenant they made it in.
 *
 * Every read and write names the tenant, and every one about a user names
 * the user: a subscription is found for a push only in the tenant the push
 * is sent in and only for the user it is to, and removed only by the user
 * who holds it. One browser's endpoint is held once per tenant -- a browser
 * subscribed again, by whoever is signed in on it now, is theirs alone,
 * so a shared machine does not go on receiving the last user's pushes.
 *
 * The epoch is the one client-held data is bound to (AuthContext), and it
 * binds a subscription the same way (ADR 0124): PushNotifications and
 * SendPush send only to a subscription made at the epoch its user is on
 * now, and remove one made at any other. So whatever moves the epoch --
 * sign-out, where the application moves it there, a role change,
 * impersonation, "sign out everywhere" -- stops every push to every browser
 * subscribed before it, with nothing for the application to unsubscribe.
 * A browser the person is still signed in on subscribes again on its next
 * page (the endpoint is the same; subscribe() replaces the row), at the
 * epoch it is on now.
 */
interface PushSubscriptions
{
    /**
     * Keeps $endpoint for $user at $epoch in $tenant, replacing whatever
     * held that endpoint in that tenant before.
     *
     * @param string $epoch the user's auth epoch now, as AuthContext::epoch() has it
     * @throws \InvalidArgumentException for an endpoint or keys that are not a subscription's
     */
    public function subscribe(string $tenant, string $user, string $epoch, string $endpoint, string $p256dh, string $auth): PushSubscription;

    /** Removes $user's subscription at $endpoint in $tenant; true when there was one. */
    public function unsubscribe(string $tenant, string $user, string $endpoint): bool;

    /** @return list<PushSubscription> $user's subscriptions in $tenant, at any epoch, oldest first */
    public function forUser(string $tenant, string $user): array;

    public function find(string $tenant, string $id): ?PushSubscription;

    /** Removes a subscription its push service says is gone, or that an epoch has passed by. */
    public function remove(string $tenant, string $id): void;
}
