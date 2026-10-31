<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * What the shared throttle stores agree on, written once: the fixed window
 * a hit falls in, the name its counter is kept under, and how a batch of
 * passed windows is swept from a table.
 *
 * ApcuThrottleStore, SqliteThrottleStore and PdoThrottleStore each spelled
 * these out for themselves, in the same words; a change to one -- the
 * window's alignment, the hash -- that missed another would have had two
 * servers counting one caller differently.
 */
final class ThrottleWindow
{
    /**
     * The counter for $key's window of $window seconds that $now falls in,
     * and when that window started: windows aligned to the clock, so a
     * window's counter is one entry named for it and nothing has to reset a
     * count. Hashed: a key is built from caller identity and can be long,
     * and a store is not somewhere addresses should be listed.
     *
     * @param int<1, max> $window
     * @return array{string, int}
     */
    public static function entry(string $key, int $window, int $now): array
    {
        $start = intdiv($now, $window) * $window;

        return [hash('xxh128', $key) . ':' . $window . ':' . $start, $start];
    }

    /**
     * A statement deleting at most $limit windows of $table that passed by
     * the time it is given, bound as its one parameter. Postgres skips rows
     * another server's sweep has locked rather than waiting on them, so
     * sweepers on several servers take different rows and never deadlock;
     * MySQL takes a LIMIT on the DELETE itself and none in a subquery
     * naming its own table; SQLite deletes by a subquery.
     */
    public static function sweep(string $driver, string $table, int $limit): string
    {
        return match ($driver) {
            'pgsql' => "DELETE FROM {$table} WHERE entry IN ("
                . "SELECT entry FROM {$table} WHERE expires_at <= ? LIMIT {$limit} FOR UPDATE SKIP LOCKED)",
            'mysql' => "DELETE FROM {$table} WHERE expires_at <= ? LIMIT {$limit}",
            default => "DELETE FROM {$table} WHERE entry IN ("
                . "SELECT entry FROM {$table} WHERE expires_at <= ? LIMIT {$limit})",
        };
    }
}
