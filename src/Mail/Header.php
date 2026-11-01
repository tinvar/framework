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

    /**
     * The most bytes of text one encoded word carries: base64'd to 60, and
     * with `=?UTF-8?B?` and `?=` 72, inside RFC 2047's 75 for any character.
     */
    private const int WORD_BYTES = 45;

    /**
     * $value as RFC 2047 encoded words when it is not plain ASCII, folded a
     * word to a line: each of whole characters and at most 75 bytes, so no
     * reader splits a character between two, nor refuses a word too long.
     * Plain ASCII as it is, unless $words: a display name's quotes, angle
     * brackets and commas are markup of the address list, so a name that
     * holds any is written as encoded words whatever its characters.
     */
    public static function encode(string $value, bool $words = false): string
    {
        if (!$words && preg_match('/^[\x20-\x7e]*\z/', $value) === 1) {
            return $value;
        }

        $words = [];
        $chunk = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            if ($chunk !== '' && \strlen($chunk) + \strlen($character) > self::WORD_BYTES) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $character;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }

    /** Whether $value would break a header line, or is not UTF-8. */
    public static function breaks(string $value): bool
    {
        return !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x08\x0a-\x1f\x7f]|\x{0085}|\x{2028}|\x{2029}/u', $value) === 1;
    }
}
