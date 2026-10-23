<?php

declare(strict_types=1);

/**
 * #[In] compares strictly, against the value as coerced for the field. So
 * `#[In(1, 2, 4)] string $size` refused '2' -- and every other value -- and
 * `#[In('1', '2')] int $tier` refused 1. A list of one type now judges only
 * fields of that type, and the mismatch is refused when the field is planned.
 */

namespace Tinvar\Tests\Validation\InRuleTypes;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\In;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

final readonly class IntsOnString extends FormRequest
{
    public function __construct(#[In(1, 2, 4)] public string $size) {}
}

final readonly class StringsOnInt extends FormRequest
{
    public function __construct(#[In('1', '2')] public int $tier) {}
}

final readonly class IntsOnInt extends FormRequest
{
    public function __construct(#[In(1, 2, 4)] public int $size) {}
}

final readonly class StringsOnString extends FormRequest
{
    public function __construct(#[In('s', 'm', 'l')] public string $size) {}
}

final readonly class MixedList extends FormRequest
{
    public function __construct(#[In('s', 1)] public string $size) {}
}

it('refuses a list whose values the field can never equal, when the field is planned', function (string $class, string $says) {
    expect(fn () => (new Validator())->validate($class, []))
        ->toThrow(\LogicException::class, $says);
})->with([
    'ints on a string' => [IntsOnString::class, 'is string, which ' . In::class . ' cannot check (it applies to int)'],
    'strings on an int' => [StringsOnInt::class, 'is int, which ' . In::class . ' cannot check (it applies to string)'],
    'a mixed list' => [MixedList::class, 'In lists both strings and integers'],
]);

it('accepts a listed value of the field type, as sent by a form', function (string $class, string $sent) {
    expect((new Validator())->validate($class, ['size' => $sent]))->toBeInstanceOf($class);
})->with([
    'an int field' => [IntsOnInt::class, '2'],
    'a string field' => [StringsOnString::class, 'm'],
]);

it('still refuses a value outside the list', function () {
    expect(fn () => (new Validator())->validate(IntsOnInt::class, ['size' => '3']))
        ->toThrow(ValidationException::class);
});
