<?php

declare(strict_types=1);

/**
 * "Rate limits on actions, queries and fragments, keyed by user, or by IP
 * when signed out." No endpoint had any: the README said so, and both
 * fragment reviews noted that a junk token could be replayed at full rate.
 */

use Tinvar\Auth\AuthContext;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Tests\Auth\Fixtures\User as AuthUser;

require_once __DIR__ . '/../Auth/Fixtures/policies.php';

/** @return array{0: Throttle, 1: AuthContext, 2: ArrayThrottleStore} */
function throttle(?int &$now = null): array
{
    $now ??= 1_000_000;
    $store = new ArrayThrottleStore(static function () use (&$now): int { return $now; });
    $auth = new AuthContext();

    return [
        new Throttle($store, $auth, static fn (Request $r): string => $r->header('x-forwarded-for') ?? '10.0.0.1'),
        $auth,
        $store,
    ];
}

describe('counting', function () {
    it('allows up to the limit and refuses past it', function () {
        [$throttle] = throttle();
        $request = Request::post('/_action/x');

        expect($throttle->hit('actions', $request, 3, 60)->allowed)->toBeTrue()
            ->and($throttle->hit('actions', $request, 3, 60)->allowed)->toBeTrue()
            ->and($throttle->hit('actions', $request, 3, 60)->allowed)->toBeTrue()
            ->and($throttle->hit('actions', $request, 3, 60)->allowed)->toBeFalse();
    });

    it('reports what is left', function () {
        [$throttle] = throttle();
        $request = Request::post('/_action/x');

        $throttle->hit('actions', $request, 5, 60);
        $result = $throttle->hit('actions', $request, 5, 60);

        expect($result->remaining)->toBe(3)
            ->and($result->limit)->toBe(5);
    });

    // A window that has passed is a fresh window, not a continued one.
    it('starts a new window once the old one expires', function () {
        $now = 1_000_000;
        [$throttle] = throttle($now);
        $request = Request::post('/_action/x');

        $throttle->hit('actions', $request, 1, 60);
        expect($throttle->hit('actions', $request, 1, 60)->allowed)->toBeFalse();

        $now += 61;

        expect($throttle->hit('actions', $request, 1, 60)->allowed)->toBeTrue();
    });

    it('keeps separate buckets per name', function () {
        [$throttle] = throttle();
        $request = Request::post('/_action/x');

        $throttle->hit('actions', $request, 1, 60);

        expect($throttle->hit('fragments', $request, 1, 60)->allowed)
            ->toBeTrue('a fragment limit is not spent by an action');
    });
});

/**
 * Keyed by user, or by IP when signed out. A limit keyed only on IP punishes
 * everyone behind one NAT; keyed only on the user it does nothing for the
 * signed-out requests, which are the ones worth limiting most.
 */
describe('who a limit is keyed to', function () {
    it('keys a signed-out caller by address', function () {
        [$throttle] = throttle();

        $first = new Request('POST', '/_action/x', ['x-forwarded-for' => '198.51.100.1']);
        $second = new Request('POST', '/_action/x', ['x-forwarded-for' => '198.51.100.2']);

        $throttle->hit('actions', $first, 1, 60);

        expect($throttle->hit('actions', $first, 1, 60)->allowed)->toBeFalse()
            ->and($throttle->hit('actions', $second, 1, 60)->allowed)
            ->toBeTrue('another address has its own budget');
    });

    it('keys a signed-in caller by user, whatever address they arrive from', function () {
        [$throttle, $auth] = throttle();
        $auth->login(new AuthUser('u-1'));

        $first = new Request('POST', '/_action/x', ['x-forwarded-for' => '198.51.100.1']);
        $second = new Request('POST', '/_action/x', ['x-forwarded-for' => '203.0.113.9']);

        $throttle->hit('actions', $first, 1, 60);

        expect($throttle->hit('actions', $second, 1, 60)->allowed)
            ->toBeFalse('changing address must not refill a user budget');
    });

    // A user id and an address are not interchangeable identifiers; sharing a
    // namespace would let a caller choose which bucket to spend.
    it('never lets a user and an address share a bucket', function () {
        [$throttle, $auth] = throttle();
        $request = new Request('POST', '/_action/x', ['x-forwarded-for' => 'u-1']);

        $throttle->hit('actions', $request, 1, 60);

        $auth->login(new AuthUser('u-1'));

        expect($throttle->hit('actions', $request, 1, 60)->allowed)
            ->toBeTrue('an address spelled like a user id is a different bucket');
    });
});

describe('the middleware', function () {
    $next = static fn (Request $r): Response => Response::make(200, 'ok');

    it('refuses with 429 once the limit is spent', function () use ($next) {
        [$throttle] = throttle();
        $middleware = new ThrottleRequests($throttle, 'actions', 1, 60);
        $request = Request::post('/_action/x');

        $middleware->handle($request, $next);

        try {
            $middleware->handle($request, $next);
            expect()->fail('a request past the limit was allowed');
        } catch (HttpException $e) {
            expect($e->status)->toBe(429)
                ->and($e->code_)->toBe('too_many_requests');
        }
    });

    // Counting afterwards would mean the work an attacker wants to cause has
    // already happened by the time they are refused.
    it('does not run anything downstream when it refuses', function () {
        [$throttle] = throttle();
        $middleware = new ThrottleRequests($throttle, 'actions', 1, 60);
        $request = Request::post('/_action/x');
        $ran = 0;
        $counting = static function (Request $r) use (&$ran): Response {
            $ran++;

            return Response::make(200, 'ok');
        };

        $middleware->handle($request, $counting);

        try {
            $middleware->handle($request, $counting);
        } catch (HttpException) {
            // expected
        }

        expect($ran)->toBe(1);
    });

    // A client that can see what it has left can slow down on its own.
    it('tells an allowed caller what is left', function () use ($next) {
        [$throttle] = throttle();
        $middleware = new ThrottleRequests($throttle, 'actions', 5, 60);

        $response = $middleware->handle(Request::post('/_action/x'), $next);

        expect($response->header('x-ratelimit-limit'))->toBe('5')
            ->and($response->header('x-ratelimit-remaining'))->toBe('4')
            ->and($response->header('x-ratelimit-reset'))->not->toBeNull();
    });
});
