<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * An event the application sent to a subscription, kept so it can be sent
 * again: its data as the Encoder wrote it, never a secret or a signature,
 * and -- for a resend -- the delivery it repeats and who asked for it,
 * which is the resend's audit record.
 */
final readonly class SentWebhook
{
    public function __construct(
        public string $delivery,
        public string $tenant,
        public string $subscription,
        public string $event,
        /** The event's data, JSON, as the body carried it. */
        public string $data,
        public int $sentAt,
        /** The delivery this one sends again, for a resend. */
        public ?string $resendOf = null,
        /** Who asked for the resend: an account's identifier. */
        public ?string $resentBy = null,
    ) {}
}
