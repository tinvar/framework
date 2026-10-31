<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;

/**
 * Mailgun's settings (ADR 0147): TINVAR_MAILGUN_KEY -- or _KEY_FILE -- the
 * sending domain's API key, TINVAR_MAILGUN_DOMAIN, and TINVAR_MAILGUN_REGION,
 * `us` (the default) or `eu`, where the domain was created.
 *
 * TINVAR_MAILGUN_WEBHOOK_SECRET is the HTTP webhook signing key Mailgun
 * shows under its webhooks, which each event's HMAC is made with.
 */
#[Config(prefix: 'TINVAR_MAILGUN_')]
final readonly class MailgunConfig
{
    public function __construct(
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $key = null,
        public ?string $domain = null,
        public string $region = 'us',
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $webhookSecret = null,
    ) {
        if (!isset(MailgunTransport::HOSTS[$region])) {
            throw ConfigException::invalid('TINVAR_MAILGUN_REGION', 'is us or eu');
        }
    }
}
