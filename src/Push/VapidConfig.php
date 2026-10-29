<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;

/**
 * The application's VAPID keys (RFC 8292), read from the environment as
 * every #[Config] class is (ADR 0085):
 *
 *     TINVAR_VAPID_PUBLIC_KEY=BNc...   the public key, base64url, as the browser's subscribe() is handed it
 *     TINVAR_VAPID_PRIVATE_KEY=q1d...  the private key, base64url -- or TINVAR_VAPID_PRIVATE_KEY_FILE
 *     TINVAR_VAPID_SUBJECT=mailto:ops@shop.example
 *
 * `tinvar push:keys` prints a new pair. The private key is a Secret: never
 * in a dump, a log line, a payload or a serialized job. Loaded only by an
 * application that sends push; one that does not never reads these.
 *
 * Refused at boot, by variable: a public key that is not an uncompressed
 * P-256 point, a private key that is not 32 bytes or not the public key's,
 * and a subject that is neither a mailto: address nor an https URL -- the
 * contact RFC 8292 has push services reach the sender at.
 */
#[Config(prefix: 'TINVAR_VAPID_')]
final readonly class VapidConfig
{
    public function __construct(
        public string $publicKey,
        #[\SensitiveParameter, Env(file: true)]
        public Secret $privateKey,
        public string $subject,
    ) {
        if (preg_match('#^(mailto:[^\s@/]+@[^\s@/]+|https://[^\s/?\#@]+(/[^\s]*)?)\z#', $subject) !== 1) {
            throw ConfigException::invalid('TINVAR_VAPID_SUBJECT', 'must be a mailto: address or an https URL a push service can reach the sender at');
        }
        try {
            VapidKeys::fromConfig($this);
        } catch (\InvalidArgumentException $e) {
            throw ConfigException::invalid(str_contains($e->getMessage(), 'private') ? 'TINVAR_VAPID_PRIVATE_KEY' : 'TINVAR_VAPID_PUBLIC_KEY', $e->getMessage());
        }
    }
}
