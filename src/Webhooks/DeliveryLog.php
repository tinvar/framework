<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * The record of every try at every outbound delivery: what a tenant's
 * deliveries page reads, and how a delivery knows which try it is on.
 */
interface DeliveryLog
{
    public function record(DeliveryAttempt $attempt): void;

    /** @return list<DeliveryAttempt> one delivery's tries, first first */
    public function attempts(string $delivery): array;

    /** @return list<DeliveryAttempt> a tenant's latest tries, newest first */
    public function recent(string $tenant, int $limit = 50): array;

    /**
     * Forgets up to $limit tries made before $before, every tenant's; how
     * many it forgot (PruneSentWebhooks).
     */
    public function prune(int $before, int $limit): int;
}
