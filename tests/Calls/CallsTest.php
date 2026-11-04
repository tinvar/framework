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

// A 402 for a plan, a 409 for a state, a 429 for a limit: each says what
// the caller can do about it, and was collapsed into a failure with a
// digest, as if the application had broken. A 5xx is the application's
// own trouble, and stays a failure. Never the exception's message.
it('passes a 4xx and its code through to the caller, and keeps a 5xx a failure', function (int $status, string $code, string $outcome, ?string $reason) {
    $h = new Harness();
    $h->actions->register(\Tinvar\Tests\Calls\Fixtures\HoldActions::class);
    \Tinvar\Tests\Calls\Fixtures\HoldActions::$status = $status;
    \Tinvar\Tests\Calls\Fixtures\HoldActions::$code = $code;
    $hold = Operations::declaring(\Tinvar\Tests\Calls\Fixtures\Refuses::class, $h->actions, $h->queries, $h->entry)[0][0];

    $result = $h->as('ada', fn () => $h->runner->run($hold, ['rack' => $h->rack()]));

    expect($result->status)->toBe($outcome)
        ->and($result->reason)->toBe($reason)
        ->and($result->httpStatus)->toBe($outcome === CallResult::REJECTED ? $status : null)
        ->and(json_encode($result->toArray()))->not->toContain('Pro plan');
    if ($outcome === CallResult::REJECTED) {
        expect($result->toArray())->toBe(['status' => 'rejected', 'value' => null, 'reason' => $reason, 'http_status' => $status]);
    } else {
        expect($result->digest)->not->toBeNull();
    }
})->with([
    'a plan' => [402, 'plan_required', CallResult::REJECTED, 'plan_required'],
    'a state' => [409, 'already_subscribed', CallResult::REJECTED, 'already_subscribed'],
    'a limit' => [429, 'too_many_requests', CallResult::REJECTED, 'too_many_requests'],
    'a code that is not one' => [409, 'Not <a> code', CallResult::REJECTED, 'rejected'],
    'no code' => [410, '', CallResult::REJECTED, 'rejected'],
    'the application\'s trouble' => [500, 'boom', CallResult::FAILED, null],
    'an upstream\'s' => [503, 'unavailable', CallResult::FAILED, null],
]);
