<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * The secret each webhook's sender signs with, per tenant: the application
 * keeps them -- one it issued, or one a provider issued to it -- and hands
 * the endpoint the one for this webhook and this tenant, or null for a
 * webhook the tenant has not set up, which is refused.
 */
interface WebhookSecrets
{
    /**
     * @param class-string $webhook
     * @param string       $tenant '' for a webhook with no tenant
     */
    public function secretFor(string $webhook, string $tenant): ?string;
}
