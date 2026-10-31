<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;

/**
 * Postmark's settings (ADR 0147): TINVAR_POSTMARK_TOKEN -- or _TOKEN_FILE --
 * the server's API token, and TINVAR_POSTMARK_STREAM, the message stream
 * (`outbound` unless set).
 *
 * Postmark signs no webhook. What it offers is HTTP basic auth on the
 * webhook's URL -- `https://user:password@shop.example/_mail/events/postmark`
 * as the URL Postmark is given -- which TINVAR_POSTMARK_WEBHOOK_USERNAME and
 * TINVAR_POSTMARK_WEBHOOK_PASSWORD are; the password 24 characters or more,
 * since it is all that stands between the endpoint and anyone.
 */
#[Config(prefix: 'TINVAR_POSTMARK_')]
final readonly class PostmarkConfig
{
    /** The shortest webhook password taken. */
    public const int MIN_WEBHOOK_PASSWORD = 24;

    public function __construct(
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $token = null,
        public ?string $stream = null,
        public ?string $webhookUsername = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $webhookPassword = null,
    ) {
        if (($webhookUsername === null) !== ($webhookPassword === null)) {
            throw ConfigException::invalid('TINVAR_POSTMARK_WEBHOOK_USERNAME', 'and TINVAR_POSTMARK_WEBHOOK_PASSWORD are set together, or neither');
        }
        if ($webhookUsername !== null && preg_match('/^[A-Za-z0-9._-]{1,64}\z/', $webhookUsername) !== 1) {
            throw ConfigException::invalid('TINVAR_POSTMARK_WEBHOOK_USERNAME', 'is 1 to 64 letters, digits, ., _ or -');
        }
        if ($webhookPassword !== null && $webhookPassword->length() < self::MIN_WEBHOOK_PASSWORD) {
            throw ConfigException::invalid('TINVAR_POSTMARK_WEBHOOK_PASSWORD', 'must be ' . self::MIN_WEBHOOK_PASSWORD . ' characters or more (openssl rand -hex 24)');
        }
    }
}
