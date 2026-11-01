<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

/**
 * One spelling per address: trimmed and lowercased, as every account is
 * stored, looked up, throttled and mailed.
 *
 * Lowercasing the local part is a liberty RFC 5321 does not grant, and one
 * every mail provider people use takes: two accounts for Ada@example.com
 * and ada@example.com would be two accounts for one inbox, each able to
 * take the other's links.
 */
final class Emails
{
    /** The longest address a mailbox can have (RFC 5321's path limit, less its brackets). */
    public const int MAX_LENGTH = 254;

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
