<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A key, as a terminal in raw mode sends it: each named one is the
 * sequence most terminals send for it, and parse() reads the other
 * spellings -- `\eOA` for up, `\e[1~` for home, `\n` for enter -- as the
 * same. Anything else is a character typed, one at a time, a multibyte
 * one whole.
 */
final class Key
{
    public const string UP = "\e[A";

    public const string DOWN = "\e[B";

    public const string RIGHT = "\e[C";

    public const string LEFT = "\e[D";

    public const string HOME = "\e[H";

    public const string END = "\e[F";

    public const string PAGE_UP = "\e[5~";

    public const string PAGE_DOWN = "\e[6~";

    public const string DELETE = "\e[3~";

    public const string SHIFT_TAB = "\e[Z";

    public const string ENTER = "\r";

    public const string TAB = "\t";

    public const string BACKSPACE = "\x7f";

    public const string SPACE = ' ';

    public const string ESCAPE = "\e";

    public const string CTRL_A = "\x01";

    public const string CTRL_C = "\x03";

    public const string CTRL_D = "\x04";

    public const string CTRL_E = "\x05";

    public const string CTRL_U = "\x15";

    public const string CTRL_W = "\x17";

    /** The other spellings of a named key. */
    private const array ALIASES = [
        "\eOA" => self::UP, "\eOB" => self::DOWN, "\eOC" => self::RIGHT, "\eOD" => self::LEFT,
        "\eOH" => self::HOME, "\eOF" => self::END,
        "\e[1~" => self::HOME, "\e[7~" => self::HOME, "\e[4~" => self::END, "\e[8~" => self::END,
        "\n" => self::ENTER, "\x08" => self::BACKSPACE,
    ];

    /** What parse() returns as itself. */
    private const array NAMED = [
        self::UP, self::DOWN, self::RIGHT, self::LEFT, self::HOME, self::END, self::PAGE_UP, self::PAGE_DOWN,
        self::DELETE, self::SHIFT_TAB, self::ENTER, self::TAB, self::BACKSPACE, self::CTRL_A, self::CTRL_C,
        self::CTRL_D, self::CTRL_E, self::CTRL_U, self::CTRL_W,
    ];

    /**
     * What was read, as keys: named keys as their constants, characters
     * as themselves. A sequence no key here is -- a function key, insert --
     * is dropped.
     *
     * @return list<string>
     */
    public static function parse(string $bytes): array
    {
        $keys = [];
        $length = \strlen($bytes);
        $i = 0;
        while ($i < $length) {
            $byte = $bytes[$i];
            if ($byte === "\e") {
                [$sequence, $i] = self::escape($bytes, $i);
                $key = self::named($sequence);
                if ($key !== null) {
                    $keys[] = $key;
                }

                continue;
            }
            if ($byte === "\r" && ($bytes[$i + 1] ?? '') === "\n") {
                $keys[] = self::ENTER;
                $i += 2;

                continue;
            }
            $ord = \ord($byte);
            if ($ord < 0x20 || $ord === 0x7f) {
                $key = self::named($byte);
                if ($key !== null) {
                    $keys[] = $key;
                }
                ++$i;

                continue;
            }
            $size = match (true) {
                $ord >= 0xf0 => 4,
                $ord >= 0xe0 => 3,
                $ord >= 0xc0 => 2,
                default => 1,
            };
            $keys[] = substr($bytes, $i, $size);
            $i += $size;
        }

        return $keys;
    }

    /** Whether $key is a character to type, not a key that does something. */
    public static function printable(string $key): bool
    {
        if ($key === '' || $key[0] === "\e") {
            return false;
        }
        $ord = \ord($key[0]);

        return $ord >= 0x20 && $ord !== 0x7f;
    }

    /**
     * The escape sequence starting at $i, and where the next key starts.
     *
     * @return array{string, int}
     */
    private static function escape(string $bytes, int $i): array
    {
        $next = $bytes[$i + 1] ?? '';
        if ($next === '[' && preg_match('/\G\e\[[0-9;]*[@-~]/', $bytes, $m, 0, $i) === 1) {
            // Modifiers on an arrow, home or end -- `\e[1;5C` -- are the key.
            $sequence = preg_replace('/^\e\[1;\d+([A-DHF])\z/', "\e[$1", $m[0]) ?? $m[0];

            return [$sequence, $i + \strlen($m[0])];
        }
        if ($next === 'O' && isset($bytes[$i + 2])) {
            return [substr($bytes, $i, 3), $i + 3];
        }

        return ["\e", $i + 1];
    }

    private static function named(string $sequence): ?string
    {
        if ($sequence === self::ESCAPE) {
            return self::ESCAPE;
        }
        $sequence = self::ALIASES[$sequence] ?? $sequence;

        return \in_array($sequence, self::NAMED, true) ? $sequence : null;
    }
}
