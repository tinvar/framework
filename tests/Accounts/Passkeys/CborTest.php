<?php

declare(strict_types=1);

/**
 * The CBOR WebAuthn is written in, read strictly: what an authenticator
 * sends is untrusted bytes, so anything outside the subset is refused.
 */

namespace Tinvar\Tests\Accounts\Passkeys\Cbor;

use Tinvar\Accounts\Passkeys\Cbor;
use Tinvar\Accounts\Passkeys\CborBytes;
use Tinvar\Accounts\Passkeys\PasskeyRefused;

function decodes(string $hex): mixed
{
    return Cbor::decode((string) hex2bin($hex));
}

it('reads the RFC 8949 examples it supports', function (string $hex, mixed $expected) {
    expect(decodes($hex))->toBe($expected);
})->with([
    ['00', 0],
    ['17', 23],
    ['1818', 24],
    ['1903e8', 1000],
    ['1a000f4240', 1000000],
    ['1b000000e8d4a51000', 1000000000000],
    ['20', -1],
    ['3863', -100],
    ['3903e7', -1000],
    ['f4', false],
    ['f5', true],
    ['f6', null],
    ['f93c00', 1.0],
    ['fa47c35000', 100000.0],
    ['6161', 'a'],
    ['6449455446', 'IETF'],
    ['83010203', [1, 2, 3]],
    ['a201020304', [1 => 2, 3 => 4]],
    ['a26161016162820203', ['a' => 1, 'b' => [2, 3]]],
]);

it('keeps byte strings apart from text', function () {
    $value = decodes('4401020304');

    expect($value)->toBeInstanceOf(CborBytes::class)
        ->and($value->bytes)->toBe("\x01\x02\x03\x04");
});

it('says where the first item ends, for what follows it', function () {
    [$value, $end] = Cbor::decodeFirst((string) hex2bin('ff0102ff'), 1);

    expect($value)->toBe(1)->and($end)->toBe(2);
});

it('refuses what is outside the subset or malformed', function (string $hex) {
    expect(fn () => decodes($hex))->toThrow(PasskeyRefused::class);
})->with([
    'trailing bytes' => ['0000'],
    'empty input' => [''],
    'a tag' => ['c11a514b67b0'],
    'an indefinite byte string' => ['5f42010243030405ff'],
    'an indefinite array' => ['9f0102ff'],
    'an indefinite map' => ['bf616101ff'],
    'a break alone' => ['ff'],
    'undefined' => ['f7'],
    'a simple value' => ['f0'],
    'a reserved additional info' => ['1c'],
    'a duplicate map key' => ['a201020103'],
    'a duplicate text key' => ['a2616101616102'],
    'a text key that reads as an integer' => ['a1613101'],
    'a map key that is an array' => ['a1800102'],
    'a length past the input' => ['5a00010000ff'],
    'a count past the input' => ['9a00010000'],
    'a truncated argument' => ['1903'],
    'a 64-bit argument past PHP_INT_MAX' => ['1bffffffffffffffff'],
    'invalid UTF-8 text' => ['62c328'],
]);

it('refuses nesting past its depth', function () {
    $deep = str_repeat("\x81", Cbor::MAX_DEPTH + 2) . "\x00";

    expect(fn () => Cbor::decode($deep))->toThrow(PasskeyRefused::class);
});

it('reads nesting up to its depth', function () {
    $nested = str_repeat("\x81", Cbor::MAX_DEPTH) . "\x00";

    expect(Cbor::decode($nested))->toBeArray();
});
