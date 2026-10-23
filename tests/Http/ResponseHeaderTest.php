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

// body() keeps what a producer wrote so a render is never run twice, and
// sendBody() -- the path Sapi::send() takes -- ignored that and ran it again:
// a logger or middleware that read the body first made the client get a
// second render.
it('sends the body a producer already wrote rather than running it again', function () {
    $runs = 0;
    $response = Response::stream(static function (\Closure $sink) use (&$runs): void {
        $runs++;
        $sink("render #{$runs}");
    });

    $response->body();
    $sent = '';
    $response->sendBody(static function (string $chunk) use (&$sent): void {
        $sent .= $chunk;
    });

    expect($runs)->toBe(1)
        ->and($sent)->toBe('render #1');
});
