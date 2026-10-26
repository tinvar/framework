<?php

declare(strict_types=1);

/**
 * ApcuThrottleStore's counting, against an in-memory stand-in for APCu's
 * add and increment, and -- where the extension is loaded -- against APCu
 * itself. That it counts across worker threads is shown under FrankenPHP,
 * in ApcuAcrossWorkersTest.
 */

namespace Tinvar\Tests\Http\ApcuThrottleStore;

use Tinvar\Http\ApcuThrottleStore;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\SqliteThrottleStore;
use Tinvar\Http\ThrottleStore;

/** APCu's add and increment, in memory, with TTLs honoured against the given clock. */
final class FakeApcu
{
    /** @var array<string, array{value: int, expires: int}> */
    public array $entries = [];

    /** How many increments to answer with false before working, as an eviction would. */
    public int $failIncrements = 0;

    public int $now = 1_000_000;

    public function add(): \Closure
    {
        return function (string $key, int $value, int $ttl): bool {
            if (isset($this->entries[$key]) && $this->entries[$key]['expires'] > $this->now) {
                return false;
            }
            // A TTL of 0 is none: the entry stays until APCu is cleared.
            $this->entries[$key] = ['value' => $value, 'expires' => $ttl === 0 ? PHP_INT_MAX : $this->now + $ttl];

            return true;
        };
    }

    public function inc(): \Closure
    {
        return function (string $key, int $step, int $ttl): int|false {
            if ($this->failIncrements > 0) {
                $this->failIncrements--;
                unset($this->entries[$key]);

                return false;
            }
            if (!isset($this->entries[$key]) || $this->entries[$key]['expires'] <= $this->now) {
                return false;
            }

            return $this->entries[$key]['value'] += $step;
        };
    }

    public function fetch(): \Closure
    {
        return fn (string $key): int|false => isset($this->entries[$key]) && $this->entries[$key]['expires'] > $this->now
            ? $this->entries[$key]['value']
            : false;
    }

    public function cas(): \Closure
    {
        return function (string $key, int $old, int $new): bool {
            if (($this->fetch())($key) !== $old) {
                return false;
            }
            $this->entries[$key]['value'] = $new;

            return true;
        };
    }

    /** @param (\Closure(): void)|null $wiped */
    public function store(bool $failClosed = false, ?\Closure $wiped = null): ApcuThrottleStore
    {
        return new ApcuThrottleStore(
            clock: fn (): int => $this->now,
            add: $this->add(),
            inc: $this->inc(),
            fetch: $this->fetch(),
            cas: $this->cas(),
            failClosed: $failClosed,
            wiped: $wiped,
        );
    }

    /** What APCu does once it is full, with `apc.ttl` at 0: every entry goes at once. */
    public function wipe(): void
    {
        $this->entries = [];
    }
}

it('counts hits in a window and says when it resets', function () {
    $apcu = new FakeApcu();
    $apcu->now = 1_000_030;
    $store = $apcu->store();

    // Aligned to the clock: the minute that started at 1_000_020.
    expect($store->increment('k', 60))->toBe([1, 1_000_080])
        ->and($store->increment('k', 60))->toBe([2, 1_000_080])
        ->and($store->increment('k', 60))->toBe([3, 1_000_080]);
});

it('starts again from one in the next window', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();

    $store->increment('k', 60);
    $store->increment('k', 60);
    $apcu->now += 60;

    expect($store->increment('k', 60)[0])->toBe(1);
});

it('keeps keys and window lengths apart', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();

    $store->increment('a', 60);
    $store->increment('a', 60);

    expect($store->increment('b', 60)[0])->toBe(1)
        ->and($store->increment('a', 900)[0])->toBe(1, 'the same key over another window is another count');
});

// An entry evicted between the add and the increment is created again.
it('counts a hit whose entry was evicted mid-way', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $apcu->failIncrements = 1;

    expect($store->increment('k', 60)[0])->toBe(1);
});

// A limit that cannot count must not read as "allowed".
it('fails closed when APCu will not count', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $apcu->failIncrements = 2;

    expect(fn () => $store->increment('k', 60))->toThrow(\RuntimeException::class, 'would not count');
});

it('keeps the caller\'s key out of the entry name', function () {
    $apcu = new FakeApcu();
    $apcu->store()->increment('actions|ip|203.0.113.7', 60);

    foreach (array_keys($apcu->entries) as $entry) {
        expect($entry)->toStartWith('tinvar:throttle:')
            ->not->toContain('203.0.113.7');
    }
});

