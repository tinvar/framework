<?php

declare(strict_types=1);

/**
 * The calls module on its own (ADR 0098): the operations an attribute marks
 * callable from outside a browser, and the runner that calls them through
 * the action's or the query's own runner -- with neither the tools nor the
 * service calls loaded, as the module's own run holds it (ADR 0114).
 */

namespace Tinvar\Tests\Calls\Calls;

use Tinvar\Calls\CallResult;
use Tinvar\Calls\Operation;
use Tinvar\Calls\Operations;
use Tinvar\Tests\Calls\Fixtures\Exposed;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\Ran;

require_once __DIR__ . '/Fixtures/calls.php';

/** @return array<string, Operation> what Exposed marks, by name */
function exposed(Harness $h): array
{
    $found = [];
    foreach (Operations::declaring(Exposed::class, $h->actions, $h->queries, $h->entry) as [$operation]) {
        $found[$operation->name] = $operation;
    }

    return $found;
}

it('finds the actions and queries an attribute marks, with the inputs each takes', function () {
    $operations = exposed(new Harness());

    expect(array_keys($operations))->toBe(['racks.rename', 'racks.show'])
        ->and($operations['racks.rename']->kind)->toBe(Operation::ACTION)
        ->and($operations['racks.rename']->writes())->toBeTrue()
        ->and(array_keys($operations['racks.rename']->inputs))->toBe(['rack', 'name'])
        ->and($operations['racks.rename']->requires('name'))->toBeTrue()
        ->and($operations['racks.show']->kind)->toBe(Operation::QUERY)
        ->and($operations['racks.show']->writes())->toBeFalse();
});

it('runs an action as the user in force, through its own validation and policy', function () {
    $h = new Harness();
    $rename = exposed($h)['racks.rename'];

    $ok = $h->as('ada', fn () => $h->runner->run($rename, ['rack' => $h->rack(), 'name' => 'C-01']));
    $refused = $h->as('mallory', fn () => $h->runner->run($rename, ['rack' => $h->rack(), 'name' => 'C-02']));
    $invalid = $h->as('ada', fn () => $h->runner->run($rename, ['rack' => $h->rack(), 'name' => str_repeat('x', 30)]));

    expect($ok->status)->toBe(CallResult::OK)
        ->and($ok->value)->toBe(['id' => $h->rack(), 'name' => 'C-01'])
        ->and($refused->status)->toBe(CallResult::FORBIDDEN)
        ->and($invalid->status)->toBe(CallResult::INVALID)
        ->and($invalid->errors)->toHaveKey('name')
        ->and($h->rackName())->toBe('C-01')
        ->and(Ran::$bodies)->toBe(['rename']);
});

it('runs a query read-only, and encodes its Data', function () {
    $h = new Harness();
    $result = $h->as('ada', fn () => $h->runner->run(exposed($h)['racks.show'], ['rack' => $h->rack(1)]));

    expect($result->toArray())->toBe(['status' => 'ok', 'value' => ['id' => $h->rack(1), 'name' => $h->rackName(1)]]);
});

it('refuses arguments that are not an object of the operation\'s own inputs, before anything runs', function (array $arguments) {
    $h = new Harness();
    $result = $h->as('ada', fn () => $h->runner->run(exposed($h)['racks.rename'], $arguments));

    expect($result->status)->toBe(CallResult::INVALID)
        ->and(Ran::$bodies)->toBe([]);
})->with([
    'a list' => [['a', 'b']],
    'a field it does not take' => [['rack' => 'r', 'name' => 'n', 'admin' => true]],
    'too deep' => [['rack' => 'r', 'name' => [[[[[[[[['deep']]]]]]]]]]],
]);
