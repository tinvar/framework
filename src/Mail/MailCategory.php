<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * What kind of mail an Email is, as the suppression list reads it.
 *
 *   - Marketing: a newsletter, an announcement -- mail with an unsubscribe,
 *     which a reader can do without. Not sent to an address that bounced,
 *     complained or was suppressed by hand.
 *   - Transactional: an order confirmation, a receipt -- mail about
 *     something the reader did. Not sent to an address that hard-bounced or
 *     was suppressed by hand: it would bounce again, and every bounce costs
 *     the sender's reputation. A complaint does not stop it: a reader who
 *     marked a newsletter as spam still gets the receipt for what they
 *     bought.
 *   - Essential: a sign-in link, a password reset, a confirmation the
 *     reader asked for a moment ago, from the address they typed. Sent
 *     whatever the list says, since the reader asking for it is better
 *     evidence than an old bounce -- a mailbox once full, or a domain once
 *     misconfigured, is working again more often than not.
 */
enum MailCategory: string
{
    case Marketing = 'marketing';
    case Transactional = 'transactional';
    case Essential = 'essential';
}
