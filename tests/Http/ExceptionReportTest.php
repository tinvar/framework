<?php

declare(strict_types=1);

/**
 * ExceptionHandler::report(): a failure that did not fail its response --
 * a rescued field or fragment -- still reaches the log, under the digest the
 * client is shown.
 */

namespace Tinvar\Tests\Http\ExceptionReport;

use Tinvar\Http\ExceptionHandler;
use Tinvar\Support\Digest;

it('logs under the digest the handler would answer with, and returns it', function () {
    $logged = [];
    $handler = new ExceptionHandler(static function (\Throwable $e, string $digest) use (&$logged): void {
        $logged[] = [$e, $digest];
    });
    $failure = new \RuntimeException('carrier timed out');

    $digest = $handler->report($failure);

    expect($digest)->toBe(Digest::of($failure))
        ->and($digest)->toBe(ExceptionHandler::digest($failure))
        ->and($logged)->toBe([[$failure, $digest]]);
});

// The renderer and the query runner have already shown the browser a
// digest; logging under another would leave the reference finding nothing.
it('logs under a digest the caller already showed', function () {
    $logged = [];
    $handler = new ExceptionHandler(static function (\Throwable $e, string $digest) use (&$logged): void {
        $logged[] = $digest;
    });

    expect($handler->report(new \RuntimeException('x'), 'abc123'))->toBe('abc123')
        ->and($logged)->toBe(['abc123']);
});

// Review: a tenant-scoped read with no tenant was swapped for a 404 made
// inside the handler, and that was what was logged and digested -- so every
// one in the application had the same digest, and its log line named the
// handler, not the read that failed.
it('logs a read with no tenant as itself, under its own digest, while answering it as a 404', function () {
    $logged = [];
    $handler = new ExceptionHandler(static function (\Throwable $e, string $digest) use (&$logged): void {
        $logged[] = [$e, $digest];
    });
    $failure = new \Tinvar\Orm\NoTenantContext('App\Rack');

    $response = $handler->handle($failure, new \Tinvar\Http\Request('GET', '/racks', ['accept' => 'application/json']));
    $error = $handler->errorFor($failure);

    expect($response->status)->toBe(404)
        ->and($logged)->toBe([[$failure, Digest::of($failure)]])
        ->and(json_decode($response->body(), true)['error']['digest'] ?? null)->toBe(Digest::of($failure))
        ->and($error->status)->toBe(404)
        ->and($error->code)->toBe('no_tenant')
        ->and($error->digest)->toBe(Digest::of($failure));
});

// Audit: an API token refused for who asked -- a token's caller, staff
// impersonating the user -- was a LogicException, so a page that did not
// catch it answered a 500 for a refusal that is the user's to hear of.
it('answers an API token refused with its own status, not a 500', function () {
    $handler = new ExceptionHandler();
    $caller = new \Tinvar\Api\ApiTokenRefused(\Tinvar\Api\ApiTokenChoice::Caller, 'An API token cannot be issued while the user is being impersonated.', 403);
    $name = new \Tinvar\Api\ApiTokenRefused(\Tinvar\Api\ApiTokenChoice::Name, 'Name the token.');

    expect($handler->handle($caller, new \Tinvar\Http\Request('POST', '/_action', ['accept' => 'application/json']))->status)->toBe(403)
        ->and($handler->errorFor($caller)->status)->toBe(403)
        ->and($handler->errorFor($caller)->code)->toBe('api_token_refused')
        ->and($handler->errorFor($name)->status)->toBe(422);
});
