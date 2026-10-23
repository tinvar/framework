<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * UUIDv7 (RFC 9562): a 48-bit big-endian millisecond timestamp followed by
 * random bits, so keys sort by creation time and index well in Postgres.
 *
 * Minted in PHP on insert rather than by the database, so a model has its key
 * before it is written and the identity map can hold it immediately.
 */
final class Uuid
{
    public static function v7(?int $milliseconds = null): string
    {
        $ms = $milliseconds ?? (int) (microtime(true) * 1000);
        $bytes = pack('J', $ms << 16);          // 48-bit timestamp in the top 6 bytes
        $bytes = substr($bytes, 0, 6) . random_bytes(10);

        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x70);   // version 7
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);   // RFC 9562 variant

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    public static function isValid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $uuid);
    }

    /** Milliseconds encoded in a v7 key, for ordering assertions in tests. */
    public static function timestampOf(string $uuid): int
    {
        $hex = str_replace('-', '', $uuid);

        return (int) hexdec(substr($hex, 0, 12));
    }
}
