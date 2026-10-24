<?php

declare(strict_types=1);

/**
 * RedisThrottleStore: counters in Redis, shared by every server reaching it.
 *
 * Against a real redis-server -- one named by TINVAR_TEST_REDIS, or one
 * started for this run from the PATH -- and skipped, saying why, without one.
 */

namespace Tinvar\Tests\Http\RedisThrottleStore;

use Tinvar\Http\RedisThrottleStore;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

/** A prefix of the test's own, so tests sharing the server never share a key. */
function prefix(): string
{
    return 'tinvar:test:' . bin2hex(random_bytes(4)) . ':';
}

/** @param (\Closure(): int)|null $clock */
function store(string $prefix, ?\Closure $clock = null): RedisThrottleStore
{
    return new RedisThrottleStore(Databases::redisClient(), $prefix, $clock);
}

describe('against Redis', function () {
    it('counts hits in a window aligned to the clock, and starts again in the next', function () {
        $now = 1_000_030;
        $store = store(prefix(), static function () use (&$now): int {
            return $now;
        });

        expect($store->increment('k', 60))->toBe([1, 1_000_080])
            ->and($store->increment('k', 60))->toBe([2, 1_000_080])
            ->and($store->increment('other', 60))->toBe([1, 1_000_080])
            ->and($store->increment('k', 900)[0])->toBe(1, 'the same key over another window is another count');

        $now += 60;
        expect($store->increment('k', 60))->toBe([1, 1_000_140]);
    });

    it('takes back hits in the current window, never below zero and never opening one', function () {
        $prefix = prefix();
        $now = 1_000_000;
        $store = store($prefix, static function () use (&$now): int {
            return $now;
        });

        $store->increment('k', 60);
        $store->increment('k', 60);
        $store->release('k', 60);
        expect($store->increment('k', 60)[0])->toBe(2);

        $store->release('k', 60, 5);
        expect($store->increment('k', 60)[0])->toBe(1, 'released past zero, it stopped at zero');

        $store->release('k', 60, PHP_INT_MAX);
        expect($store->increment('k', 60)[0])->toBe(1, 'cleared');

        $store->release('never-counted', 60);
        expect(Databases::redisClient()->keys("{$prefix}*"))->toHaveCount(1, 'no key made for a window never opened');

        $now += 60;
        $store->release('k', 60);
        expect($store->increment('k', 60)[0])->toBe(1, 'a passed window gives nothing back to the next');
    });

    // Nothing sweeps Redis: every counter carries its own expiry, set in the
    // same MULTI as the count, and lasting a second past its window.
    it('expires every counter a second past its window', function () {
        $prefix = prefix();
        $store = store($prefix);
        [, $resetsAt] = $store->increment('k', 60);
        $store->increment('k', 60);

        $redis = Databases::redisClient();
        $keys = $redis->keys("{$prefix}*");
        expect($keys)->toHaveCount(1);

        $ttl = $redis->ttl($keys[0]);
        expect($ttl)->toBeGreaterThan(0)
            ->and($ttl)->toBeLessThanOrEqual($resetsAt + 1 - time() + 1);
    });

    // An expiry worked out from this server's clock, not Redis's: a clock
    // far from Redis's still leaves the counter living out its window.
    it('keeps counting on a server whose clock is far from Redis\'s', function () {
        $prefix = prefix();
        $store = store($prefix, static fn (): int => time() + 86_400);

        $store->increment('k', 60);
        expect($store->increment('k', 60)[0])->toBe(2)
            ->and(Databases::redisClient()->ttl($prefix . hash('xxh128', 'k') . ':60:' . intdiv(time() + 86_400, 60) * 60))
            ->toBeGreaterThan(0);
    });

    it('shares its counts with every store on the same Redis', function () {
        $prefix = prefix();
        store($prefix)->increment('k', 60);
        store($prefix)->increment('k', 60);

        expect(store($prefix)->increment('k', 60)[0])->toBe(3);
    });

    it('keeps the caller\'s key out of Redis', function () {
        $prefix = prefix();
        store($prefix)->increment('actions|ip|203.0.113.7', 60);

        expect(implode('', Databases::redisClient()->keys("{$prefix}*")))->not->toContain('203.0.113.7');
    });

    // A key holding something INCR cannot count fails the request, rather
    // than answering "allowed" for a limit that quietly stopped existing.
    it('fails closed when Redis will not count', function () {
        $prefix = prefix();
        $now = 999_960;
        Databases::redisClient()->set($prefix . hash('xxh128', 'k') . ':60:' . $now, 'not a number');

        expect(fn () => store($prefix, static fn (): int => $now)->increment('k', 60))
            ->toThrow(\RuntimeException::class, 'would not count');
    });

    it('connects only when it counts', function () {
        $connected = 0;
        $store = new RedisThrottleStore(static function () use (&$connected): \Redis {
            $connected++;

            return Databases::redisClient();
        }, prefix());

        expect($connected)->toBe(0);

        $store->increment('k', 60);
        $store->increment('k', 60);
        expect($connected)->toBe(1);
    });

    // Atomic, or the limit is advisory: processes counting one key at once
    // must between them see every count from 1 to N exactly once.
    it('counts every hit once across processes counting at the same moment', function () {
        $at = Databases::redis();
        if (\is_string($at)) {
            throw new \LogicException($at);
        }
        $prefix = prefix();
        $processes = 6;
        $each = 40;
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = sys_get_temp_dir() . '/tinvar-redis-throttle-count-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $autoload, $host, $port, $prefix, $start, $each] = $argv;
            require $autoload;
            $redis = new Redis();
            $redis->connect($host, (int) $port, 2.0);
            $store = new Tinvar\Http\RedisThrottleStore($redis, $prefix);
            while (microtime(true) < (float) $start) {
                usleep(1000);
            }
            $counts = [];
            for ($i = 0; $i < (int) $each; $i++) {
                $counts[] = $store->increment('shared', 3600)[0];
            }
            echo json_encode($counts);
            PHP);

        try {
            $start = (string) (microtime(true) + 1.5);
            $running = [];
            $outputs = [];
            for ($p = 0; $p < $processes; $p++) {
                $running[] = proc_open(
                    [PHP_BINARY, $script, $autoload, $at[0], (string) $at[1], $prefix, $start, (string) $each],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                ) ?: throw new \RuntimeException('Could not start a counting process.');
                $outputs[] = $pipes;
            }

            $counts = [];
            foreach ($running as $p => $process) {
                $out = stream_get_contents($outputs[$p][1]);
                $err = stream_get_contents($outputs[$p][2]);
                proc_close($process);
                expect($err)->toBe('');
                array_push($counts, ...json_decode((string) $out, true, flags: JSON_THROW_ON_ERROR));
            }
            sort($counts);

            expect($counts)->toBe(range(1, $processes * $each));
        } finally {
            unlink($script);
        }
    });
})->group('redis')->skip(Databases::redisMissing() !== null, Databases::redisMissing() ?? '');
