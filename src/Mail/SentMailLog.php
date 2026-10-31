<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Which messages the application sent, through which provider, to whom:
 * the provider's message id and each reader, recorded by the Mailer as the
 * provider hands the id back (ADR 0147).
 *
 * What a webhook's event is checked against before anything is done about
 * it (MailEventsEndpoint). A provider's signature proves a delivery came
 * from the provider; Mailgun's does not cover what the delivery says --
 * only its timestamp and token -- so one triple taken from any delivery
 * signed with the same key could carry a forged event, once, and suppress
 * any address its forger chose. An event about a message this application
 * never sent to that reader is ignored, whoever signed it.
 *
 * Readers are kept as digests of their lowercased address, not the
 * address: the log answers "was this sent to that?", and never "who was
 * this sent to?". Kept for RETENTION, longer than a provider reports on a
 * message -- a complaint can come weeks after.
 */
interface SentMailLog
{
    /** Seconds a send is kept: 45 days. */
    public const int RETENTION = 3_888_000;

    /** Records $sent's id against each of its readers; a send with no id is not recorded. */
    public function record(SentMessage $sent, int $now): void;

    /** Whether $provider took a message with $messageId for $recipient, within the retention. */
    public function wasSent(string $provider, string $messageId, string $recipient, int $now): bool;
}
