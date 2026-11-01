<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * The part of CBOR (RFC 8949) WebAuthn's structures are written in: an
 * attestation object, a COSE public key, an authenticator's extensions.
 *
 * What an authenticator sends is untrusted bytes from the browser, so this
 * reads less than CBOR allows and refuses the rest:
 *
 * - unsigned and negative integers, byte strings, text strings, arrays,
 *   maps, false, true, null and floats -- and nothing else: a tag (major
 *   type 6) and the simple values CTAP2 never sends are refused;
 * - definite lengths only, as CTAP2's canonical form has them: an
 *   indefinite-length item is refused;
 * - a length is held to the bytes left before anything is read for it, so
 *   a header claiming four gigabytes allocates nothing;
 * - nesting at most MAX_DEPTH deep;
 * - a map's keys are integers or text, each once: a second `alg` or
 *   `authData` would be a second answer to a question asked once;
 * - a text string must be UTF-8.
 *
 * Byte strings come back as CborBytes, text as a PHP string, maps as PHP
 * arrays by key. Anything malformed is a PasskeyRefused.
 */
final class Cbor
{
    /** How deep arrays and maps may nest: a COSE key is two levels, an attestation three. */
    public const int MAX_DEPTH = 16;

    /** The whole of $bytes as one item; bytes after it are refused. */
    public static function decode(string $bytes): mixed
    {
        [$value, $end] = self::decodeFirst($bytes);
        if ($end !== \strlen($bytes)) {
            throw PasskeyRefused::malformed();
        }

        return $value;
    }

    /**
     * The one item starting at $offset, and the offset just after it: for
     * an item that something else follows, as a credential's COSE key is
     * followed by the authenticator's extensions.
     *
     * @return array{mixed, int}
     */
    public static function decodeFirst(string $bytes, int $offset = 0): array
    {
        if ($offset < 0 || $offset > \strlen($bytes)) {
            throw PasskeyRefused::malformed();
        }

        return self::item($bytes, $offset, 0);
    }

    /** @return array{mixed, int} */
    private static function item(string $bytes, int $offset, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw PasskeyRefused::malformed();
        }
        $length = \strlen($bytes);
        if ($offset >= $length) {
            throw PasskeyRefused::malformed();
        }

        $initial = \ord($bytes[$offset]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;
        $offset++;

        if ($major === 7) {
            return self::simple($bytes, $offset, $info);
        }
        if ($major === 6) {
            // Tags change what an item means; nothing WebAuthn reads has one.
            throw PasskeyRefused::malformed();
        }

        [$argument, $offset] = self::argument($bytes, $offset, $info);

        switch ($major) {
            case 0:
                return [$argument, $offset];
            case 1:
                // -1 - n; n is at most PHP_INT_MAX here (argument()), so this fits.
                return [-1 - $argument, $offset];
            case 2:
            case 3:
                if ($argument > $length - $offset) {
                    throw PasskeyRefused::malformed();
                }
                $string = substr($bytes, $offset, $argument);
                $offset += $argument;
                if ($major === 2) {
                    return [new CborBytes($string), $offset];
                }
                if (!mb_check_encoding($string, 'UTF-8')) {
                    throw PasskeyRefused::malformed();
                }

                return [$string, $offset];
            case 4:
                // Every item takes at least one byte.
                if ($argument > $length - $offset) {
                    throw PasskeyRefused::malformed();
                }
                $list = [];
                for ($i = 0; $i < $argument; $i++) {
                    [$list[], $offset] = self::item($bytes, $offset, $depth + 1);
                }

                return [$list, $offset];
            default:
                // A map: every pair takes at least two bytes.
                if ($argument > intdiv($length - $offset, 2)) {
                    throw PasskeyRefused::malformed();
                }
                $map = [];
                for ($i = 0; $i < $argument; $i++) {
                    [$key, $offset] = self::item($bytes, $offset, $depth + 1);
                    if (!\is_int($key) && !\is_string($key)) {
                        throw PasskeyRefused::malformed();
                    }
                    // A text key that reads as a decimal integer would become
                    // that integer as a PHP array key, and meet an integer key
                    // of the same value: refused rather than merged.
                    if (\is_string($key) && (string) (int) $key === $key) {
                        throw PasskeyRefused::malformed();
                    }
                    if (\array_key_exists($key, $map)) {
                        throw PasskeyRefused::malformed();
                    }
                    [$map[$key], $offset] = self::item($bytes, $offset, $depth + 1);
                }

                return [$map, $offset];
        }
    }

    /**
     * An item's argument: its value, or its length or count.
     *
     * @return array{int, int}
     */
    private static function argument(string $bytes, int $offset, int $info): array
    {
        if ($info < 24) {
            return [$info, $offset];
        }
        $size = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            // 28-30 are reserved; 31 is an indefinite length.
            default => throw PasskeyRefused::malformed(),
        };
        if ($size > \strlen($bytes) - $offset) {
            throw PasskeyRefused::malformed();
        }

        $value = 0;
        for ($i = 0; $i < $size; $i++) {
            // A 64-bit argument past PHP_INT_MAX is no length or value
            // anything here could use.
            if ($value > (PHP_INT_MAX >> 8)) {
                throw PasskeyRefused::malformed();
            }
            $value = ($value << 8) | \ord($bytes[$offset + $i]);
        }

        return [$value, $offset + $size];
    }

    /** @return array{mixed, int} */
    private static function simple(string $bytes, int $offset, int $info): array
    {
        $left = \strlen($bytes) - $offset;

        return match ($info) {
            20 => [false, $offset],
            21 => [true, $offset],
            22 => [null, $offset],
            25 => $left >= 2 ? [self::half(substr($bytes, $offset, 2)), $offset + 2] : throw PasskeyRefused::malformed(),
            26 => $left >= 4 ? [self::float('G', substr($bytes, $offset, 4)), $offset + 4] : throw PasskeyRefused::malformed(),
            27 => $left >= 8 ? [self::float('E', substr($bytes, $offset, 8)), $offset + 8] : throw PasskeyRefused::malformed(),
            // undefined, the other simple values, a break with no
            // indefinite item to end: nothing WebAuthn sends.
            default => throw PasskeyRefused::malformed(),
        };
    }

    /** A single- or double-precision float, big-endian. */
    private static function float(string $format, string $bytes): float
    {
        $unpacked = unpack($format, $bytes);
        $value = \is_array($unpacked) ? ($unpacked[1] ?? null) : null;

        return \is_float($value) ? $value : throw PasskeyRefused::malformed();
    }

    /** An IEEE 754 half-precision float. */
    private static function half(string $two): float
    {
        $bits = (\ord($two[0]) << 8) | \ord($two[1]);
        $sign = ($bits & 0x8000) !== 0 ? -1.0 : 1.0;
        $exponent = ($bits >> 10) & 0x1F;
        $fraction = $bits & 0x3FF;

        return $sign * match (true) {
            $exponent === 0 => $fraction * 2 ** -24,
            $exponent === 31 => $fraction === 0 ? INF : NAN,
            default => (1 + $fraction / 1024) * 2 ** ($exponent - 15),
        };
    }
}
