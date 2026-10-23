<?php

declare(strict_types=1);

namespace Tinvar\Codec;

/**
 * Pure-PHP MessagePack, covering the subset the socket protocol uses:
 * nil, bool, int64, float64, str, bin, array and map.
 *
 * ext-msgpack is faster and encodes when loaded (see MessagePackCodec).
 * Decoding is always this: it reads what the renderer sends, and the
 * renderer is semi-trusted.
 */
final class MessagePack
{
    /** Nesting deeper than any protocol frame has; a bomb is all brackets. */
    public const int MAX_DEPTH = 64;

    /**
     * Array and map elements per decode. A PHP array costs far more memory
     * than its wire bytes, so a 2 MB frame of tiny elements took 234 MB, and
     * a frame under the size cap took the process past its memory limit --
     * a fatal error, which no catch can answer.
     */
    public const int MAX_ELEMENTS = 65_536;

    public static function encode(mixed $value): string
    {
        return self::encodeValue($value);
    }

    /** @param int $offset consumed position, advanced in place */
    public static function decode(string $bytes, int &$offset = 0): mixed
    {
        $budget = self::MAX_ELEMENTS;

        return self::decodeValue($bytes, $offset, 0, $budget);
    }

    private static function encodeValue(mixed $v): string
    {
        return match (true) {
            $v === null => "\xc0",
            $v === true => "\xc3",
            $v === false => "\xc2",
            \is_int($v) => self::encodeInt($v),
            \is_float($v) => "\xcb" . pack('E', $v),
            \is_string($v) => self::encodeStr($v),
            $v instanceof Bin => self::encodeBin($v->bytes),
            \is_array($v) => array_is_list($v) ? self::encodeArray($v) : self::encodeMap($v),
            default => throw CodecException::unsupported($v),
        };
    }

    private static function encodeInt(int $v): string
    {
        if ($v >= 0) {
            return match (true) {
                $v < 0x80 => \chr($v),
                $v <= 0xff => "\xcc" . \chr($v),
                $v <= 0xffff => "\xcd" . pack('n', $v),
                $v <= 0xffffffff => "\xce" . pack('N', $v),
                default => "\xcf" . pack('J', $v),
            };
        }

        return match (true) {
            $v >= -0x20 => \chr(0xe0 | ($v + 0x20)),
            $v >= -0x80 => "\xd0" . pack('c', $v),
            $v >= -0x8000 => "\xd1" . pack('n', $v & 0xffff),
            $v >= -0x80000000 => "\xd2" . pack('N', $v & 0xffffffff),
            default => "\xd3" . pack('J', $v),
        };
    }

    private static function encodeStr(string $v): string
    {
        $n = \strlen($v);

        return match (true) {
            $n < 0x20 => \chr(0xa0 | $n) . $v,
            $n <= 0xff => "\xd9" . \chr($n) . $v,
            $n <= 0xffff => "\xda" . pack('n', $n) . $v,
            default => "\xdb" . pack('N', $n) . $v,
        };
    }

    private static function encodeBin(string $v): string
    {
        $n = \strlen($v);

        return match (true) {
            $n <= 0xff => "\xc4" . \chr($n) . $v,
            $n <= 0xffff => "\xc5" . pack('n', $n) . $v,
            default => "\xc6" . pack('N', $n) . $v,
        };
    }

    /** @param list<mixed> $v */
    private static function encodeArray(array $v): string
    {
        $n = \count($v);
        $out = match (true) {
            $n < 0x10 => \chr(0x90 | $n),
            $n <= 0xffff => "\xdc" . pack('n', $n),
            default => "\xdd" . pack('N', $n),
        };
        foreach ($v as $item) {
            $out .= self::encodeValue($item);
        }

        return $out;
    }

    /** @param array<array-key, mixed> $v */
    private static function encodeMap(array $v): string
    {
        $n = \count($v);
        $out = match (true) {
            $n < 0x10 => \chr(0x80 | $n),
            $n <= 0xffff => "\xde" . pack('n', $n),
            default => "\xdf" . pack('N', $n),
        };
        foreach ($v as $k => $item) {
            $out .= \is_int($k) ? self::encodeInt($k) : self::encodeStr((string) $k);
            $out .= self::encodeValue($item);
        }

        return $out;
    }

