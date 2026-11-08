<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * Subscriptions that keep count of the deliveries to each that failed in a
 * row, and stop sending to one that keeps failing (ADR 0197):
 * DatabaseWebhookSubscriptions. DeliverWebhook tells a WebhookSubscriptions
 * that implements this how each delivery ended, in the tenant it ran as.
 *
 * A delivery is counted once, when it ends: delivered, or undelivered on
 * its last try, or ended at once -- a destination refused, a receiver that
 * answered 410. A try that will be tried again counts for nothing yet.
 */
interface CountsDeliveryFailures
{
    /** A delivery to $subscription arrived: its failures in a row start again from none. */
    public function deliveryArrived(string $subscription): void;

    /**
     * A delivery to $subscription ended undelivered.
     *
     * @param string $failure what its last try met, as a code a tenant may be shown: `status_503`,
     *        `timeout`, `destination_refused`, `receiver_gone`
     * @param bool $receiverGone the receiver answered 410: asked to be sent nothing more
     */
    public function deliveryFailed(string $subscription, string $failure, bool $receiverGone = false): void;
}
