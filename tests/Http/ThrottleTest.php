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

    // A user id and an address are not interchangeable identifiers; sharing
    // a namespace would let one be spelled as the other.
    it('never lets a user and an address share a bucket', function () {
        [$throttle, $auth] = throttle();
        $asUser = new Request('POST', '/_action/x', ['x-forwarded-for' => '198.51.100.7']);
        $auth->login(new AuthUser('198.51.100.7'));

        // The user bucket for "198.51.100.7" and the address bucket for the
        // same string are different keys, so one hit spends one of each
        // rather than two of one.
        $result = $throttle->hit('actions', $asUser, 1, 60);

        expect($result->allowed)->toBeTrue();
    });

    /**
     * Choosing a bucket by whether a credential was sent let the caller
     * CHOOSE which budget to spend: anonymously, then as one account, then
     * as another, is three full budgets from one address.
     */
    it('charges a signed-in caller address as well as their account', function () {
        [$throttle, $auth] = throttle();
        $request = new Request('POST', '/_action/x', ['x-forwarded-for' => '198.51.100.1']);

        // Anonymous spends the address budget.
        $throttle->hit('actions', $request, 2, 60);
        $throttle->hit('actions', $request, 2, 60);

        // Signing in must not refill it.
        $auth->login(new AuthUser('u-1'));

        expect($throttle->hit('actions', $request, 2, 60)->allowed)
            ->toBeFalse('the address is over, whoever is signed in');
    });

    it('does not let a second account refill one address budget', function () {
        [$throttle, $auth] = throttle();
        $request = new Request('POST', '/_action/x', ['x-forwarded-for' => '198.51.100.1']);

        $auth->login(new AuthUser('u-1'));
        $throttle->hit('actions', $request, 2, 60);
        $throttle->hit('actions', $request, 2, 60);

        $auth->login(new AuthUser('u-2'));

        expect($throttle->hit('actions', $request, 2, 60)->allowed)
            ->toBeFalse('N accounts from one address must not be N budgets');
    });

    /**
     * One account's behaviour must still not exhaust a shared NAT's budget,
     * so each account keeps its own counter. Two accounts behind one address
     * are limited by the address long before either exhausts the other --
     * which is the point: the reported remaining is whichever budget is
     * closest to running out.
     */
    it('counts each account separately while the address bounds them all', function () {
        [$throttle, $auth] = throttle();
        $shared = new Request('POST', '/_action/x', ['x-forwarded-for' => '198.51.100.1']);
        $elsewhere = new Request('POST', '/_action/x', ['x-forwarded-for' => '203.0.113.9']);

        $auth->login(new AuthUser('u-1'));
        $throttle->hit('actions', $shared, 10, 60);
        $throttle->hit('actions', $shared, 10, 60);

        // A second account behind the SAME address sees the address budget,
        // which two hits have already spent.
        $auth->login(new AuthUser('u-2'));
        expect($throttle->hit('actions', $shared, 10, 60)->remaining)->toBe(7);

        // The same account from elsewhere sees its own, barely-used one.
        expect($throttle->hit('actions', $elsewhere, 10, 60)->remaining)
            ->toBe(8, 'u-2 has spent two of its own ten');
    });

    /**
     * An empty address collapses every signed-out caller into one bucket, so
     * one attacker exhausts the anonymous budget for the whole site -- a
     * cheaper denial of service than the flood being prevented.
     */
    it('does not put every unidentifiable caller in one bucket', function () {
        $store = new ArrayThrottleStore();
        $throttle = new Throttle($store, new AuthContext(), static fn (Request $r): string => '');
        $request = Request::post('/_action/x');

        $allowed = [];
        for ($i = 0; $i < 5; $i++) {
            $allowed[] = $throttle->hit('actions', $request, 1, 60)->allowed;
        }

        expect($allowed)->toBe([true, true, true, true, true]);
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

    /**
     * The headers were computed correctly and then dropped, because
     * HttpException had nowhere to carry them -- so retryAfter() was dead
     * code on the only path that would ever have used it, while the
     * docblock, the commit and a test name all said otherwise.
     */
    it('tells a refused caller when to come back', function () use ($next) {
        [$throttle] = throttle();
        $middleware = new ThrottleRequests($throttle, 'actions', 1, 60);
        $request = Request::post('/_action/x');

        $middleware->handle($request, $next);

        try {
            $middleware->handle($request, $next);
            expect()->fail('a request past the limit was allowed');
        } catch (HttpException $e) {
            expect($e->headers['retry-after'] ?? null)->not->toBeNull()
                ->and((int) $e->headers['retry-after'])->toBeGreaterThan(0)
                ->and($e->headers['x-ratelimit-remaining'])->toBe('0');
        }
    });
});
