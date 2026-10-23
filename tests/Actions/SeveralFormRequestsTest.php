<?php

declare(strict_types=1);

/**
 * An action may take more than one form request. A submit reported only the
 * first one's errors, and Precognition answered a field shared by two of
 * them from the first alone -- passing a value the submit then refused.
 */

namespace Tinvar\Tests\Actions\SeveralFormRequests;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final readonly class AccountRequest extends FormRequest
{
    public function __construct(#[Email] public string $email, #[Min(3)] public string $name) {}
}

final readonly class ProfileRequest extends FormRequest
{
    public function __construct(#[Max(5)] public string $name, #[Min(18)] public int $age) {}
}

final class SignupActions
{
    #[Action, NoAuthorization(reason: 'fixture: validation, not access control')]
    public function signup(AccountRequest $account, ProfileRequest $profile): ActionResult
    {
        return ActionResult::ok("{$account->name}:{$profile->age}");
    }
}

function runner(): ActionRunner
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(SignupActions::class);

    return new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));
}

function errorsOf(\Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors;
    }

    throw new \LogicException('expected a 422');
}

const INPUT = ['email' => 'nope', 'name' => 'Bartholomew', 'age' => '12'];

const EVERY_ERROR = [
    'email' => ['email must be a valid email address.'],
    'name' => ['name must be at most 5 characters.'],
    'age' => ['age must be at least 18.'],
];

it("reports every form request's errors on a submit", function () {
    expect(errorsOf(fn () => runner()->run('SignupActions.signup', INPUT)))->toBe(EVERY_ERROR);
});

it('reports them all on a whole-form Precognition call too', function () {
    expect(errorsOf(fn () => runner()->run('SignupActions.signup', INPUT, precognitive: true)))->toBe(EVERY_ERROR);
});

it('judges a shared field by every request that declares it', function () {
    $runner = runner();

    expect($runner->validateField('SignupActions.signup', INPUT, 'name'))
        ->toBe(['name must be at most 5 characters.'])
        // Too short for one, fine for the other: still an error.
        ->and($runner->validateField('SignupActions.signup', ['name' => 'Al'], 'name'))
        ->toBe(['name must be at least 3 characters.'])
        ->and($runner->validateField('SignupActions.signup', ['name' => 'Alice'], 'name'))->toBe([]);
});

it('agrees with the submit about every field', function () {
    $runner = runner();

    $perField = [];
    foreach (array_keys(INPUT) as $field) {
        $found = $runner->validateField('SignupActions.signup', INPUT, $field);
        if ($found !== []) {
            $perField[$field] = $found;
        }
    }

    expect($perField)->toBe(errorsOf(fn () => $runner->run('SignupActions.signup', INPUT)));
});

it('runs once every request passes', function () {
    expect(runner()->run('SignupActions.signup', ['email' => 'a@b.co', 'name' => 'Alice', 'age' => '30'])->value)
        ->toBe('Alice:30');
});
