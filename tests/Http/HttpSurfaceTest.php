<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\Surface;

use Tinvar\Auth\AuthContext;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\ExceptionHandler;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\ThrottleRequests;
use Tinvar\Http\Middleware\TranslateRedirects;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Sapi;
use Tinvar\Http\Throttle;

describe('the build registry', function () {
    // Re-retaining is the rollback operation; doing it to a build that never
    // left must not move it, or it could be lifted over the floor.
    it('leaves a build that is already retained where it is', function () {
        $builds = (new BuildRegistry('b3', ['b1', 'b2']))->floor('b2');

        $builds->retain('b1', after: 'b3');

        expect($builds->retained())->toBe(['b1', 'b2', 'b3'])
            ->and($builds->accepts('b1'))->toBeFalse()
            ->and($builds->accepts('b2'))->toBeTrue();
    });
});

describe('the exception handler', function () {
    it('names the code for a status when the exception carries none', function (int $status, string $code, string $message) {
        $response = (new ExceptionHandler())->handle(new HttpException($status), Request::get('/'));
        $error = json_decode($response->body(), true)['error'];

        expect($response->status)->toBe($status)
            ->and($error['code'])->toBe($code)
            ->and($error['message'])->toBe($message);
    })->with([
        [400, 'bad_request', 'The request could not be understood.'],
        [401, 'unauthenticated', 'Authentication is required.'],
        [403, 'forbidden', 'You may not do that.'],
        [404, 'not_found', 'Not found.'],
        [405, 'method_not_allowed', 'That method is not allowed here.'],
        [409, 'conflict', 'The request conflicts with the current state.'],
        [413, 'body_too_large', 'The request is too large.'],
        [419, 'csrf_token_mismatch', 'The session has expired.'],
        [422, 'unprocessable', 'The request could not be processed.'],
        [429, 'too_many_requests', 'Too many requests; slow down.'],
        [418, 'internal_error', 'Something went wrong.'],
    ]);
});

describe('throttling an account', function () {
    // The address pass outside it has already counted an anonymous caller.
    it('lets an anonymous caller through the account pass without charging anything', function () {
        $store = new ArrayThrottleStore(static fn (): int => 1000);
        $throttle = new Throttle($store, new AuthContext(), static fn (Request $r): string => '10.0.0.1');
        $middleware = new ThrottleRequests($throttle, 'actions', 1, byAddress: false);
        $next = static fn (Request $r): Response => Response::make(200, 'ok');

        $first = $middleware->handle(Request::post('/_action/x'), $next);
        $second = $middleware->handle(Request::post('/_action/x'), $next);

        expect($second->status)->toBe(200)
            ->and($first->header('x-ratelimit-limit'))->toBeNull()
            ->and($second->header('x-ratelimit-remaining'))->toBeNull()
            ->and($store->increment('actions|ip|10.0.0.1', 60)[0])->toBe(1);
    });

    it('has no answer to give when nothing was counted', function (array $results) {
        expect(fn () => Throttle::strictest($results))
            ->toThrow(\InvalidArgumentException::class, 'Nothing was counted');
    })->with([
        'no results' => [[]],
        'only absent ones' => [[null, null]],
    ]);
});

describe('translating redirects', function () {
    it('leaves a redirect status with no target alone', function (array $headers) {
        $response = new TranslateRedirects()->handle(
            Request::get('/racks', ['RSC' => '1']),
            static fn (): Response => Response::make(302, '', $headers),
        );

        expect($response->status)->toBe(302)
            ->and($response->header('x-redirect'))->toBeNull()
            ->and($response->header('x-location'))->toBeNull();
    })->with([
        'no location' => [[]],
        'empty location' => [['location' => '']],
    ]);
});

describe('redirect targets', function () {
    // PHP's URL parser gives up on a port-shaped segment it cannot read. A
    // target it cannot parse is one nothing downstream can vouch for either.
    it('refuses a target the URL parser cannot read', function () {
        expect(parse_url('/:65536'))->toBeFalse()
            ->and(fn () => Response::redirect('/:65536'))
            ->toThrow(\InvalidArgumentException::class, 'names a scheme or host');
    });
});

describe('emitting a buffered response', function () {
    it('writes the whole body as one chunk after the status and headers', function () {
        $log = [];

        Sapi::emit(
            Response::make(201, '{"ok":true}', ['content-type' => 'application/json']),
            static function (int $s) use (&$log): void {
                $log[] = "status {$s}";
            },
            static function (string $n, string $v) use (&$log): void {
                $log[] = "{$n}: {$v}";
            },
            static function (string $c) use (&$log): void {
                $log[] = $c;
            },
        );

        expect($log)->toBe(['status 201', 'content-type: application/json', '{"ok":true}']);
    });

    it('writes no chunk at all for an empty body', function () {
        $chunks = [];

        Sapi::emit(
            Response::make(204),
            static function (int $s): void {},
            static function (string $n, string $v): void {},
            static function (string $c) use (&$chunks): void {
                $chunks[] = $c;
            },
        );

        expect($chunks)->toBe([]);
    });

    // The CLI SAPI keeps no header list to inspect, so only the body is
    // observable here; the point is that headers do not stop it arriving.
    it('sends a response with headers to the real output', function () {
        ob_start();
        Sapi::send(Response::make(200, 'plain body', ['content-type' => 'text/plain']));

        expect(ob_get_clean())->toBe('plain body');
    });
});