// APCu clears every entry once it is full, and anyone can fill it. A budget
// against load starts again from nothing, as it would after a restart; the
// operator is told, to size APCu by.
it('tells when APCu has been cleared, once, and counts on from nothing', function () {
    $apcu = new FakeApcu();
    $wipes = 0;
    $store = $apcu->store(wiped: static function () use (&$wipes): void {
        $wipes++;
    });

    $store->increment('k', 60);
    $store->increment('k', 60);
    expect($wipes)->toBe(0, 'a store starting on an empty APCu is not told of a wipe');

    $apcu->wipe();

    expect($store->increment('k', 60)[0])->toBe(1)
        ->and($store->increment('k', 60)[0])->toBe(2)
        ->and($wipes)->toBe(1)
        // Another worker's store, on the same APCu, finds it put back.
        ->and($apcu->store()->increment('k', 60)[0])->toBe(3)
        ->and($wipes)->toBe(1);
});

// Built to fail closed, a wipe pauses every window it reached into, as
// spent, until it ends: what those windows held is not known, and a limit
// that cannot count fails closed. Windows opened since count as before.
it('counts a window a wipe reached into as spent until it ends, when built to fail closed', function () {
    $apcu = new FakeApcu();
    $apcu->now = 1_000_030;
    $store = $apcu->store(failClosed: true);

    expect($store->increment('k', 60))->toBe([1, 1_000_080]);

    $apcu->wipe();

    expect($store->increment('k', 60))->toBe([ApcuThrottleStore::LOST + 1, 1_000_080])
        ->and($store->increment('never-counted', 60)[0])->toBeGreaterThan(ApcuThrottleStore::LOST);

    // The next window counts from nothing.
    $apcu->now = 1_000_080;
    expect($store->increment('k', 60))->toBe([1, 1_000_140]);
});

// A worker starting on an empty APCu is the cache starting, not a wipe:
// nothing was counted before it, so a deploy pauses nobody.
it('pauses nobody when it starts, built to fail closed', function () {
    $apcu = new FakeApcu();

    expect($apcu->store(failClosed: true)->increment('k', 60)[0])->toBe(1);
});

it('treats a window under a second as one second', function () {
    $apcu = new FakeApcu();

    expect($apcu->store()->increment('k', 0))->toBe([1, $apcu->now + 1]);
});

it('says plainly when APCu is not there', function () {
    expect(fn () => new ApcuThrottleStore())->toThrow(\RuntimeException::class, 'APCu is not available');
})->skip(ApcuThrottleStore::available(), 'APCu is loaded here.');

// The store a Throttle is built with, so it is held to the same contract
// as the array store it stands in for.
it('answers the throttle store contract as the array store does', function (ThrottleStore $store) {
    $first = $store->increment('contract', 30);
    $second = $store->increment('contract', 30);

    expect($second[0])->toBe($first[0] + 1)
        ->and($second[1])->toBe($first[1])
        ->and($first[1])->toBeGreaterThan(time() - 1);
})->with([
    'array' => [fn (): ThrottleStore => new ArrayThrottleStore()],
    'apcu, in memory' => [fn (): ThrottleStore => (static function (): ApcuThrottleStore {
        $apcu = new FakeApcu();
        $apcu->now = time();

        return $apcu->store();
    })()],
    'sqlite, in memory' => [fn (): ThrottleStore => new SqliteThrottleStore(':memory:')],
]);

// A budget that counts only failures counts every attempt first and takes
// back the ones that succeeded.
it('takes back hits in the current window, never below zero', function (ThrottleStore $store) {
    $store->increment('released', 60);
    $store->increment('released', 60);
    $store->increment('released', 60);
    $store->release('released', 60);

    expect($store->increment('released', 60)[0])->toBe(3);

    $store->release('released', 60, PHP_INT_MAX);
    expect($store->increment('released', 60)[0])->toBe(1, 'cleared');

    $store->release('never-counted', 60);
    expect($store->increment('never-counted', 60)[0])->toBe(1, 'no window was opened below zero');
})->with([
    'array' => [fn (): ThrottleStore => new ArrayThrottleStore()],
    'apcu, in memory' => [fn (): ThrottleStore => (static function (): ApcuThrottleStore {
        $apcu = new FakeApcu();
        $apcu->now = time();

        return $apcu->store();
    })()],
    'sqlite, in memory' => [fn (): ThrottleStore => new SqliteThrottleStore(':memory:')],
]);

it('takes nothing back from a window that has passed', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();

    $store->increment('k', 60);
    $apcu->now += 60;
    $store->release('k', 60);

    expect($store->increment('k', 60)[0])->toBe(1)
        ->and($store->increment('k', 60)[0])->toBe(2);
});

describe('against APCu itself', function () {
    it('counts with real atomic increments', function () {
        $store = new ApcuThrottleStore('tinvar:test:' . bin2hex(random_bytes(4)) . ':');

        expect($store->increment('k', 60)[0])->toBe(1)
            ->and($store->increment('k', 60)[0])->toBe(2);

        $store->release('k', 60);
        expect($store->increment('k', 60)[0])->toBe(2);
    });
})->skip(!ApcuThrottleStore::available(), 'APCu is not loaded (or apc.enable_cli is off) in this PHP.');
