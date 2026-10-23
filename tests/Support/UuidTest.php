<?php

declare(strict_types=1);

use Tinvar\Support\Uuid;

it('mints valid v7 identifiers', function () {
    expect(Uuid::isValid(Uuid::v7()))->toBeTrue();
});

it('sets the version and variant bits RFC 9562 requires', function () {
    $hex = str_replace('-', '', Uuid::v7());

    expect($hex[12])->toBe('7')
        ->and(hexdec($hex[16]) & 0b1100)->toBe(0b1000);
});

it('encodes the timestamp so keys sort by creation time', function () {
    $early = Uuid::v7(1_700_000_000_000);
    $late = Uuid::v7(1_800_000_000_000);

    expect(Uuid::timestampOf($early))->toBe(1_700_000_000_000)
        ->and(strcmp($early, $late))->toBeLessThan(0);
});

it('does not repeat within a millisecond', function () {
    $ids = array_map(static fn (): string => Uuid::v7(1_700_000_000_000), range(1, 500));

    expect(array_unique($ids))->toHaveCount(500);
});

it('rejects malformed identifiers', function (string $candidate) {
    expect(Uuid::isValid($candidate))->toBeFalse();
})->with([
    'empty' => [''],
    'v4 variant bits wrong' => ['0192f3a1-0000-7000-0000-000000000000'],
    'too short' => ['0192f3a1'],
    'not hex' => ['zzzzzzzz-0000-7000-8000-000000000000'],
    // $ matches before a final newline; \z does not.
    'trailing newline' => ["0192f3a1-0000-7000-8000-000000000000\n"],
]);
