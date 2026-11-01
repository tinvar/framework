<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * An inbound webhook: a path that a sender POSTs signed deliveries to.
 *
 *     #[Webhook('/webhooks/carrier'), TenantFrom(Signature::class)]
 *     final class CarrierUpdates { public function __invoke(WebhookCall $call): void { ... } }
 *
 * Nothing in the class runs until the delivery's signature has verified,
 * its timestamp is within tolerance and its nonce has not been seen (see
 * Webhooks\WebhookEndpoint). `#[TenantFrom(Signature::class)]` reads the
 * tenant from the signed URL the sender was given, as for any entry point
 * reached with no session behind it.
 *
 * A sender that signs in its own way -- Stripe's `Stripe-Signature`, say --
 * names the class that checks it, a Webhooks\WebhookVerifier, which is
 * asked in place of Tinvar's own signature (ADR 0150):
 *
 *     #[Webhook('/webhooks/stripe', verifier: StripeSignature::class)]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Webhook
{
    /**
     * @param string|null $verifier the class, a Webhooks\WebhookVerifier, that proves a delivery
     *        signed some other way than Tinvar's own; null for Tinvar's
     */
    public function __construct(public string $path, public ?string $verifier = null)
    {
        if (preg_match('#^/[A-Za-z0-9._~/-]*\z#', $path) !== 1 || str_contains($path, '//') || str_contains($path, '/../')) {
            throw new \InvalidArgumentException("'{$path}' is not a webhook path: a plain path on this site, with no parameters.");
        }
    }
}
