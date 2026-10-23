<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\Hardening;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;

describe('redirect targets', function () {
    // Browsers decode %2e%2e as .. when they resolve a path: /%2e%2e//host
    // lands on //host, which location.assign() reads as another site.
    it('refuses an encoded traversal segment', function (string $target) {
        expect(Response::isSameOriginTarget($target))->toBeFalse();
    })->with(['/%2e%2e//evil.example', '/a/%2E%2e/b', '/a/.%2e/b', '/a/%2e./b', '/../x']);

    it('still accepts an ordinary path with dots in it', function (string $target) {
        expect(Response::isSameOriginTarget($target))->toBeTrue();
    })->with(['/racks/r1', '/files/a..b', '/x?back=/../y', '/v1.2/notes']);
});

describe('throttling by address', function () {
    /** @param \Closure(Request): string $addressOf */
    $throttle = static fn (\Closure $addressOf): Throttle => new Throttle(new ArrayThrottleStore(), new AuthContext(), $addressOf);

    // One IPv6 subscriber holds a whole /64; per-address counting gave each
    // of them 2^64 budgets.
    it('counts every address in one IPv6 /64 together', function () use ($throttle) {
        $n = 0;
        $limiter = $throttle(static function () use (&$n): string {
            return '2001:db8:1:2::' . dechex(++$n);
        });

        $results = array_map(
            static fn (): bool => $limiter->hitAddress('actions', new Request('POST', '/'), 3, 60)->allowed,
            range(1, 4),
        );

        expect($results)->toBe([true, true, true, false]);
    });

    it('keeps separate /64s and IPv4 addresses apart', function () use ($throttle) {
        $addresses = ['2001:db8:1:2::1', '2001:db8:1:3::1', '192.0.2.1', '192.0.2.2', '::ffff:192.0.2.3'];
        $limiter = $throttle(static function () use (&$addresses): string {
            return array_shift($addresses) ?? '';
        });

        $results = array_map(
            static fn (): bool => $limiter->hitAddress('actions', new Request('POST', '/'), 1, 60)->allowed,
            range(1, 5),
        );

        expect($results)->toBe([true, true, true, true, true]);
    });
});

// With more live windows than the sweep threshold, every hit swept the whole
// array: a cost per request that grew with the attacker's address count.
it('does not sweep the throttle store on every hit while its windows are live', function () {
    $now = 1_000;
    $store = new ArrayThrottleStore(static function () use (&$now): int {
        return $now;
    });
    for ($i = 0; $i < ArrayThrottleStore::SWEEP_AT; $i++) {
        $store->increment("k{$i}", 60);
    }

    $started = hrtime(true);
    for ($i = 0; $i < 2_000; $i++) {
        $store->increment("more{$i}", 60);
    }
    $perHit = (hrtime(true) - $started) / 2_000 / 1e3;

    expect($store->count())->toBe(ArrayThrottleStore::SWEEP_AT + 2_000)
        ->and($perHit)->toBeLessThan(50.0, 'microseconds per hit');

    // Expired windows are still dropped once it does sweep.
    $now += 120;
    for ($i = 0; $i < ArrayThrottleStore::SWEEP_AT * 2; $i++) {
        $store->increment("late{$i}", 60);
    }
    expect($store->count())->toBeLessThan(ArrayThrottleStore::SWEEP_AT * 3);
});

describe('a form or multipart body', function () {
    // PHP parses these itself, so they never passed through decodeJson().
    it('is held to the body size limit', function () {
        expect(fn () => Limits::form(['note' => str_repeat('x', Limits::MAX_BODY_BYTES + 1)]))
            ->toThrow(HttpException::class, 'too large');
    });

    it('is held to the depth limit', function () {
        $deep = 'leaf';
        for ($i = 0; $i < Limits::MAX_JSON_DEPTH + 1; $i++) {
            $deep = [$deep];
        }

        expect(fn () => Limits::form(['a' => $deep]))->toThrow(HttpException::class, 'nests too deeply');
    });

    it('is held to the element limit', function () {
        expect(fn () => Limits::form(array_fill(0, Limits::MAX_JSON_ELEMENTS + 1, '1')))
            ->toThrow(HttpException::class, 'too many fields');
    });

    it('passes an ordinary form through unchanged', function () {
        $form = ['name' => 'Rack A', 'tags' => ['a', 'b'], 'meta' => ['owner' => ['id' => '1']]];

        expect(Limits::form($form))->toBe($form);
    });
});
