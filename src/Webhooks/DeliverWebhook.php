<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRefused;
use Tinvar\Orm\Orm;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\Target;

/**
 * One outbound webhook delivery, POSTed from a worker through OutboundHttp
 * and signed as the inbound side verifies (WebhookSignature, ADR 0033):
 * an HMAC-SHA256 over the timestamp, the nonce, the method, the path and
 * query, and the body, with the subscription's secret.
 *
 * Each try is signed afresh -- a new timestamp, so a retry an hour later
 * is inside the receiver's window -- under the same nonce, the delivery's
 * id, which is also the body's `id`: a receiver that saw a try whose
 * answer was lost refuses the next as a replay within its window, and can
 * keep the id to recognise one after it.
 *
 * The subscription is found when the try runs, in the tenant that sent
 * the event (the job runs as it), and its secret never travels in the
 * payload: a queue row is readable by whoever can read the database.
 *
 * Every try is recorded with its status and timing, never the body, which
 * is not even read (readBody: false). A 2xx is delivered, and so is a 409,
 * a receiver refusing the nonce of a try it already has; anything else,
 * or a connection that fails, is tried again with the backoff below; a
 * destination the policy refuses, a 410, or a subscription that is gone
 * ends the delivery at once, since trying again changes none of those.
 */
#[Queue(self::QUEUE, tries: 6, backoff: [30, 120, 600, 1800, 3600], timeout: 30)]
final readonly class DeliverWebhook implements Job
{
    public const string QUEUE = 'webhooks';

    /** Seconds one try may take: well inside the job's own timeout. */
    public const float TIMEOUT = 10.0;

    public function __construct(
        public string $subscription,
        public string $delivery,
        public string $event,
        public string $body,
    ) {}

    public function handle(OutboundHttp $http, WebhookSubscriptions $subscriptions, DeliveryLog $log, Orm $orm): void
    {
        $attempt = \count($log->attempts($this->delivery)) + 1;
        $record = function (string $outcome, ?int $status, ?string $reason, int $milliseconds) use ($log, $orm, $attempt): void {
            $log->record(new DeliveryAttempt(
                $this->delivery,
                $this->subscription,
                $this->event,
                $orm->tenant(),
                $attempt,
                $outcome,
                $status,
                $reason,
                $milliseconds,
                time(),
            ));
        };

        $subscription = $subscriptions->find($this->subscription);
        if ($subscription === null) {
            // Deleted since the event: there is no one to deliver it to.
            $record(DeliveryAttempt::GONE, null, 'subscription_gone', 0);

            return;
        }

        try {
            $target = Target::parse($subscription->url);
            $response = $http->send(new OutboundRequest(
                'POST',
                $subscription->url,
                [
                    'content-type' => 'application/json',
                    WebhookSignature::HEADER => WebhookSignature::header(
                        $subscription->secret,
                        time(),
                        $this->delivery,
                        'POST',
                        $target->requestTarget(),
                        $this->body,
                    ),
                ],
                $this->body,
                timeout: self::TIMEOUT,
                readBody: false,
            ));
        } catch (OutboundRefused $e) {
            // Refused, and not why: that a name the tenant chose resolves to
            // a loopback, private or metadata address is the network's to
            // know. The reason is in the failed job, for the operator.
            $record(DeliveryAttempt::REFUSED, null, null, 0);

            throw new JobRefused('destination_refused', "Webhook delivery {$this->delivery} is refused: {$e->getMessage()}", $e);
        } catch (OutboundFailed $e) {
            $record(DeliveryAttempt::FAILED, $e->status, $e->reason, $e->milliseconds);

            throw $e;
        }

        if ($response->successful()) {
            $record(DeliveryAttempt::DELIVERED, $response->status, null, $response->milliseconds);

            return;
        }
        // A replay refused (WebhookEndpoint's answer to a nonce it has
        // seen): an earlier try reached the receiver and its answer was
        // lost. Delivered, then -- tried again, the tries went on past the
        // receiver's window, and the next was handled a second time.
        if ($response->status === 409) {
            $record(DeliveryAttempt::DELIVERED, $response->status, 'already_received', $response->milliseconds);

            return;
        }
        if ($response->status === 410) {
            $record(DeliveryAttempt::GONE, $response->status, 'receiver_gone', $response->milliseconds);

            throw new JobRefused('receiver_gone', "Webhook delivery {$this->delivery} was answered 410: the receiver asked to be sent nothing more.");
        }

        $record(DeliveryAttempt::FAILED, $response->status, 'status', $response->milliseconds);

        throw new \RuntimeException("Webhook delivery {$this->delivery} ({$this->event}) was answered {$response->status}.");
    }
}
