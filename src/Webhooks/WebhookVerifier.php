<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;

/**
 * How a delivery from a sender that signs in its own way proves itself:
 * Stripe's `Stripe-Signature`, say, rather than Tinvar's own header.
 *
 *     #[Webhook('/webhooks/stripe', verifier: StripeSignature::class)]
 *
 * WebhookEndpoint builds the verifier with the application's resolver and
 * asks it, in place of its own signature check, before anything of the
 * handler runs. The rest of the endpoint's steps stay its own: the method,
 * the body's limit and the tenant before the verifier is asked, and the
 * nonce after, claimed in the application's NonceStore until the delivery's
 * expiresAt, for this webhook and tenant and for everyone who holds the
 * same secret (VerifiedDelivery::$signer). A replay inside that window is a
 * 409, and a handler that throws releases the nonce for the sender's retry,
 * as for any webhook.
 *
 * A verifier fails closed: it throws for anything it cannot prove, and
 * never answers with a delivery it did not check.
 */
interface WebhookVerifier
{
    /**
     * Proves $request's delivery to the webhook $webhook, for $tenant, at
     * $now (seconds since the epoch).
     *
     * @param class-string $webhook
     * @throws HttpException 404 when the webhook is not set up here -- no
     *         secret to check with -- and 401 when the delivery does not prove itself
     */
    public function verify(string $webhook, string $tenant, Request $request, string $rawBody, int $now): VerifiedDelivery;
}
