<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Attributes\Queue;
use Tinvar\Config\WebhookSignatureHeader;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\Rate;
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
 *
 * A subscription that is paused (WebhookSubscription::$active) ends the
 * delivery unsent. While its secret is rotated (ADR 0197) the signature
 * header is made with the secret being replaced, exactly as it always was,
 * and the new secret's goes beside it in the header's -Next. Both are
 * named as the application chose (WebhookSignatureHeader, ADR 0200):
 * `Tinvar-Signature` unless TINVAR_WEBHOOK_SIGNATURE_HEADER says. And
 * where the subscriptions keep count (CountsDeliveryFailures), each delivery
 * is counted as it ends -- a refusal or a 410 here, a last try that failed
 * in failed(), which the worker calls once it gives up on the job, however
 * the try ended -- so one that keeps failing is disabled.
 *
 * Every tenant's deliveries share the `webhooks` queue: give it
 * tenantRate() among the console's rates, so one tenant's flood waits on
 * its own starts and never on another's.
 */
#[Queue(self::QUEUE, tries: self::TRIES, backoff: [30, 120, 600, 1800, 3600], timeout: 30)]
final readonly class DeliverWebhook implements Job
{
    public const string QUEUE = 'webhooks';

    /** Tries a delivery has before it ends undelivered. */
    public const int TRIES = 6;

    /** Deliveries one tenant may start a minute, under tenantRate(). */
    public const int TENANT_RUNS = 120;

    /** Seconds one try may take: well inside the job's own timeout. */
    public const float TIMEOUT = 10.0;

    public function __construct(
        public string $subscription,
        public string $delivery,
        public string $event,
        public string $body,
    ) {}

    /**
     * The rate the `webhooks` queue is held to per tenant (ADR 0118, 0197):
     *
     *     new Console(..., rates: [DeliverWebhook::QUEUE => DeliverWebhook::tenantRate()]);
     */
    public static function tenantRate(): Rate
    {
        return Rate::perTenant(runs: self::TENANT_RUNS, per: 60);
    }

    public function handle(OutboundHttp $http, WebhookSubscriptions $subscriptions, DeliveryLog $log, Orm $orm, WebhookSignatureHeader $header): void
    {
        // Counted in the tenant the job runs as, which its payload seals.
        $attempt = \count($log->attempts($orm->tenant(), $this->delivery)) + 1;
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
        if (!$subscription->active) {
            // Paused, or disabled, since the event: nothing is sent to it.
            $record(DeliveryAttempt::GONE, null, 'subscription_paused', 0);

            return;
        }
        $counts = $subscriptions instanceof CountsDeliveryFailures ? $subscriptions : null;

        try {
            $target = Target::parse($subscription->url);
            $response = $http->send(new OutboundRequest(
                'POST',
                $subscription->url,
                [
                    'content-type' => 'application/json',
                    ...$this->signatures($subscription, $header, $target->requestTarget(), time()),
                ],
                $this->body,
                timeout: self::TIMEOUT,
                readBody: false,
                // The tenant chose the URL: the private addresses the
                // application opened for its own services are not its to
                // reach (ADR 0038).
                publicOnly: true,
            ));
        } catch (OutboundRefused $e) {
            // Refused, and not why: that a name the tenant chose resolves to
            // a loopback, private or metadata address is the network's to
            // know. The reason is in the failed job, for the operator.
            $record(DeliveryAttempt::REFUSED, null, null, 0);
            $counts?->deliveryFailed($this->subscription, 'destination_refused');

            throw new JobRefused('destination_refused', "Webhook delivery {$this->delivery} is refused: {$e->getMessage()}", $e);
        } catch (OutboundFailed $e) {
            $record(DeliveryAttempt::FAILED, $e->status, $e->reason, $e->milliseconds);

            throw $e;
        }

        if ($response->successful()) {
            $record(DeliveryAttempt::DELIVERED, $response->status, null, $response->milliseconds);
            $counts?->deliveryArrived($this->subscription);

            return;
        }
        // A replay refused (WebhookEndpoint's answer to a nonce it has
        // seen): an earlier try reached the receiver and its answer was
        // lost. Delivered, then -- tried again, the tries went on past the
        // receiver's window, and the next was handled a second time.
        if ($response->status === 409) {
            $record(DeliveryAttempt::DELIVERED, $response->status, 'already_received', $response->milliseconds);
            $counts?->deliveryArrived($this->subscription);

            return;
        }
        if ($response->status === 410) {
            $record(DeliveryAttempt::GONE, $response->status, 'receiver_gone', $response->milliseconds);
            $counts?->deliveryFailed($this->subscription, 'receiver_gone', receiverGone: true);

            throw new JobRefused('receiver_gone', "Webhook delivery {$this->delivery} was answered 410: the receiver asked to be sent nothing more.");
        }

        $record(DeliveryAttempt::FAILED, $response->status, 'status', $response->milliseconds);

        throw new \RuntimeException("Webhook delivery {$this->delivery} ({$this->event}) was answered {$response->status}.");
    }

    /**
     * The worker's last word, once its last try failed -- however it ended,
     * a try lost before it was logged among them: the delivery is counted
     * as undelivered, with what its last logged try met.
     */
    public function failed(WebhookSubscriptions $subscriptions, DeliveryLog $log, Orm $orm, \Throwable $error): void
    {
        if (!$subscriptions instanceof CountsDeliveryFailures) {
            return;
        }
        $tries = $log->attempts($orm->tenant(), $this->delivery);
        $last = $tries === [] ? null : $tries[\count($tries) - 1];
        $failure = match (true) {
            $last === null => 'failed',
            $last->reason === 'status' && $last->status !== null => "status_{$last->status}",
            default => $last->reason ?? 'failed',
        };
        $subscriptions->deliveryFailed($this->subscription, $failure);
    }

    /**
     * The signature header, under the name the application chose (ADR
     * 0200), made with the secret every receiver holds, and while a
     * rotation overlaps the new secret's beside it, under that name's -Next.
     *
     * @return array<string, string>
     */
    private function signatures(WebhookSubscription $subscription, WebhookSignatureHeader $header, string $target, int $at): array
    {
        $headers = [$header->name => WebhookSignature::header($subscription->signingSecret(), $at, $this->delivery, 'POST', $target, $this->body)];
        $next = $subscription->nextSecret();
        if ($next !== null) {
            $headers[$header->next] = WebhookSignature::header($next, $at, $this->delivery, 'POST', $target, $this->body);
        }

        return $headers;
    }
}
