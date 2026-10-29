<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Auth\Authenticatable;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Orm\Orm;
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
 *
 * Given a SentWebhooks store, each event is kept as it is queued, in its
 * tenant, so that resend() can send it again: a new delivery of the same
 * event and data, under a new id and so a new nonce -- a receiver that
 * already handled the first is told it is new, which a resend is for --
 * recorded with the delivery it repeats and who asked. Asking is the
 * application's to authorize; the record is the audit trail.
 */
final readonly class OutboundWebhooks
{
    /**
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param SentWebhooks|null $sent where each event is kept to be sent again; with the Orm, whose tenant it is kept in
     */
    public function __construct(
        private Dispatcher $jobs,
        private Encoder $encoder = new Encoder(),
        private ?\Closure $clock = null,
        private ?SentWebhooks $sent = null,
        private ?Orm $orm = null,
    ) {
        if (($sent === null) !== ($orm === null)) {
            throw new \InvalidArgumentException('Sent events are kept in the tenant in force: give the store and the Orm together.');
        }
    }

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

        return $this->queue($subscription, $event, $this->encoder->encode($data));
    }

    /**
     * Sends a delivery's event again, as a new delivery: found in the
     * tenant in force, so another tenant's id finds nothing, and kept with
     * the delivery it repeats and $by, who asked.
     *
     * @return string|null the new delivery's id; null when there is no such event to send again
     */
    public function resend(string $delivery, Authenticatable $by): ?string
    {
        if ($this->sent === null || $this->orm === null) {
            throw new \LogicException('A resend reads the event back from the store it was kept in: give OutboundWebhooks a SentWebhooks.');
        }

        $sent = $this->sent->find($this->orm->tenant(), $delivery);
        if ($sent === null) {
            return null;
        }
        // Objects kept as objects, so `{}` is sent again as `{}` and not `[]`.
        $data = json_decode($sent->data, false, 512, JSON_THROW_ON_ERROR);

        return $this->queue($sent->subscription, $sent->event, $data, $sent->delivery, $by->authIdentifier());
    }

    /**
     * Queues a delivery of $event with $data, already encoded, and keeps it.
     *
     * @return string the delivery's id
     */
    private function queue(string $subscription, string $event, mixed $data, ?string $resendOf = null, ?string $resentBy = null): string
    {
        $delivery = Uuid::v7();
        $now = $this->clock === null ? time() : ($this->clock)();
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $body = json_encode([
            'id' => $delivery,
            'event' => $event,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'data' => $data,
        ], $flags);
        if (\strlen($body) > WebhookEndpoint::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException("The '{$event}' delivery is over " . WebhookEndpoint::MAX_BODY_BYTES . ' bytes, more than a receiver is sent.');
        }

        if ($this->sent !== null && $this->orm !== null) {
            $this->sent->keep(new SentWebhook($delivery, $this->orm->tenant(), $subscription, $event, json_encode($data, $flags), $now, $resendOf, $resentBy));
        }
        $this->jobs->dispatch(new DeliverWebhook($subscription, $delivery, $event, $body));

        return $delivery;
    }
}
