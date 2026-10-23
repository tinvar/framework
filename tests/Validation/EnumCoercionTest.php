<?php

declare(strict_types=1);

/**
 * Backed enums and floats from request input.
 *
 * An int-backed enum was handed a string under strict types -- a TypeError,
 * so a 500 -- and an array was converted to a string before anything checked
 * it. A float field took a 400-digit string, or 1e400 in JSON, as INF.
 */

namespace Tinvar\Tests\Validation\EnumCoercion;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

enum Priority: int
{
    case Low = 1;
    case High = 2;
    case Below = -1;
}

enum Kind: string
{
    case A = 'a';
    case One = '1';
}

final readonly class PriorityRequest extends FormRequest
{
    public function __construct(public Priority $priority) {}
}

final readonly class KindRequest extends FormRequest
{
    public function __construct(public Kind $kind) {}
}

final readonly class RatioRequest extends FormRequest
{
    public function __construct(#[Min(0)] public float $ratio) {}
}

/** @param class-string<FormRequest> $class */
function refuses(string $class, string $field, mixed $given): bool
{
    try {
        (new Validator())->validate($class, [$field => $given]);
    } catch (ValidationException $e) {
        return isset($e->errors[$field]);
    }

    return false;
}

describe('an int-backed enum', function () {
    it('takes the case from a form string or a JSON integer', function (string|int $given, Priority $case) {
        expect((new Validator())->validate(PriorityRequest::class, ['priority' => $given])->priority)->toBe($case);
    })->with([
        'form string' => ['2', Priority::High],
        'json integer' => [1, Priority::Low],
        'negative string' => ['-1', Priority::Below],
    ]);

    it('refuses anything that is not a whole number with a 422', function (mixed $given) {
        expect(refuses(PriorityRequest::class, 'priority', $given))->toBeTrue();
    })->with([
        'not a number' => ['high'],
        'a decimal' => ['1.0'],
        'padded' => [' 1'],
        'exponent' => ['1e0'],
        'a float' => [1.0],
        'a boolean' => [true],
        'an array' => [['1']],
        'no such case' => ['3'],
        'past PHP_INT_MAX' => ['99999999999999999999'],
    ]);
});

describe('a string-backed enum', function () {
    it('takes the case from a string, or an integer that spells it', function () {
        $validator = new Validator();

        expect($validator->validate(KindRequest::class, ['kind' => 'a'])->kind)->toBe(Kind::A)
            ->and($validator->validate(KindRequest::class, ['kind' => 1])->kind)->toBe(Kind::One);
    });

    // A bool used to become '1' and an array 'Array', each after a conversion
    // nothing had checked.
    it('refuses a boolean or an array with a 422', function (mixed $given) {
        expect(refuses(KindRequest::class, 'kind', $given))->toBeTrue();
    })->with([
        'true' => [true],
        'an array' => [['a']],
    ]);
});

describe('a float', function () {
    it('refuses a value too large to be finite', function (mixed $given) {
        expect(refuses(RatioRequest::class, 'ratio', $given))->toBeTrue();
    })->with([
        'a 400-digit string' => [str_repeat('9', 400)],
        'JSON 1e400' => [json_decode('{"r":1e400}', true)['r']],
        'NAN' => [NAN],
    ]);

    it('still takes a large finite one', function () {
        expect((new Validator())->validate(RatioRequest::class, ['ratio' => str_repeat('9', 300)])->ratio)
            ->toBe((float) str_repeat('9', 300));
    });
});
