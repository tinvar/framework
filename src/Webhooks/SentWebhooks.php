<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * The events OutboundWebhooks sent, by delivery, each in its tenant: what
 * a resend reads the event back from, and the record of every resend --
 * which delivery, by whom, when.
 *
 * Every read names the tenant, so a delivery's id from a form finds
 * nothing of another tenant's.
 */
interface SentWebhooks
{
    public function keep(SentWebhook $sent): void;

    public function find(string $tenant, string $delivery): ?SentWebhook;

    /**
     * The events of $deliveries kept in $tenant, by delivery: one read for
     * a page listing them.
     *
     * @param list<string> $deliveries
     * @return array<string, SentWebhook>
     */
    public function many(string $tenant, array $deliveries): array;

    /**
     * The resends of $deliveries in $tenant, by the delivery each repeats,
     * newest last.
     *
     * @param list<string> $deliveries
     * @return array<string, list<SentWebhook>>
     */
    public function resendsOf(string $tenant, array $deliveries): array;

    /** Forgets up to $limit events sent before $before; how many it forgot. */
    public function prune(int $before, int $limit): int;
}
