<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/** Sent events in memory: DatabaseSentWebhooks' fake. */
final class ArraySentWebhooks implements SentWebhooks
{
    /** @var array<string, SentWebhook> by delivery */
    public array $sent = [];

    public function keep(SentWebhook $sent): void
    {
        $this->sent[$sent->delivery] = $sent;
    }

    public function find(string $tenant, string $delivery): ?SentWebhook
    {
        $sent = $this->sent[$delivery] ?? null;

        return $sent !== null && $sent->tenant === $tenant ? $sent : null;
    }

    public function many(string $tenant, array $deliveries): array
    {
        $found = [];
        foreach ($deliveries as $delivery) {
            $sent = $this->find($tenant, $delivery);
            if ($sent !== null) {
                $found[$delivery] = $sent;
            }
        }

        return $found;
    }

    public function resendsOf(string $tenant, array $deliveries): array
    {
        $resends = [];
        foreach ($this->sent as $sent) {
            if ($sent->tenant === $tenant && $sent->resendOf !== null && \in_array($sent->resendOf, $deliveries, true)) {
                $resends[$sent->resendOf][] = $sent;
            }
        }

        return $resends;
    }

    public function prune(int $before, int $limit): int
    {
        $pruned = 0;
        foreach ($this->sent as $delivery => $sent) {
            if ($pruned < $limit && $sent->sentAt < $before) {
                unset($this->sent[$delivery]);
                $pruned++;
            }
        }

        return $pruned;
    }
}
