<?php

declare(strict_types=1);

/**
 * Precognition validated form requests and never looked at an action's own
 * scalar parameters: `heightU=tall` passed validate-only and failed the
 * submit, and validate('heightU') threw a LogicException for a field the
 * action plainly takes.
 */

namespace Tinvar\Tests\Actions\PrecognitionScalars;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final readonly class LabelRequest extends FormRequest
{
    public function __construct(#[Max(8)] public string $label) {}
}

final class Ran
{
    public static int $bodies = 0;
}

final class RackActions
{
    #[Action, Authorize('update', 'rack')]
    public function resize(Rack $rack, #[Min(1)] int $heightU, LabelRequest $request): ActionResult
    {
        Ran::$bodies++;

        return ActionResult::ok($heightU);
    }
}

/** @return array{0: ActionRunner, 1: string} */
function runner(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(RackActions::class);

    return [new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm)), $fixture->ids['racks'][0]];
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

beforeEach(function () {
    Ran::$bodies = 0;
});

it('refuses on a whole-form Precognition call what the submit refuses', function (array $input, array $errors) {
    [$runner, $rack] = runner();
    $input = ['rack' => $rack, ...$input];

    expect(errorsOf(fn () => $runner->run('RackActions.resize', $input, precognitive: true)))->toBe($errors)
        ->and(errorsOf(fn () => $runner->run('RackActions.resize', $input)))->toBe($errors)
        ->and(Ran::$bodies)->toBe(0);
})->with([
    'not a number' => [['heightU' => 'tall', 'label' => 'A'], ['heightU' => ['heightU must be a whole number.']]],
    'below its rule' => [['heightU' => '0', 'label' => 'A'], ['heightU' => ['heightU must be at least 1.']]],
    'left out' => [['label' => 'A'], ['heightU' => ['heightU is required.']]],
    'with a form request error' => [
        ['heightU' => 'tall', 'label' => 'far too long'],
        ['label' => ['label must be at most 8 characters.'], 'heightU' => ['heightU must be a whole number.']],
    ],
]);

it('validates a scalar parameter on its own', function () {
    [$runner, $rack] = runner();

    expect($runner->validateField('RackActions.resize', ['rack' => $rack, 'heightU' => 'tall'], 'heightU'))
        ->toBe(['heightU must be a whole number.'])
        ->and($runner->validateField('RackActions.resize', ['rack' => $rack, 'heightU' => '0'], 'heightU'))
        ->toBe(['heightU must be at least 1.'])
        ->and($runner->validateField('RackActions.resize', ['rack' => $rack, 'heightU' => '42'], 'heightU'))->toBe([]);
});

it('still refuses a field the action does not take', function () {
    [$runner, $rack] = runner();

    expect(fn () => $runner->validateField('RackActions.resize', ['rack' => $rack], 'height'))
        ->toThrow(\Tinvar\Endpoints\UnknownField::class, "no form-request field named 'height'");
});

it('hands the body the coerced value', function () {
    [$runner, $rack] = runner();

    expect($runner->run('RackActions.resize', ['rack' => $rack, 'heightU' => '42', 'label' => 'A'])->value)->toBe(42);
});
