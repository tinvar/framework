<?php

declare(strict_types=1);

/**
 * One value, one accepted spelling.
 *
 * decode() promised it ("one logical value had many accepted spellings ...
 * Anything keyed on the string is bypassable when that is true") and kept
 * the promise for whitespace only. base64 ignores the unused low bits of the
 * last character, so 'AA' and 'AB' both decoded to the same byte.
 */

namespace Tinvar\Tests\Support\SignerEncoding;

use Tinvar\Support\Signer;

it('refuses a spelling with unused bits set in its last character', function (string $loose, string $canonical) {
    expect(Signer::decode($canonical))->not->toBeNull()
        ->and(Signer::decode($loose))->toBeNull();
})->with([
    'one byte' => ['AB', 'AA'],
    'two bytes' => ['AAB', 'AAA'],
    'a letter' => ['QR', 'QQ'],
]);

it('still accepts what encode() produces, at every padding length', function (int $length) {
    $raw = random_bytes($length);

    expect(Signer::decode(Signer::encode($raw)))->toBe($raw);
})->with([1, 2, 3, 31, 32, 33]);
