<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/** Delivery attempts in memory: the log's fake, and a real one in one process. */
final class ArrayDeliveryLog implements DeliveryLog
{
    /** @var list<DeliveryAttempt> */
    public array $recorded = [];

    public function record(DeliveryAttempt $attempt): void
    {
        $this->recorded[] = $attempt;
    }

    public function attempts(string $delivery): array
    {
        return array_values(array_filter($this->recorded, static fn (DeliveryAttempt $a): bool => $a->delivery === $delivery));
    }

    public function recent(string $tenant, int $limit = 50): array
    {
        $mine = array_values(array_filter($this->recorded, static fn (DeliveryAttempt $a): bool => $a->tenant === $tenant));

        return \array_slice(array_reverse($mine), 0, max(0, $limit));
    }

    public function forSubscription(string $tenant, string $subscription, int $limit = 50): array
    {
        $mine = array_values(array_filter($this->recorded, static fn (DeliveryAttempt $a): bool => $a->tenant === $tenant && $a->subscription === $subscription));

        return \array_slice(array_reverse($mine), 0, max(0, $limit));
    }

    public function prune(int $before, int $limit): int
    {
        $pruned = 0;
        $kept = [];
        foreach ($this->recorded as $attempt) {
            if ($pruned < $limit && $attempt->attemptedAt < $before) {
                $pruned++;
            } else {
                $kept[] = $attempt;
            }
        }
        $this->recorded = $kept;

        return $pruned;
    }
}
