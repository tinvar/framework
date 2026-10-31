<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * The addresses mail is not sent to: those that hard-bounced, those whose
 * readers complained, and those suppressed by hand (ADR 0147).
 *
 * Fed by MailEventsEndpoint from the providers' webhooks, and read by the
 * Mailer before every send: a reader the list blocks for the message's
 * category is left off it, and a message left with no To reader is not
 * sent at all (MailCategory says which reason blocks what).
 *
 * One list for the application, not one per tenant: an address that does
 * not exist does not exist for every tenant, and the sender's reputation
 * the bounces cost is the application's, shared by all of them.
 */
interface Suppressions
{
    /**
     * The suppressions held for any of $emails, by lowercased address.
     *
     * @param list<string> $emails
     * @return array<string, Suppression>
     */
    public function find(array $emails): array;

    /**
     * Records $suppression -- unless the address is held for a reason that
     * stops more already: a complaint after a bounce leaves the bounce.
     */
    public function suppress(Suppression $suppression): void;

    /** Takes $email off the list: a reader who fixed their mailbox, or asked to be mailed again. */
    public function lift(string $email): void;
}
