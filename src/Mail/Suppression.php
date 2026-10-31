<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * One address on the suppression list: why, on whose word -- a provider's
 * webhook, or `manual` -- and since when.
 *
 * The address is kept lowercased, as Message::envelope() compares readers:
 * a bounce for Ada@Example.test suppresses ada@example.test.
 */
final readonly class Suppression
{
    /** The longest provider detail kept: an SMTP diagnostic, cut. */
    public const int MAX_DETAIL = 500;

    public string $email;

    public ?string $detail;

    /**
     * @param string $source who said so: `resend`, `ses`, `postmark`, `mailgun`, `sendgrid`, `manual`
     * @param int $at seconds since the epoch
     * @param string|null $detail the provider's reason -- `5.1.1 user unknown` -- for whoever reviews the list
     */
    public function __construct(
        string $email,
        public SuppressionReason $reason,
        public string $source,
        public int $at,
        ?string $detail = null,
    ) {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || \strlen($email) > 320) {
            throw new \InvalidArgumentException('A suppression names an email address.');
        }
        if (preg_match('/^[a-z0-9_-]{1,32}\z/', $source) !== 1) {
            throw new \InvalidArgumentException("'{$source}' is not a suppression's source: lowercase letters, digits, _ or -.");
        }
        $this->email = $email;
        $this->detail = $detail === null ? null : mb_strcut((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $detail), 0, self::MAX_DETAIL, 'UTF-8');
    }
}
