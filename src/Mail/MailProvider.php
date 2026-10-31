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

    /** Whether a provider's webhook reports its bounces, complaints and deliveries (MailEventsEndpoint). */
    public function hasWebhooks(): bool
    {
        return \in_array($this, [self::Ses, self::Postmark, self::Mailgun, self::Resend, self::SendGrid], true);
    }
}
