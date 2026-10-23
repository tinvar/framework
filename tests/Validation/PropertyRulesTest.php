<?php

declare(strict_types=1);

/**
 * Rule attributes on a property the constructor assigns rather than promotes.
 *
 * Every rule targets properties as well as parameters, so this shape compiles
 * and reads as validated; the planner used to look only at the parameter.
 */

namespace Tinvar\Tests\Validation\PropertyRules;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\In;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Pattern;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

final readonly class AssignedRequest extends FormRequest
{
    #[Max(100)] public int $amount;
    #[In('user', 'viewer')] public string $role;
    #[Pattern('[a-z0-9-]+')] public string $slug;
    #[Email] public string $email;

    public function __construct(int $amount, string $role, string $slug, string $email)
    {
        $this->amount = $amount;
        $this->role = $role;
        $this->slug = $slug;
        $this->email = $email;
    }
}

final readonly class SplitRulesRequest extends FormRequest
{
    #[Max(10)] public int $count;

    public function __construct(#[Min(2)] int $count)
    {
        $this->count = $count;
    }
}

final readonly class PromotedRequest extends FormRequest
{
    public function __construct(#[Max(10)] public int $count) {}
}

final readonly class OrphanRuleRequest extends FormRequest
{
    #[Max(10)] public int $total;

    public function __construct(int $amount)
    {
        $this->total = $amount * 2;
    }
}

final readonly class MismatchedRuleRequest extends FormRequest
{
    #[Email] public string $count;

    public function __construct(int $count)
    {
        $this->count = (string) $count;
    }
}

/**
 * @param class-string<FormRequest> $class
 * @param array<string, mixed>      $input
 * @return array<string, list<string>>
 */
function errorsOf(string $class, array $input): array
{
    try {
        (new Validator())->validate($class, $input);
    } catch (ValidationException $e) {
        return $e->errors;
    }

    return [];
}

it('enforces rules declared on a property the constructor assigns', function () {
    $errors = errorsOf(AssignedRequest::class, [
        'amount' => '1000000', 'role' => 'admin', 'slug' => '../../etc/passwd', 'email' => 'not-an-email',
    ]);

    expect(array_keys($errors))->toBe(['amount', 'role', 'slug', 'email']);
});

it('still accepts input that satisfies the property rules', function () {
    $request = (new Validator())->validate(AssignedRequest::class, [
        'amount' => '42', 'role' => 'viewer', 'slug' => 'rack-1', 'email' => 'ops@example.com',
    ]);

    expect($request->role)->toBe('viewer')->and($request->amount)->toBe(42);
});

it('merges the parameter rules with the property rules', function () {
    expect(errorsOf(SplitRulesRequest::class, ['count' => '1']))->toHaveKey('count')
        ->and(errorsOf(SplitRulesRequest::class, ['count' => '11']))->toHaveKey('count')
        ->and(errorsOf(SplitRulesRequest::class, ['count' => '5']))->toBe([]);
});

// PHP copies an attribute on a promoted parameter onto the property too, so
// reading both would report every failure twice.
it('applies a promoted field rule once', function () {
    expect(errorsOf(PromotedRequest::class, ['count' => '11']))->toBe(['count' => ['count must be at most 10.']]);
});

it('refuses a rule on a property no constructor parameter fills', function () {
    expect(fn () => (new Validator())->validate(OrphanRuleRequest::class, ['amount' => '1']))
        ->toThrow(\LogicException::class, 'OrphanRuleRequest::$total');
});

it('judges a property rule against the type the parameter is coerced to', function () {
    expect(fn () => (new Validator())->validate(MismatchedRuleRequest::class, ['count' => '1']))
        ->toThrow(\LogicException::class, 'cannot check');
});
