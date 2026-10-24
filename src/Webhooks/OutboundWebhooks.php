<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Support\Uuid;

/**
 * Sends the application's events to the URLs tenants subscribed: each one
 * a DeliverWebhook job, queued as the tenant in force and -- inside a
 * transaction -- only once it commits, so a rolled-back order announces
 * nothing.
 *
 *     $webhooks->send('orders', 'order.placed', new OrderPlacedData($order));
 *
 * The body is JSON the framework writes, the same for every event:
 *
 *     {"id": "<delivery>", "event": "order.placed", "created_at": "2026-10-24T12:00:00Z", "data": {...}}
 *
 * The data goes through the Encoder, as a render's does, so a Data object
 * goes as its fields and a model -- every column, a password hash among
 * them -- is refused, here, not on a worker an hour later.
 */
final readonly class OutboundWebhooks
{
    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(
        private Dispatcher $jobs,
        private Encoder $encoder = new Encoder(),
        private ?\Closure $clock = null,
    ) {}

    /**
     * Queues $event for the subscription's URL.
     *
     * @param Data|array<array-key, mixed> $data
     * @return string the delivery's id
     */
    public function send(string $subscription, string $event, Data|array $data): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $subscription) !== 1) {
            throw new \InvalidArgumentException("'{$subscription}' is not a subscription id: letters, digits, '.', '_', ':' and '-', up to 128.");
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}\z/', $event) !== 1) {
            throw new \InvalidArgumentException("'{$event}' is not an event name: lowercase letters, digits, '.', '_' and '-', up to 64.");
        }

        $delivery = Uuid::v7();
        $body = json_encode([
            'id' => $delivery,
            'event' => $event,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $this->clock === null ? time() : ($this->clock)()),
            'data' => $this->encoder->encode($data),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (\strlen($body) > WebhookEndpoint::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException("The '{$event}' delivery is over " . WebhookEndpoint::MAX_BODY_BYTES . ' bytes, more than a receiver is sent.');
        }

        $this->jobs->dispatch(new DeliverWebhook($subscription, $delivery, $event, $body));

        return $delivery;
    }
}
