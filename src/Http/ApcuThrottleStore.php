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
 *
 * **APCu forgets everything at once.** Full of entries still live, APCu
 * clears the whole cache rather than make room -- whatever `apc.ttl` says,
 * measured on 5.1.28 -- and every count goes with it; anyone can fill it,
 * since each new address -- an IPv6 /64 -- is new entries. So a limit that guards something an
 * attacker would guess at, such as passwords, does not count here at all,
 * but in a store nothing else fills. What stays here is a budget against
 * load, and for it this store says when a wipe happened: an entry set with
 * no TTL, which only a wipe takes, is missing. `$wiped` is told, for the
 * operator to size APCu by (`apc.shm_size`); and a store built to fail
 * closed counts every window that began before the wipe as spent, until it
 * ends, rather than starting it again from nothing. That turns every wipe
 * into a pause for every caller, which a budget against load is better
 * without, so it is not the default.
 *
 * A worker starting into a cache that was wiped before any count -- the
 * mark gone, and nothing yet to say it ever stood -- is told apart from the
 * cache starting by APCu's own count of expunges (ADR 0125): a cache that
 * has never had to clear itself to make room is new; one that has, was
 * filled. That worker marks the wipe as any other worker finding one does.
 * An `apcu_clear_cache()` the application calls itself is not an expunge,
 * and still reads as a start: whoever can call it can do worse.
 */
final class ApcuThrottleStore implements ThrottleStore
{
    /** @var \Closure(string, int, int): bool add a key at a value, with a TTL, only if absent */
    private readonly \Closure $add;

    /** @var \Closure(string, int, int): (int|false) increment a key, with a TTL, and return the new value */
    private readonly \Closure $inc;

    /** @var \Closure(string): mixed a key's value, or false when there is none */
    private readonly \Closure $fetch;

    /** @var \Closure(string, int, int): bool replace a key's value only if it is still the one read */
    private readonly \Closure $cas;

    /** A window counted as spent once a wipe has taken what it held: past any limit, with room to count. */
    public const int LOST = PHP_INT_MAX >> 1;

    /** The entry with no TTL, holding when counting last started: gone, APCu has been cleared. */
    private readonly string $mark;

    /**
     * @param string $prefix  set apart from anything else the application keeps in APCu
     * @param (\Closure(): int)|null $clock for tests; defaults to time()
     * @param (\Closure(string, int, int): bool)|null $add for tests; defaults to apcu_add()
     * @param (\Closure(string, int, int): (int|false))|null $inc for tests; defaults to apcu_inc()
     * @param (\Closure(string): mixed)|null $fetch for tests; defaults to apcu_fetch()
     * @param (\Closure(string, int, int): bool)|null $cas for tests; defaults to apcu_cas()
     * @param bool $failClosed whether a window that began before a wipe counts as spent until it ends
     * @param (\Closure(): void)|null $wiped told when this store finds APCu has been cleared
     * @param (\Closure(): int)|null $expunges for tests; how many times APCu has cleared itself
     *        to make room since it started, from apcu_cache_info()
     */
    public function __construct(
        private readonly string $prefix = 'tinvar:throttle:',
        private readonly ?\Closure $clock = null,
        ?\Closure $add = null,
        ?\Closure $inc = null,
        ?\Closure $fetch = null,
        ?\Closure $cas = null,
        private readonly bool $failClosed = false,
        private readonly ?\Closure $wiped = null,
        ?\Closure $expunges = null,
    ) {
        if (($add === null || $inc === null || $fetch === null || $cas === null) && !self::available()) {
            throw new \RuntimeException(
                'APCu is not available: load the apcu extension, and set apc.enable_cli=1 to use it from the CLI.',
            );
        }

        $this->add = $add ?? static fn (string $key, int $value, int $ttl): bool => apcu_add($key, $value, $ttl);
        $this->inc = $inc ?? static function (string $key, int $step, int $ttl): int|false {
            $success = false;

            return apcu_inc($key, $step, $success, $ttl);
        };
        $this->fetch = $fetch ?? static fn (string $key): mixed => apcu_fetch($key);
        $this->cas = $cas ?? static fn (string $key, int $old, int $new): bool => apcu_cas($key, $old, $new);

        // From the first worker to start into a cache that has never cleared
        // itself: nothing was counted before it, so nothing has been lost.
        // Into one that has, the mark it finds missing was taken by a wipe,
        // and is put back as any worker finding one puts it back.
        $this->mark = $prefix . 'counting-since';
        $expunges ??= static function (): int {
            $info = \function_exists('apcu_cache_info') ? apcu_cache_info(true) : false;

            return \is_array($info) && \is_int($info['expunges'] ?? null) ? $info['expunges'] : 0;
        };
        if ($expunges() === 0) {
            ($this->add)($this->mark, 0, 0);
        } else {
            $this->countingSince();
        }
    }

    /** Whether APCu is loaded and enabled in this SAPI. */
    public static function available(): bool
    {
        return \function_exists('apcu_enabled') && apcu_enabled();
    }

    public function increment(string $key, int $window): array
    {
        $window = max(1, $window);
        [$entry, $start] = $this->entry($key, $window);
        $since = $this->countingSince();

        // A second past the window, so the entry outlives every hit counted
        // in it; after that it is garbage APCu collects on its own.
        $ttl = $window + 1;

        // What a window opens at: nothing, unless a wipe has taken what it
        // held and this store fails closed -- then spent, until it ends.
        $opening = $this->failClosed && $start < $since ? self::LOST : 0;

        // Two tries: the entry can be evicted between the add and the
        // increment when APCu is full, and a second add recreates it.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            ($this->add)($entry, $opening, $ttl);
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

    public function release(string $key, int $window, int $hits = 1): void
    {
        [$entry] = $this->entry($key, max(1, $window));

        // Compare-and-swap rather than apcu_dec: a decrement creates an
        // entry that is not there, which would open a window at -1 -- a
        // spare hit for whoever comes next. Retried while other requests
        // move the count under it; if it never holds still, the hits stay
        // counted, which errs on the side of the limit.
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $count = ($this->fetch)($entry);
            if (!\is_int($count) || $count <= 0) {
                return;
            }
            if (($this->cas)($entry, $count, max(0, $count - max(0, $hits)))) {
                return;
            }
        }
    }

    /**
     * When counting last started from nothing: 0 for as long as APCu has
     * kept everything since the first worker, and the moment this store
     * found it cleared otherwise -- put back by whichever worker finds it
     * first, which tells $wiped.
     */
    private function countingSince(): int
    {
        $since = ($this->fetch)($this->mark);
        if (\is_int($since)) {
            return $since;
        }

        $now = $this->now();
        if (!($this->add)($this->mark, $now, 0)) {
            $since = ($this->fetch)($this->mark);

            return \is_int($since) ? $since : $now;
        }

        if ($this->wiped !== null) {
            ($this->wiped)();
        }

        return $now;
    }

    /**
     * The APCu entry counting a key's current window, and when that window
     * started.
     *
     * @return array{string, int}
     */
    private function entry(string $key, int $window): array
    {
        [$entry, $start] = ThrottleWindow::entry($key, max(1, $window), $this->now());

        return [$this->prefix . $entry, $start];
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
