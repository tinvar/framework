<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;

/**
 * Resend's settings (ADR 0147): TINVAR_RESEND_KEY -- or _KEY_FILE -- the API
 * key, and TINVAR_RESEND_WEBHOOK_SECRET, the webhook's `whsec_...` signing
 * secret, which its Svix signatures are made with.
 */
#[Config(prefix: 'TINVAR_RESEND_')]
final readonly class ResendConfig
{
    public function __construct(
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $key = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $webhookSecret = null,
    ) {
        if ($webhookSecret !== null && SvixSignature::key($webhookSecret->reveal()) === null) {
            throw ConfigException::invalid('TINVAR_RESEND_WEBHOOK_SECRET', 'is the whsec_... secret Resend shows for the webhook');
        }
    }
}
