<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\ListOf;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;

/**
 * Where the application's mail goes (ADR 0147): TINVAR_MAIL_PROVIDER, one
 * of `smtp`, `ses`, `postmark`, `mailgun`, `resend`, `sendgrid`, `log` or
 * `array` -- or several, comma-separated, tried in that order
 * (`ses,postmark`), or spread round robin where TINVAR_MAIL_STRATEGY is
 * `roundrobin`. Each provider reads its own settings from variables of its
 * own -- TINVAR_SES_REGION, TINVAR_RESEND_KEY... -- and MailTransports
 * builds the transport from them, refusing at boot a provider missing one.
 *
 * One way, and no URL: a secret is never written into a DSN, where its
 * characters need encoding and the whole string is one log line away from
 * being quoted. Every key and password is a variable of its own, a Secret,
 * and may be read from the file its _FILE variable names.
 *
 * TINVAR_MAIL_LOG is where the `log` provider writes, when the application
 * names no file of its own.
 */
#[Config(prefix: 'TINVAR_MAIL_')]
final readonly class MailConfig
{
    /**
     * @param list<MailProvider> $provider in the order they are tried; none, and the application's default
     */
    public function __construct(
        #[ListOf(MailProvider::class)]
        public array $provider = [],
        public MailStrategy $strategy = MailStrategy::Failover,
        public ?string $log = null,
    ) {
        if (\count(array_unique(array_map(static fn (MailProvider $p): string => $p->value, $provider))) !== \count($provider)) {
            throw ConfigException::invalid('TINVAR_MAIL_PROVIDER', 'names a provider twice');
        }
        if ($log !== null && !str_starts_with($log, '/')) {
            throw ConfigException::invalid('TINVAR_MAIL_LOG', 'must be an absolute path');
        }
    }

    /** @throws ConfigException */
    public static function load(): self
    {
        return Configs::load(self::class);
    }
}
