<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Throttle counters in APCu, shared by every worker thread of one server.
 *
 * ArrayThrottleStore counts per worker, so ten FrankenPHP workers gave
 * every limit ten times over -- the limit that looks enforced and is not.
 * APCu is shared memory for the whole process, which under FrankenPHP is
 * every worker thread, and its increment is atomic: two requests in two
 * threads can never both read a count and both write count+1.
 *
 * Not shared between servers. Several machines behind a load balancer each
 * count alone, and need a store they all reach.
 *
 * Fixed windows aligned to the clock: the window a hit falls in is
 * `floor(now / window)`, and its counter is one APCu entry named for that
 * window, created at zero and incremented. A window that has passed is a
 * different name, so nothing has to reset a counter, and APCu drops the old
 * entry when its TTL runs out. The cost of alignment is the usual one for
 * fixed windows: a caller can spend the end of one window and the start of
 * the next back to back.
 */
final class ApcuThrottleStore implements ThrottleStore
{
    /** @var \Closure(string, int, int): bool add a key at a value, with a TTL, only if absent */
    private readonly \Closure $add;

    /** @var \Closure(string, int, int): (int|false) increment a key, with a TTL, and return the new value */
    private readonly \Closure $inc;

    /**
     * @param string $prefix  set apart from anything else the application keeps in APCu
     * @param (\Closure(): int)|null $clock for tests; defaults to time()
     * @param (\Closure(string, int, int): bool)|null $add for tests; defaults to apcu_add()
     * @param (\Closure(string, int, int): (int|false))|null $inc for tests; defaults to apcu_inc()
     */
    public function __construct(
        private readonly string $prefix = 'tinvar:throttle:',
        private readonly ?\Closure $clock = null,
        ?\Closure $add = null,
        ?\Closure $inc = null,
    ) {
        if (($add === null || $inc === null) && !self::available()) {
            throw new \RuntimeException(
                'APCu is not available: load the apcu extension, and set apc.enable_cli=1 to use it from the CLI.',
            );
        }

        $this->add = $add ?? static fn (string $key, int $value, int $ttl): bool => apcu_add($key, $value, $ttl);
        $this->inc = $inc ?? static function (string $key, int $step, int $ttl): int|false {
            $success = false;

            return apcu_inc($key, $step, $success, $ttl);
        };
    }

    /** Whether APCu is loaded and enabled in this SAPI. */
    public static function available(): bool
    {
        return \function_exists('apcu_enabled') && apcu_enabled();
    }

    public function increment(string $key, int $window): array
    {
        $window = max(1, $window);
        $now = $this->clock === null ? time() : ($this->clock)();
        $start = intdiv($now, $window) * $window;

        // Hashed: a key is built from caller identity and can be long, and
        // APCu's entry names are not where addresses should be listed.
        $entry = $this->prefix . hash('xxh128', $key) . ':' . $window . ':' . $start;

        // A second past the window, so the entry outlives every hit counted
        // in it; after that it is garbage APCu collects on its own.
        $ttl = $window + 1;

        // Two tries: the entry can be evicted between the add and the
        // increment when APCu is full, and a second add recreates it.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            ($this->add)($entry, 0, $ttl);
            $count = ($this->inc)($entry, 1, $ttl);
            if (\is_int($count)) {
                return [$count, $start + $window];
            }
        }

        // A throttle that cannot count fails closed: an exception is a 500
        // the operator sees, where answering "allowed" would be a limit that
        // quietly stopped existing.
        throw new \RuntimeException('APCu would not count a throttle hit; it may be full or disabled.');
    }
}
