<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/** Parses the TTL strings attributes carry: '30s', '5m', '1h', '1d'. */
final class Duration
{
    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

    public static function seconds(string $ttl): int
    {
        if (preg_match('/^(\d+)([smhdw])$/', trim($ttl), $m) !== 1) {
            throw new \InvalidArgumentException(
                "'{$ttl}' is not a duration. Use a number and one of s, m, h, d, w -- '5m', '1h'."
            );
        }

        return (int) $m[1] * self::UNITS[$m[2]];
    }
}
