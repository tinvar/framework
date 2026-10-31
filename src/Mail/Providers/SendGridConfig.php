<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;

/**
 * SendGrid's settings (ADR 0147): TINVAR_SENDGRID_KEY -- or _KEY_FILE -- the
 * API key, and TINVAR_SENDGRID_REGION, `global` (the default) or `eu`.
 *
 * TINVAR_SENDGRID_WEBHOOK_PUBLIC_KEY is the verification key SendGrid shows
 * when the Event Webhook's signature is turned on: the base64 of an ECDSA
 * P-256 public key, which each delivery's signature is checked against. A
 * public key, and no secret.
 */
#[Config(prefix: 'TINVAR_SENDGRID_')]
final readonly class SendGridConfig
{
    public function __construct(
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $key = null,
        public string $region = 'global',
        public ?string $webhookPublicKey = null,
    ) {
        if (!isset(SendGridTransport::HOSTS[$region])) {
            throw ConfigException::invalid('TINVAR_SENDGRID_REGION', 'is global or eu');
        }
        if ($webhookPublicKey !== null && SendGridWebhook::publicKey($webhookPublicKey) === null) {
            throw ConfigException::invalid('TINVAR_SENDGRID_WEBHOOK_PUBLIC_KEY', 'is the base64 ECDSA P-256 verification key SendGrid shows for the signed Event Webhook');
        }
    }
}
