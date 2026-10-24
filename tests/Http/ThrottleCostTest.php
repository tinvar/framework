<?php

declare(strict_types=1);

/**
 * A request that is several in one is charged as several: a fragment group
 * renders a subtree per token, and charged as one request it would buy that
 * many renders for the price of one (ADR 0018).
 */

namespace Tinvar\Tests\Http\ThrottleCost;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;

function throttle(): Throttle
{
    return new Throttle(new ArrayThrottleStore(static fn (): int => 1_000_000), new AuthContext(), static fn (Request $r): string => '10.0.0.1');
}

function pass(ThrottleRequests $middleware, string $path): Response
{
    return $middleware->handle(new Request('GET', $path), static fn (): Response => Response::make(200, 'ok'));
}

it('charges a group a hit per token', function () {
    $middleware = new ThrottleRequests(throttle(), 'fragments', 5, cost: FragmentEndpoint::tokenCount(...));

    $response = pass($middleware, '/_fragment/a/b/c');

    expect($response->header('x-ratelimit-remaining'))->toBe('2');
});

// Refused for all of itself, and the next request finds the budget spent.
it('refuses a group the budget cannot cover', function () {
    $middleware = new ThrottleRequests(throttle(), 'fragments', 3, cost: FragmentEndpoint::tokenCount(...));

    pass($middleware, '/_fragment/a/b');

    expect(fn () => pass($middleware, '/_fragment/c/d'))->toThrow(HttpException::class, 'Too many fragments');
    expect(fn () => pass($middleware, '/_fragment/e'))->toThrow(HttpException::class, 'Too many fragments');
});

it('charges one hit without a cost, as it always has', function () {
    $middleware = new ThrottleRequests(throttle(), 'fragments', 5);

    expect(pass($middleware, '/_fragment/a/b/c')->header('x-ratelimit-remaining'))->toBe('4');
});
