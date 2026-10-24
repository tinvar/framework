<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Throttle counters in Redis, shared by every server that reaches it.
 *
 * The other way to count across machines than PdoThrottleStore, for a
 * deployment that runs Redis already: a hit is one round trip, and passed
 * windows expire on their own, with nothing to sweep.
 *
 * Fixed windows aligned to the clock, as the other stores keep them: a
 * window's counter is one key named for the window. A hit is INCR and
 * EXPIRE in one MULTI, so the count and its expiry land together and no
 * key is left counting forever. The expiry is relative, worked out from
 * this server's clock: an absolute EXPIREAT read against Redis's clock
 * would, on a server whose clock ran ahead of Redis's by a window, expire
 * every counter as it was made -- every hit the first.
 *
 * Needs ext-redis. Opened on first use when given as a closure: a request
 * that never meets a throttle never connects.
 */
final class RedisThrottleStore implements ThrottleStore
{
    /**
     * Takes hits back, never below zero and never making a key: a DECRBY
     * alone on a key that has expired would open a window at minus the
     * hits -- spare hits for whoever comes next. In one script, so no
     * other server's hit lands between the read and the write.
     */
    private const string RELEASE = <<<'LUA'
        local count = tonumber(redis.call('GET', KEYS[1]))
        if not count or count <= 0 then
            return 0
        end
        local take = tonumber(ARGV[1])
        if take > count then
            take = count
        end
        return redis.call('DECRBY', KEYS[1], take)
        LUA;

    private ?\Redis $redis = null;

    /**
     * @param \Redis|(\Closure(): \Redis) $connection a connected client, or what connects one
     * @param string $prefix set apart from anything else kept in the same Redis
     * @param (\Closure(): int)|null $clock for tests; defaults to time()
     */
    public function __construct(
        private readonly \Redis|\Closure $connection,
        private readonly string $prefix = 'tinvar:throttle:',
        private readonly ?\Closure $clock = null,
    ) {}

    /** Whether ext-redis is loaded. */
    public static function available(): bool
    {
        return \extension_loaded('redis');
    }

    public function increment(string $key, int $window): array
    {
        $window = max(1, $window);
        [$entry, $start] = $this->entry($key, $window);
        $resetsAt = $start + $window;

        // A second past the window, so the key outlives every hit counted in
        // it; after that Redis drops it.
        $redis = $this->redis();
        $redis->multi();
        $redis->incr($entry);
        $redis->expire($entry, max(1, $resetsAt + 1 - $this->now()));
        $replies = $redis->exec();

        // A throttle that cannot count fails closed, as the other stores do.
        $hits = \is_array($replies) ? ($replies[0] ?? null) : null;
        if (!\is_int($hits)) {
            throw new \RuntimeException('Redis would not count a throttle hit.');
        }

        return [$hits, $resetsAt];
    }

    public function release(string $key, int $window, int $hits = 1): void
    {
        [$entry] = $this->entry($key, max(1, $window));

        // Redis's EVAL runs the constant script above; the key and the count
        // travel as its KEYS and ARGV and are never part of the script.
        $this->redis()->eval(self::RELEASE, [$entry, (string) max(0, $hits)], 1);
    }

    /**
     * The key counting a key's current window, and when that window started.
     *
     * @return array{string, int}
     */
    private function entry(string $key, int $window): array
    {
        $start = intdiv($this->now(), $window) * $window;

        // Hashed: a key is built from caller identity and can be long, and
        // Redis's key space is not where addresses should be listed.
        return [$this->prefix . hash('xxh128', $key) . ':' . $window . ':' . $start, $start];
    }

    private function redis(): \Redis
    {
        return $this->redis ??= $this->connection instanceof \Redis ? $this->connection : ($this->connection)();
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
