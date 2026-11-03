<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** Where mail can go, as TINVAR_MAIL_PROVIDER names it (MailConfig). */
enum MailProvider: string
{
    case Smtp = 'smtp';
    case Ses = 'ses';
    case Postmark = 'postmark';
    case Mailgun = 'mailgun';
    case Resend = 'resend';
    case SendGrid = 'sendgrid';
    case Log = 'log';
    case Array = 'array';

    /**
     * The variables a provider sends with, in the order a person sets them,
     * each marked true where it is a secret: the one list `tinvar new`
     * writes into .env and `tinvar deploy:init` asks for. A secret is one
     * the config reads from a _FILE as well, or one named like a secret
     * (EnvironmentFile::namesSecret()), as SES's access key id is: it goes
     * to a platform's secret store, never into a file in the repository.
     *
     * @return array<string, bool>
     */
    public function variables(): array
    {
        return match ($this) {
            self::Smtp => ['TINVAR_SMTP_HOST' => false, 'TINVAR_SMTP_PORT' => false, 'TINVAR_SMTP_USERNAME' => false, 'TINVAR_SMTP_PASSWORD' => true],
            self::Ses => ['TINVAR_SES_REGION' => false, 'TINVAR_SES_KEY' => true, 'TINVAR_SES_SECRET' => true],
            self::Postmark => ['TINVAR_POSTMARK_TOKEN' => true],
            self::Mailgun => ['TINVAR_MAILGUN_DOMAIN' => false, 'TINVAR_MAILGUN_REGION' => false, 'TINVAR_MAILGUN_KEY' => true],
            self::Resend => ['TINVAR_RESEND_KEY' => true],
            self::SendGrid => ['TINVAR_SENDGRID_KEY' => true],
            self::Log, self::Array => [],
        };
    }

    /** Whether a provider's webhook reports its bounces, complaints and deliveries (MailEventsEndpoint). */
    public function hasWebhooks(): bool
    {
        return \in_array($this, [self::Ses, self::Postmark, self::Mailgun, self::Resend, self::SendGrid], true);
    }
}
