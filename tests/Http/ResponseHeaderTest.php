<?php

declare(strict_types=1);

use Tinvar\Http\Response;

// $ matched before a final newline, so "x-a\n" passed as a header name.
it('refuses a header name that is not a token', function (string $name) {
    expect(fn () => Response::make()->withHeader($name, 'v'))->toThrow(InvalidArgumentException::class);
})->with(["x-a\n", 'x a', 'x:a', '']);

it('accepts a token header name', function () {
    expect(Response::make()->withHeader('x-route-token', 'v')->header('x-route-token'))->toBe('v');
});
