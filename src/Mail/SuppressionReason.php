<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Why an address is on the suppression list, and so what it is not sent.
 *
 * A hard bounce -- the address does not exist, or its server refuses it
 * for good -- and a hand-made suppression stop Marketing and Transactional
 * mail. A complaint -- the reader marked a message as spam -- stops
 * Marketing alone. Essential mail is stopped by nothing (MailCategory).
 */
enum SuppressionReason: string
{
    case Bounce = 'bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';

    /** Whether an address suppressed for this is not sent mail of $category. */
    public function blocks(MailCategory $category): bool
    {
        return match ($category) {
            MailCategory::Essential => false,
            MailCategory::Marketing => true,
            MailCategory::Transactional => $this !== self::Complaint,
        };
    }

    /** How much it stops: a reason replaces one already held only when it stops as much or more. */
    public function weight(): int
    {
        return $this === self::Complaint ? 1 : 2;
    }
}
