<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * How many columns text takes on a terminal: a wide character -- CJK, a
 * fullwidth form, most emoji -- two, a combining mark, a format character
 * or what a zero-width joiner joins none, colour codes none. Bytes that are
 * not UTF-8 count one each, so a stray one never makes text measure short.
 *
 * Measured by code point, not by grapheme cluster as some terminals draw:
 * close enough to pad and truncate by, and never wider than it says.
 */
final class Width
{
    /** An ANSI escape sequence: colour, cursor movement, erasing. */
    private const string ANSI = '/\e\[[0-9;?]*[@-~]|\e\][^\a]*\a|\e[@-Z\\\\-_]/';

    /** The ranges a terminal draws two columns wide. */
    private const array WIDE = [
        [0x1100, 0x115F], [0x231A, 0x231B], [0x2329, 0x232A], [0x23E9, 0x23EC], [0x23F0, 0x23F0], [0x23F3, 0x23F3],
        [0x25FD, 0x25FE], [0x2614, 0x2615], [0x2648, 0x2653], [0x267F, 0x267F], [0x2693, 0x2693], [0x26A1, 0x26A1],
        [0x26AA, 0x26AB], [0x26BD, 0x26BE], [0x26C4, 0x26C5], [0x26CE, 0x26CE], [0x26D4, 0x26D4], [0x26EA, 0x26EA],
        [0x26F2, 0x26F3], [0x26F5, 0x26F5], [0x26FA, 0x26FA], [0x26FD, 0x26FD], [0x2705, 0x2705], [0x270A, 0x270B],
        [0x2728, 0x2728], [0x274C, 0x274C], [0x274E, 0x274E], [0x2753, 0x2755], [0x2757, 0x2757], [0x2795, 0x2797],
        [0x27B0, 0x27B0], [0x27BF, 0x27BF], [0x2B1B, 0x2B1C], [0x2B50, 0x2B50], [0x2B55, 0x2B55],
        [0x2E80, 0x303E], [0x3041, 0x33FF], [0x3400, 0x4DBF], [0x4E00, 0x9FFF], [0xA000, 0xA4CF], [0xA960, 0xA97F],
        [0xAC00, 0xD7A3], [0xF900, 0xFAFF], [0xFE10, 0xFE19], [0xFE30, 0xFE6F], [0xFF00, 0xFF60], [0xFFE0, 0xFFE6],
        [0x16FE0, 0x16FE4], [0x17000, 0x18AFF], [0x1B000, 0x1B2FF], [0x1F004, 0x1F004], [0x1F0CF, 0x1F0CF],
        [0x1F18E, 0x1F18E], [0x1F191, 0x1F19A], [0x1F200, 0x1F251], [0x1F300, 0x1F64F], [0x1F680, 0x1F6FF],
        [0x1F7E0, 0x1F7EB], [0x1F900, 0x1F9FF], [0x1FA70, 0x1FAFF], [0x20000, 0x2FFFD], [0x30000, 0x3FFFD],
    ];

    /** The columns $text takes, its colour codes none. */
    public static function of(string $text): int
    {
        $width = 0;
        $joined = false;
        foreach (self::chars(self::strip($text)) as $char) {
            $columns = self::ofChar($char);
            if ($joined) {
                $columns = 0;
            }
            $joined = $char === "\u{200D}";
            $width += $columns;
        }

        return $width;
    }

