<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\JsonLimits;

use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpException;

/** @param list<string> $members */
function object(array $members): string
{
    return '{' . implode(',', $members) . '}';
}

// PHP's arrays are not hash-randomised: a megabyte of colliding keys cost
// seconds of CPU to decode, before the endpoint had looked at the name.
it('refuses a body with more keys than any form has, before decoding it', function () {
    $keys = array_map(static fn (int $i): string => '"' . ($i << 18) . '":0', range(1, Limits::MAX_JSON_ELEMENTS));

    $started = microtime(true);
    try {
        Limits::decodeJson(object($keys));
        expect()->fail('an oversized body was decoded');
    } catch (HttpException $e) {
        expect($e->status)->toBe(413)
            ->and(microtime(true) - $started)->toBeLessThan(0.5);
    }
});

it('refuses a list with more elements than any form has', function () {
    $body = '{"items":[' . implode(',', array_fill(0, Limits::MAX_JSON_ELEMENTS + 1, '0')) . ']}';

    expect(fn () => Limits::decodeJson($body))->toThrow(HttpException::class, 'too many fields');
});

it('does not count commas and colons inside strings', function () {
    $text = str_repeat('a, b: c, ', 50_000);

    expect(Limits::decodeJson(json_encode(['note' => $text, 'esc' => 'q"uote, \\ back: slash'])))
        ->toBe(['note' => $text, 'esc' => 'q"uote, \\ back: slash']);
});

it('decodes a body just under the limit', function () {
    $keys = array_map(static fn (int $i): string => "\"k{$i}\":{$i}", range(1, intdiv(Limits::MAX_JSON_ELEMENTS, 2) - 1));

    expect(Limits::decodeJson(object($keys)))->toHaveCount(intdiv(Limits::MAX_JSON_ELEMENTS, 2) - 1);
});
