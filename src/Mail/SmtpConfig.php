<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Secret;

/**
 * The SMTP provider's settings (ADR 0146): TINVAR_SMTP_HOST, _PORT (587),
 * _USERNAME, _PASSWORD -- or _PASSWORD_FILE -- and _ENCRYPTION: `starttls`
 * (the default), `tls` for implicit TLS, or `none` for a relay on this
 * machine, which SmtpTransport holds credentials back from. _LOCAL_DOMAIN
 * is what EHLO says and Message-IDs end in.
 */
#[Config(prefix: 'TINVAR_SMTP_')]
final readonly class SmtpConfig
{
    public function __construct(
        public ?string $host = null,
        public int $port = 587,
        public ?string $username = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $password = null,
        public string $encryption = SmtpTransport::STARTTLS,
        public string $localDomain = 'localhost',
    ) {
        if (!\in_array($encryption, [SmtpTransport::NONE, SmtpTransport::STARTTLS, SmtpTransport::TLS], true)) {
            throw ConfigException::invalid('TINVAR_SMTP_ENCRYPTION', 'is starttls, tls or none');
        }
        if ($port < 1 || $port > 65535) {
            throw ConfigException::invalid('TINVAR_SMTP_PORT', 'is a port, 1 to 65535');
        }
        if (($username === null) !== ($password === null)) {
            throw ConfigException::invalid('TINVAR_SMTP_USERNAME', 'and TINVAR_SMTP_PASSWORD are set together, or neither');
        }
    }
}