    /**
     * $text safe to put on a terminal: each control character shown as a
     * picture of it rather than sent -- a C0 control as its Unicode control
     * picture (escape as ␛, carriage return as ␍), delete as ␡, a C1 control
     * as � -- so text from outside (a table's cells, a prompt's label, an
     * option, a job's stored error) cannot move the cursor, clear the
     * screen, retitle the window or write the clipboard. Only Style's own
     * output sends an escape. $keep names the controls the caller lays out
     * itself, such as "\n" for text it wraps by line.
     *
     * One character for one, so a cursor's place in the text holds. Text
     * that is not UTF-8 has each byte past ASCII shown as �, a C1 byte being
     * a control to a terminal reading eight-bit controls.
     */
    public static function clean(string $text, string $keep = ''): string
    {
        if (preg_match('/[\x00-\x1F\x7F-\x9F]/', $text) !== 1) {
            return $text;
        }
        if (preg_match('//u', $text) !== 1) {
            $text = (string) preg_replace('/[\x80-\xFF]/', "\u{FFFD}", $text);
        }

        return (string) preg_replace_callback('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', static function (array $match) use ($keep): string {
            $char = $match[0];

            return match (true) {
                \strlen($char) > 1 => "\u{FFFD}",
                $keep !== '' && str_contains($keep, $char) => $char,
                $char === "\x7F" => "\u{2421}",
                default => mb_chr(0x2400 + \ord($char), 'UTF-8'),
            };
        }, $text);
    }

    /** $text without its ANSI escape sequences. */
    public static function strip(string $text): string
    {
        return str_contains($text, "\e") ? (string) preg_replace(self::ANSI, '', $text) : $text;
    }

    /**
     * $text's code points; each byte of text that is not UTF-8.
     *
     * @return list<string>
     */
    public static function chars(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? str_split($text) : $chars;
    }

    /** The columns one character takes. */
    public static function ofChar(string $char): int
    {
        $ord = \ord($char[0] ?? "\0");
        if (\strlen($char) === 1) {
            return $ord < 0x20 || $ord === 0x7f ? 0 : 1;
        }
        if (preg_match('/^[\p{Mn}\p{Me}\p{Cf}]\z/u', $char) === 1 || self::between(self::codePoint($char), [[0xFE00, 0xFE0F], [0x1160, 0x11FF]])) {
            return 0;
        }

        return self::between(self::codePoint($char), self::WIDE) ? 2 : 1;
    }

    /**
     * $text cut to $width columns, ending in $ellipsis where it was cut; a
     * wide character that would straddle the edge is left out whole. Colour
     * codes are kept, and closed where text was cut.
     */
    public static function truncate(string $text, int $width, string $ellipsis = '…'): string
    {
        if (self::of($text) <= $width) {
            return $text;
        }
        $room = $width - self::of($ellipsis);
        if ($room < 0) {
            return '';
        }

        $out = '';
        $used = 0;
        $styled = false;
        $parts = preg_split('/(' . substr(self::ANSI, 1, -1) . ')/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            if ($part[0] === "\e") {
                $out .= $part;
                $styled = true;

                continue;
            }
            foreach (self::chars($part) as $char) {
                $columns = self::ofChar($char);
                if ($used + $columns > $room) {
                    return $out . ($styled ? "\e[0m" : '') . $ellipsis;
                }
                $out .= $char;
                $used += $columns;
            }
        }

        return $out . ($styled ? "\e[0m" : '') . $ellipsis;
    }

    /** $text with spaces after it to make $width columns; as it is if it is that wide already. */
    public static function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - self::of($text)));
    }

    /**
     * Plain $text in lines of $width columns at most, broken between words
     * where it can be and inside a word too long for a line.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $width = max(1, $width);
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (explode(' ', $paragraph) as $word) {
                $candidate = $line === '' ? $word : "{$line} {$word}";
                if (self::of($candidate) <= $width) {
                    $line = $candidate;

                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                }
                $line = '';
                foreach (self::chars($word) as $char) {
                    if (self::of($line . $char) > $width) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $char;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private static function codePoint(string $char): int
    {
        $bytes = array_values(unpack('C*', $char) ?: []);

        return match (\count($bytes)) {
            2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
            3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
            4 => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
            default => $bytes[0] ?? 0,
        };
    }

    /** @param list<array{int, int}> $ranges */
    private static function between(int $codePoint, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($codePoint < $from) {
                return false;
            }
            if ($codePoint <= $to) {
                return true;
            }
        }

        return false;
    }
}
