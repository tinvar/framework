<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * One try at delivering one webhook: what a tenant may be shown of it.
 *
 * tinvar-spec.md, "Outbound requests": "Tenants see only status codes and
 * timing for their deliveries, never response bodies." So this is all
 * there is: the outcome, the status the receiver answered with, a reason
 * code when it did not answer, and how long it took. No body, no response
 * header and no message is kept -- what a receiver says back stays with
 * the receiver. A refused destination keeps no reason either: which kind
 * of address a tenant's URL resolved to inside the network (loopback,
 * private, metadata) would let them map it, so that is in the failed job,
 * for the operator, alone.
 */
final readonly class DeliveryAttempt
{
    public const string DELIVERED = 'delivered';

    /** Not delivered this time; tried again unless it was the last try. */
    public const string FAILED = 'failed';

    /** The destination is one outbound requests may not reach; not tried again. */
    public const string REFUSED = 'refused';

    /** The subscription is gone, or the receiver answered 410; not tried again. */
    public const string GONE = 'gone';

    public function __construct(
        public string $delivery,
        public string $subscription,
        public string $event,
        public string $tenant,
        public int $attempt,
        public string $outcome,
        public ?int $status,
        public ?string $reason,
        public int $milliseconds,
        public int $attemptedAt,
    ) {}
}
