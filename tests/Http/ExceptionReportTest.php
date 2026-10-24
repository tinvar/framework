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
