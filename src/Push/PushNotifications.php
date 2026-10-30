<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\UserLookup;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Orm\Orm;

/**
 * Pushes a message to every browser a user subscribed in the tenant in
 * force: one SendPush job each, queued as that tenant and -- inside a
 * transaction -- once it commits.
 *
 *     $push->send($customer, new OrderShippedPush($order->id, 'Your order is on its way'));
 *
 * The message is JSON the service worker's push event reads, written
 * through the Encoder as a render's data is, so a Data object goes as its
 * fields and a model is refused here rather than pushed with every column.
 * It is checked against the size one push carries before anything is
 * queued.
 *
 * Only a subscription made at the auth epoch the user is on now is queued
 * for (ADR 0124); one made at any other epoch -- before a sign-out, a role
 * change, "sign out everywhere" -- is removed instead, and SendPush asks
 * again when it runs.
 */
final readonly class PushNotifications
{
    public function __construct(
        private Dispatcher $jobs,
        private PushSubscriptions $subscriptions,
        private Orm $orm,
        private UserLookup $users,
        private Encoder $encoder = new Encoder(),
    ) {}

    /**
     * @param Data|array<array-key, mixed> $message
     * @return int the subscriptions queued for
     */
    public function send(Authenticatable $user, Data|array $message, int $ttl = 86_400, string $urgency = 'normal', ?string $topic = null): int
    {
        $payload = json_encode($this->encoder->encode($message), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (\strlen($payload) > PushEncryption::MAX_PAYLOAD) {
            throw new \InvalidArgumentException('A push message is at most ' . PushEncryption::MAX_PAYLOAD . ' bytes of JSON.');
        }
        if (!\in_array($urgency, WebPush::URGENCIES, true) || $ttl < 0 || $ttl > WebPush::MAX_TTL) {
            throw new \InvalidArgumentException('A push has an urgency WebPush names and a TTL of four weeks at most.');
        }

        $queued = 0;
        $tenant = $this->orm->tenant();
        $epoch = $this->users->epoch($user);
        foreach ($this->subscriptions->forUser($tenant, $user->authIdentifier()) as $subscription) {
            if (!hash_equals($epoch, $subscription->epoch)) {
                $this->subscriptions->remove($tenant, $subscription->id);

                continue;
            }
            $this->jobs->dispatch(new SendPush($subscription->id, $payload, $ttl, $urgency, $topic));
            $queued++;
        }

        return $queued;
    }
}
