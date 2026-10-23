<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/** Parses the TTL strings attributes carry: '30s', '5m', '1h', '1d'. */
final class Duration
{
    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

    /** A year in seconds. The cap exists to catch overflow, not to legislate. */
    public const int MAX_SECONDS = 31_536_000;

    public static function seconds(string $ttl): int
    {
        if (preg_match('/^(\d+)([smhdw])$/', trim($ttl), $m) !== 1) {
            throw new \InvalidArgumentException(
                "'{$ttl}' is not a duration. Use a number and one of s, m, h, d, w -- '5m', '1h'."
            );
        }

        $amount = (int) $m[1];

        // A zero TTL writes an entry that is already expired: the cache looks
        // enabled and never hits. Say so rather than silently doing nothing.
        if ($amount === 0) {
            throw new \InvalidArgumentException("'{$ttl}' is zero. Leave cache unset instead of caching for no time.");
        }

        $seconds = $amount * self::UNITS[$m[2]];

        // Guards the multiplication overflowing to a float, which would
        // TypeError out of a method declared to return int.
        if (!\is_int($seconds) || $seconds > self::MAX_SECONDS) {
            throw new \InvalidArgumentException(
                "'{$ttl}' is longer than the " . self::MAX_SECONDS . '-second maximum.'
            );
        }

        return $seconds;
    }
}
