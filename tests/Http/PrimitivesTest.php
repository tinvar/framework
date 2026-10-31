<?php

declare(strict_types=1);

/**
 * The small pieces under the request path: the throttle store's windows,
 * the pipeline's order, and the SAPI adapter's real output.
 */

use Tinvar\Support\Lazy;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Middleware;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Sapi;

describe('the in-memory throttle store', function () {
    it('counts within a window and starts afresh once it has passed', function () {
        $now = 1020;
        $store = new ArrayThrottleStore(static function () use (&$now): int {
            return $now;
        });

        expect($store->increment('k', 60))->toBe([1, 1080])
            ->and($store->increment('k', 60))->toBe([2, 1080]);

        $now = 1080;
        expect($store->increment('k', 60))->toBe([1, 1140]);
    });

    // An attacker rotating keys must not grow it without bound.
    it('sweeps passed windows once it holds enough of them', function () {
        $now = 1000;
        $store = new ArrayThrottleStore(static function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < ArrayThrottleStore::SWEEP_AT; $i++) {
            $store->increment("old-{$i}", 10);
        }

        $now = 2000;
        $store->increment('new', 10);

        expect($store->count())->toBe(1);
    });

    it('keeps windows that have not passed when it sweeps', function () {
        $now = 1000;
        $store = new ArrayThrottleStore(static function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < ArrayThrottleStore::SWEEP_AT; $i++) {
            $store->increment("live-{$i}", 3600);
        }

        $store->increment('new', 10);

        expect($store->count())->toBe(ArrayThrottleStore::SWEEP_AT + 1);
    });

    it('forgets everything when flushed', function () {
        $store = new ArrayThrottleStore();
        $store->increment('k', 60);
        $store->flush();

        expect($store->count())->toBe(0);
    });

    it('uses the real clock by default', function () {
        [, $resetsAt] = (new ArrayThrottleStore())->increment('k', 60);

        // The end of the minute the clock is in.
        expect($resetsAt % 60)->toBe(0)
            ->and($resetsAt)->toBeGreaterThan(time() - 1)->toBeLessThanOrEqual(time() + 60);
    });
});

describe('the pipeline', function () {
    it('runs middleware outermost first and unwinds in reverse', function () {
        $trace = new ArrayObject();
        $tag = static fn (string $name): Middleware => new class ($name, $trace) implements Middleware {
            public function __construct(private string $name, private ArrayObject $trace) {}

            public function handle(Request $request, Closure $next): Response
            {
                $this->trace[] = "in {$this->name}";
                $response = $next($request);
                $this->trace[] = "out {$this->name}";

                return $response;
            }
        };
        $pipeline = new Pipeline([$tag('a'), $tag('b')]);

        $pipeline->run(Request::get('/'), static function () use ($trace): Response {
            $trace[] = 'destination';

            return Response::make();
        });

        expect($trace->getArrayCopy())->toBe(['in a', 'in b', 'destination', 'out b', 'out a'])
            ->and($pipeline->middleware())->toHaveCount(2);
    });
});

describe('the SAPI adapter', function () {
    it('writes the body to output as it streams', function () {
        ob_start();
        Sapi::send(Response::stream(static function (Closure $sink): void {
            $sink('one ');
            $sink('two');
        }));

        expect(ob_get_clean())->toBe('one two');
    });

    it('reads / for a missing or empty request target', function (mixed $uri) {
        expect(Sapi::request(['REQUEST_URI' => $uri])->path)->toBe('/');
    })->with([[null], [''], [42]]);

    it('ignores server entries that are not string headers', function () {
        $request = Sapi::request([0 => 'argv', 'HTTP_X_A' => ['not', 'a', 'string'], 'HTTP_X_B' => 'ok']);

        expect($request->header('x-a'))->toBeNull()->and($request->header('x-b'))->toBe('ok');
    });
});

describe('Lazy', function () {
    it('runs its resolver once, on first resolve', function () {
        $runs = 0;
        $lazy = Lazy::of(static function () use (&$runs): int {
            return ++$runs;
        });

        expect($lazy->isResolved())->toBeFalse()
            ->and($lazy->resolve())->toBe(1)
            ->and($lazy->resolve())->toBe(1)
            ->and($lazy->isResolved())->toBeTrue();
    });

    it('wraps a value that is already known as resolved', function () {
        $lazy = Lazy::value(['a']);

        expect($lazy->isResolved())->toBeTrue()->and($lazy->resolve())->toBe(['a']);
    });
});