    private static function decodeValue(string $b, int &$o, int $depth, int &$budget): mixed
    {
        $c = self::byte($b, $o);

        if ($c <= 0x7f) {
            return $c;                       // positive fixint
        }
        if ($c >= 0xe0) {
            return $c - 0x100;               // negative fixint
        }
        // Ranges in ascending order: each test's lower bound is the previous
        // one's upper bound, already excluded above.
        if ($c <= 0x8f) {
            return self::decodeMap($b, $o, $c & 0x0f, $depth, $budget);
        }
        if ($c <= 0x9f) {
            return self::decodeArray($b, $o, $c & 0x0f, $depth, $budget);
        }
        if ($c <= 0xbf) {
            return self::take($b, $o, $c & 0x1f);
        }

        return match ($c) {
            0xc0 => null,
            0xc2 => false,
            0xc3 => true,
            0xc4 => self::take($b, $o, self::uint8($b, $o)),
            0xc5 => self::take($b, $o, self::uint16($b, $o)),
            0xc6 => self::take($b, $o, self::uint32($b, $o)),
            0xca => self::float($b, $o, 'G', 4),
            0xcb => self::float($b, $o, 'E', 8),
            0xcc => self::uint8($b, $o),
            0xcd => self::uint16($b, $o),
            0xce => self::uint32($b, $o),
            0xcf => self::uint64($b, $o),
            0xd0 => self::int($b, $o, 'c', 1),
            0xd1 => self::int16($b, $o),
            0xd2 => self::int32($b, $o),
            0xd3 => self::int64($b, $o),
            0xd9 => self::take($b, $o, self::uint8($b, $o)),
            0xda => self::take($b, $o, self::uint16($b, $o)),
            0xdb => self::take($b, $o, self::uint32($b, $o)),
            0xdc => self::decodeArray($b, $o, self::uint16($b, $o), $depth, $budget),
            0xdd => self::decodeArray($b, $o, self::uint32($b, $o), $depth, $budget),
            0xde => self::decodeMap($b, $o, self::uint16($b, $o), $depth, $budget),
            0xdf => self::decodeMap($b, $o, self::uint32($b, $o), $depth, $budget),
            default => throw CodecException::badFormat($c, $o - 1),
        };
    }

    /** @return list<mixed> */
    private static function decodeArray(string $b, int &$o, int $n, int $depth, int &$budget): array
    {
        self::admit($b, $o, $n, $n, $depth, $budget);

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::decodeValue($b, $o, $depth + 1, $budget);
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    private static function decodeMap(string $b, int &$o, int $n, int $depth, int &$budget): array
    {
        self::admit($b, $o, $n, 2 * $n, $depth, $budget);

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $k = self::decodeValue($b, $o, $depth + 1, $budget);
            if (!\is_int($k) && !\is_string($k)) {
                throw new CodecException('Map keys must be int or string.');
            }
            $out[$k] = self::decodeValue($b, $o, $depth + 1, $budget);
        }

        return $out;
    }

    /**
     * Refuses a container before any of it is built: too deep, more
     * elements than the frame has budget for, or more than the bytes left
     * could hold -- every element takes at least one.
     */
    private static function admit(string $b, int $o, int $n, int $values, int $depth, int &$budget): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new CodecException('Frame nests deeper than ' . self::MAX_DEPTH . ' levels.');
        }
        if ($values > \strlen($b) - $o) {
            throw CodecException::truncated($o + $values, \strlen($b));
        }
        $budget -= $n;
        if ($budget < 0) {
            throw new CodecException('Frame holds more than ' . self::MAX_ELEMENTS . ' elements.');
        }
    }

    private static function byte(string $b, int &$o): int
    {
        if ($o >= \strlen($b)) {
            throw CodecException::truncated($o + 1, \strlen($b));
        }

        return \ord($b[$o++]);
    }

    private static function take(string $b, int &$o, int $n): string
    {
        if ($o + $n > \strlen($b)) {
            throw CodecException::truncated($o + $n, \strlen($b));
        }
        $s = substr($b, $o, $n);
        $o += $n;

        return $s;
    }

    private static function uint8(string $b, int &$o): int
    {
        return self::byte($b, $o);
    }

    private static function uint16(string $b, int &$o): int
    {
        return (int) self::unpacked('n', self::take($b, $o, 2));
    }

    private static function uint32(string $b, int &$o): int
    {
        return (int) self::unpacked('N', self::take($b, $o, 4));
    }

    private static function uint64(string $b, int &$o): int
    {
        $v = (int) self::unpacked('J', self::take($b, $o, 8));
        if ($v < 0) {
            throw new CodecException('uint64 exceeds PHP_INT_MAX and cannot be represented.');
        }

        return $v;
    }

    private static function int(string $b, int &$o, string $fmt, int $n): int
    {
        return (int) self::unpacked($fmt, self::take($b, $o, $n));
    }

    private static function int16(string $b, int &$o): int
    {
        $v = self::uint16($b, $o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function int32(string $b, int &$o): int
    {
        $v = (int) self::unpacked('N', self::take($b, $o, 4));

        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private static function int64(string $b, int &$o): int
    {
        return (int) self::unpacked('J', self::take($b, $o, 8));
    }

    private static function float(string $b, int &$o, string $fmt, int $n): float
    {
        return (float) self::unpacked($fmt, self::take($b, $o, $n));
    }

    /** One value from unpack(), which reports a short buffer as false. */
    private static function unpacked(string $format, string $bytes): int|float
    {
        $values = unpack($format, $bytes);
        // take() always hands over exactly the bytes the format reads, so
        // this cannot happen; it is here because unpack() is typed to allow it.
        // @codeCoverageIgnoreStart
        if ($values === false) {
            throw new CodecException("Truncated value: cannot unpack '{$format}'.");
        }
        // @codeCoverageIgnoreEnd

        return $values[1];
    }
}
