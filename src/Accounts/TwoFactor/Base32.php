<?php

declare(strict_types=1);

namespace Tinvar\Accounts\TwoFactor;

/**
 * RFC 4648 base32, without padding: how an authenticator app is given a
 * TOTP secret, typed or in an otpauth:// URI.
 *
 * Decoding is forgiving of how a person copies a secret -- spaces, dashes,
 * lowercase -- and strict about everything else: a character outside the
 * alphabet, or leftover bits that are not zero, is no secret at all.
 */
final class Base32
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(#[\SensitiveParameter] string $bytes): string
    {
        $bits = '';
        for ($i = 0, $n = \strlen($bytes); $i < $n; $i++) {
            $bits .= str_pad(decbin(\ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0, $n = \strlen($bits); $i < $n; $i += 5) {
            $out .= self::ALPHABET[(int) bindec(str_pad(substr($bits, $i, 5), 5, '0'))];
        }

        return $out;
    }

    /** The bytes $text encodes, or null when it is not base32. */
    public static function decode(#[\SensitiveParameter] string $text): ?string
    {
        $text = strtoupper((string) preg_replace('/[\s-]+/', '', rtrim($text, '=')));
        if ($text === '' || preg_match('/^[A-Z2-7]+\z/', $text) !== 1) {
            return null;
        }
        $bits = '';
        for ($i = 0, $n = \strlen($text); $i < $n; $i++) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $text[$i])), 5, '0', STR_PAD_LEFT);
        }
        $whole = intdiv(\strlen($bits), 8) * 8;
        // What is left over pads the last byte out, and must be zero.
        if (trim(substr($bits, $whole), '0') !== '') {
            return null;
        }
        $bytes = '';
        for ($i = 0; $i < $whole; $i += 8) {
            $bytes .= \chr(((int) bindec(substr($bits, $i, 8))) & 0xFF);
        }

        return $bytes === '' ? null : $bytes;
    }
}
