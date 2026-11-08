<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

/**
 * Told when the framework disables a tenant's subscription (ADR 0197): its
 * deliveries kept failing, or the receiver answered 410. Called once, by
 * the delivery that disabled it, in the tenant it belongs to, after the
 * change is written -- for the application to record it and tell the
 * tenant's admins. tinvar/tenancy's NotifiedWebhookDisabling records it
 * with TenantActivity and mails them where the application gives a mail.
 */
interface WebhookDisabledNotice
{
    public function disabled(StoredWebhookSubscription $subscription): void;
}
