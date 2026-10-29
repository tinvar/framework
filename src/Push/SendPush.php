<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRefused;
use Tinvar\Orm\Orm;
use Tinvar\Outbound\OutboundRefused;

/**
 * One push message to one subscription, sent from a worker as the tenant it
 * was sent in.
 *
 * The subscription is found when the try runs, in that tenant: one removed
 * since -- the user unsubscribed, or signed out of that browser -- is sent
 * nothing. Its keys never travel in the payload; the message does, as
 * plaintext in the queue's row until it is sent, as a webhook's body does.
 *
 * A 2xx is delivered. A 404 or 410 is the push service saying the
 * subscription is gone, and it is removed; so is one whose endpoint is
 * refused, which no retry will change. A 429 or a 5xx, or a connection
 * that fails, is tried again with backoff; any other answer -- a 400, a
 * 403 for a key the service does not take, a 413 -- ends it.
 */
#[Queue(self::QUEUE, tries: 5, backoff: [30, 120, 600, 1800], timeout: 30)]
final readonly class SendPush implements Job
{
    public const string QUEUE = 'push';

    public function __construct(
        public string $subscription,
        public string $payload,
        public int $ttl = 86_400,
        public string $urgency = 'normal',
        public ?string $topic = null,
    ) {}

    public function handle(WebPush $push, PushSubscriptions $subscriptions, Orm $orm): void
    {
        $tenant = $orm->tenant();
        $subscription = $subscriptions->find($tenant, $this->subscription);
        if ($subscription === null) {
            return;
        }

        try {
            $response = $push->send($subscription, $this->payload, $this->ttl, $this->urgency, $this->topic);
        } catch (OutboundRefused $e) {
            $subscriptions->remove($tenant, $subscription->id);

            throw new JobRefused('destination_refused', "Push subscription {$subscription->id} is refused and was removed: {$e->getMessage()}", $e);
        }

        if ($response->successful()) {
            return;
        }
        if ($response->status === 404 || $response->status === 410) {
            $subscriptions->remove($tenant, $subscription->id);

            return;
        }
        if ($response->status === 429 || $response->status >= 500) {
            throw new \RuntimeException("Push to subscription {$subscription->id} was answered {$response->status}.");
        }

        throw new JobRefused('push_refused', "Push to subscription {$subscription->id} was answered {$response->status}.");
    }
}
