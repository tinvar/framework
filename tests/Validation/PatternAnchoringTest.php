<?php

declare(strict_types=1);

/**
 * Pattern guarded its anchoring wrapper by counting parentheses, and got it
 * wrong both ways: `[](]x` -- a class holding `]` and `(` -- was refused,
 * and `\Q[\E).*|(evil` passed "totally evil", because the quoted bracket
 * made the counter think the `)` sat inside a class. The pattern is now
 * compiled on its own before it is wrapped, a `\Q` it leaves open is closed,
 * and the match has to be the whole value.
 */

namespace Tinvar\Tests\Validation\PatternAnchoring;

use Tinvar\Validation\Rules\Pattern;
use Tinvar\Validation\ValidationContext;

function check(string $pattern, string $value): ?string
{
    return (new Pattern($pattern))->check($value, 'f', new ValidationContext([]));
}

it('accepts a valid pattern the parenthesis count refused', function () {
    expect(check('[](]x', ']x'))->toBeNull()
        ->and(check('[](]x', '(x'))->toBeNull()
        ->and(check('[](]x', 'x'))->not->toBeNull();
});

it('refuses a pattern that closes the wrapper from behind a quote', function () {
    expect(fn () => check('\Q[\E).*|(evil', 'totally evil'))
        ->toThrow(\LogicException::class, 'compiled on its own');
});

it('closes a quote the pattern leaves open, rather than letting it swallow the anchor', function () {
    expect(check('\Qa.b', 'a.b'))->toBeNull()
        ->and(check('\Qa.b', 'axb'))->not->toBeNull()
        ->and(check('\Qa.b', 'a.bc'))->not->toBeNull();
});

it('refuses a value a verb accepted before the end of the value', function () {
    expect(check('x|(*ACCEPT)', 'totally evil'))->toBe('f is not in the expected format.')
        ->and(check('x|(*ACCEPT)', 'x'))->toBeNull();
});

it('still refuses what does not compile alone', function (string $pattern) {
    expect(fn () => check($pattern, 'x'))->toThrow(\LogicException::class, 'invalid expression');
})->with([
    'an unopened group' => ['.*)|(^evil.*'],
    'an unclosed group' => ['(a'],
    'a trailing backslash' => ['a\\'],
    'the delimiter' => ['a~b'],
]);
