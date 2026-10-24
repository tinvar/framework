<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Support\Signer;

/**
 * Webhook secrets derived from the application key: one per webhook and
 * tenant, never stored, shown to the tenant's sender when they set it up.
 *
 * Derived under a purpose of its own, so a webhook secret is no key for
 * anything else -- and one tenant's secret says nothing about another's.
 */
final readonly class DerivedSecrets implements WebhookSecrets
{
    public const string PURPOSE = 'webhook-secret';

    /** @param Signer $signer built for PURPOSE from the application key */
    public function __construct(private Signer $signer) {}

    public function secretFor(string $webhook, string $tenant): string
    {
        return Signer::encode($this->signer->sign($webhook . "\n" . $tenant));
    }
}
