<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * What a header value may hold. A CR or LF is a header of an attacker's
 * choosing -- a Bcc to themselves -- written into a message the application
 * thought it addressed; a NUL or another control character is one some
 * mail servers end a line on, or cut the value at; and U+0085, U+2028 and
 * U+2029 are line breaks to software that reads Unicode's.
 */
final class Header
{
    /** Longer, and a subject is not what the reader sees of it, nor one line on the wire. */
    public const int MAX_SUBJECT = 255;

    /** Whether $value would break a header line, or is not UTF-8. */
    public static function breaks(string $value): bool
    {
        return !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x08\x0a-\x1f\x7f]|\x{0085}|\x{2028}|\x{2029}/u', $value) === 1;
    }
}
