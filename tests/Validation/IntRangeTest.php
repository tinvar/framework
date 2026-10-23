<?php

declare(strict_types=1);

/**
 * An integer too long for PHP's int is refused, not clamped.
 *
 * toInt() checked the shape and then cast, and the cast clamps: an over-long
 * number became PHP_INT_MAX or PHP_INT_MIN. '-99999999999999999999999'
 * cleared a Max(100) as PHP_INT_MIN, and a field with no rule accepted
 * either end as a value the client never sent.
 */

namespace Tinvar\Tests\Validation\IntRange;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

final readonly class Capped extends FormRequest
{
    public function __construct(#[Max(100)] public int $qty) {}
}

final readonly class Uncapped extends FormRequest
{
    public function __construct(public int $qty) {}
}

enum Level: int
{
    case Top = PHP_INT_MAX;
}

final readonly class Levelled extends FormRequest
{
    public function __construct(public Level $level) {}
}

it('refuses an integer past either end of the int range', function (string $class, string $given) {
    expect(fn () => (new Validator())->validate($class, ['qty' => $given]))
        ->toThrow(ValidationException::class);
})->with([
    'below a Max' => [Capped::class, '-99999999999999999999999'],
    'no rule, too large' => [Uncapped::class, '99999999999999999999999'],
    'no rule, too small' => [Uncapped::class, '-99999999999999999999999'],
    'one past PHP_INT_MAX' => [Uncapped::class, '9223372036854775808'],
    'one past PHP_INT_MIN' => [Uncapped::class, '-9223372036854775809'],
]);

it('accepts both ends of the int range, and leading zeros as before', function (string $given, int $value) {
    expect((new Validator())->validate(Uncapped::class, ['qty' => $given])->qty)->toBe($value);
})->with([
    'PHP_INT_MAX' => ['9223372036854775807', PHP_INT_MAX],
    'PHP_INT_MIN' => ['-9223372036854775808', PHP_INT_MIN],
    'leading zeros' => ['007', 7],
]);

it('does not clamp an int-backed enum value onto the case at the end of the range', function () {
    expect(fn () => (new Validator())->validate(Levelled::class, ['level' => '99999999999999999999']))
        ->toThrow(ValidationException::class)
        ->and((new Validator())->validate(Levelled::class, ['level' => '9223372036854775807'])->level)
        ->toBe(Level::Top);
});
