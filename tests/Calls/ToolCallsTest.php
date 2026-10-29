<?php

declare(strict_types=1);

/**
 * AI tool calls (ADR 0098): actions and queries marked #[Tool], called by a
 * model through code that holds a grant, as the user the grant was issued
 * for -- through the same resolution, #[Authorize] and validation as the
 * user's own call, held to the grant's user, epoch, tenant and panel.
 */

namespace Tinvar\Tests\Calls\ToolCalls;

use Tinvar\Calls\CallResult;
use Tinvar\Calls\Operations;
use Tinvar\Http\BuildRegistry;
use Tinvar\Render\SignedToken;
use Tinvar\Support\Signer;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\NeedsRequest;
use Tinvar\Tests\Calls\Fixtures\Ran;
use Tinvar\Tools\ToolCalls;

use const Tinvar\Tests\Calls\Fixtures\KEY;

require_once __DIR__ . '/Fixtures/calls.php';

it('tells a model each tool\'s name, description and the schema of its input', function () {
    $h = new Harness();

    $definitions = array_column($h->tools->definitions(), null, 'name');

    expect(array_keys($definitions))->toBe(['racks_rename', 'racks_audit', 'show_rack'])
        ->and($definitions['racks_rename']['description'])->toBe('Renames a rack.')
        ->and($definitions['racks_rename']['input_schema'])->toBe([
            'type' => 'object',
            'properties' => [
                'rack' => ['type' => 'string', 'description' => 'The id of a Rack.'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
            'required' => ['rack', 'name'],
            'additionalProperties' => false,
        ])
        ->and($h->tools->definitions(['show_rack']))->toHaveCount(1);
});

it('runs a tool call as the user the grant was issued for, through the action\'s own validation and policy', function () {
    $h = new Harness();
    $result = $h->as('ada', function () use ($h): CallResult {
        $grant = $h->tools->grant(['racks_rename', 'show_rack']);

        return $h->tools->call($grant, 'racks_rename', ['rack' => $h->rack(), 'name' => 'B-07']);
    });

    expect($result->status)->toBe(CallResult::OK)
        ->and($result->value)->toBe(['id' => $h->rack(), 'name' => 'B-07'])
        ->and($result->flash)->toBe(['message' => 'Renamed.'])
        ->and($h->rackName())->toBe('B-07');
});

it('runs a query tool read-only, and encodes its Data', function () {
    $h = new Harness();
    $result = $h->as('ada', fn () => $h->tools->call($h->tools->grant(['show_rack']), 'show_rack', ['rack' => $h->rack(1)]));

    expect($result->toArray())->toBe(['status' => 'ok', 'value' => ['id' => $h->rack(1), 'name' => $h->rackName(1)]]);
});

it('refuses what the user could not do by hand: a policy that says no, bad input, an id out of scope', function () {
    $h = new Harness();
    $h->as('mallory', function () use ($h): void {
        $grant = $h->tools->grant(['racks_rename']);

        expect($h->tools->call($grant, 'racks_rename', ['rack' => $h->rack(), 'name' => 'x'])->status)->toBe(CallResult::FORBIDDEN);
    });
    $h->as('ada', function () use ($h): void {
        $grant = $h->tools->grant(['racks_rename']);

        $invalid = $h->tools->call($grant, 'racks_rename', ['rack' => $h->rack(), 'name' => str_repeat('x', 30)]);
        expect($invalid->status)->toBe(CallResult::INVALID)
            ->and($invalid->errors)->toHaveKey('name')
            ->and($h->tools->call($grant, 'racks_rename', ['rack' => '01900000-0000-7000-8000-000000000000', 'name' => 'x'])->status)->toBe(CallResult::NOT_FOUND);
    });

    expect(Ran::$bodies)->toBe([]);
});

it('refuses arguments that are not a JSON object naming the tool\'s inputs, before anything runs', function (array $arguments) {
    $h = new Harness();
    $result = $h->as('ada', fn () => $h->tools->call($h->tools->grant(['racks_rename']), 'racks_rename', $arguments));

    expect($result->status)->toBe(CallResult::INVALID)
        ->and(Ran::$bodies)->toBe([]);
})->with([
    'a list' => [['a', 'b']],
    'a field it does not take' => [['rack' => 'r', 'name' => 'n', 'admin' => true]],
    'too deep' => [['rack' => 'r', 'name' => [[[[[[[[['deep']]]]]]]]]]],
    'too large' => [['rack' => 'r', 'name' => str_repeat('x', 70_000)]],
]);

it('refuses a call to a tool the grant does not name, and to one that is not a tool', function () {
    $h = new Harness();
    $h->as('ada', function () use ($h): void {
        $grant = $h->tools->grant(['show_rack']);

        expect($h->tools->call($grant, 'racks_rename', ['rack' => $h->rack(), 'name' => 'x'])->reason)->toBe('grant')
            ->and($h->tools->call($grant, 'racks_scrap', ['rack' => $h->rack()])->reason)->toBe('unknown_tool');
    });

    expect(Ran::$bodies)->toBe([]);
});

it('refuses a grant presented by another user, on another epoch, in another tenant or another panel', function () {
    $h = new Harness();
    $grant = $h->as('ada', fn () => $h->tools->grant(['show_rack']));
    $call = fn (): CallResult => $h->tools->call($grant, 'show_rack', ['rack' => $h->rack()]);

    expect($h->as('bob', $call)->reason)->toBe('grant')
        // The same user, after a role change moved their epoch.
        ->and($h->as('ada', $call, 'e2')->reason)->toBe('grant')
        ->and($h->as('ada', fn () => $h->orm->actAs('acme', $call))->reason)->toBe('grant')
        ->and($h->as('ada', fn () => $h->panels->within('admin', $call))->reason)->toBe('grant')
        ->and($call()->reason)->toBe('grant')
        ->and($h->as('ada', $call)->status)->toBe(CallResult::OK);
});

it('refuses a grant once the user\'s epoch moves, though the request or job still holds the old one', function () {
    $h = new Harness();
    $result = $h->as('ada', function () use ($h): CallResult {
        $grant = $h->tools->grant(['show_rack']);
        // A role change, written while the conversation goes on.
        $h->users->epochs['ada'] = 'e2';

        return $h->tools->call($grant, 'show_rack', ['rack' => $h->rack()]);
    });

    expect($result->reason)->toBe('grant');
});

it('refuses a grant that is forged, expired, from a revoked build, or of another kind', function () {
    $h = new Harness();
    $grant = $h->as('ada', fn () => $h->tools->grant(['show_rack']));
    $call = fn (string $token): CallResult => $h->as('ada', fn () => $h->tools->call($token, 'show_rack', ['rack' => $h->rack()]));

    // Widened to every tool, re-signed without the key.
    [$v, $payload, $mac] = explode('.', $grant);
    $claims = json_decode((string) Signer::decode($payload), true);
    $claims['o'] = ['show_rack', 'racks_rename'];
    $forged = $v . '.' . Signer::encode((string) json_encode($claims)) . '.' . $mac;

    // A service context token, for the same operation: another purpose.
    $service = SignedToken::encode(new Signer(KEY, 'service-context'), 'service-context', $claims);

    expect($call($forged)->reason)->toBe('grant')
        ->and($call($service)->reason)->toBe('grant');

    $h->now += ToolCalls::TTL;
    expect($call($grant)->reason)->toBe('grant');

    // Issued by build-1; this server answers for build-2 alone.
    $old = new Harness();
    $old->now = time();
    $issued = $old->as('ada', fn () => $old->tools->grant(['show_rack']));
    $revoked = new Harness(new BuildRegistry('build-2'));
    $revoked->now = $old->now;
    expect($revoked->as('ada', fn () => $revoked->tools->call($issued, 'show_rack', ['rack' => $revoked->rack()]))->reason)->toBe('grant');
});

it('holds a tool in a panel to the user\'s membership of it, with the permission it needs, at every call', function () {
    $h = new Harness();
    $h->staff->members['ada'] = ['racks.audit'];
    $h->as('ada', fn () => $h->panels->within('admin', function () use ($h): void {
        $grant = $h->tools->grant(['racks_audit']);

        expect($h->tools->call($grant, 'racks_audit', ['rack' => $h->rack()])->status)->toBe(CallResult::OK);

        $h->staff->members['ada'] = [];
        expect($h->tools->call($grant, 'racks_audit', ['rack' => $h->rack()])->status)->toBe(CallResult::FORBIDDEN);
    }));
});

it('issues no grant for nobody, across tenants, or for a tool in another panel', function () {
    $h = new Harness();

    expect(fn () => $h->tools->grant(['show_rack']))->toThrow(\LogicException::class, 'signed-in user')
        ->and(fn () => $h->as('ada', fn () => $h->orm->acrossTenants(fn () => $h->tools->grant(['show_rack']))))->toThrow(\LogicException::class, 'acrossTenants')
        ->and(fn () => $h->as('ada', fn () => $h->tools->grant(['racks_audit'])))->toThrow(\LogicException::class, "panel 'admin'")
        ->and(fn () => $h->as('ada', fn () => $h->tools->grant(['nope'])))->toThrow(\InvalidArgumentException::class);
});

it('refuses to make a tool of an action that takes the Request', function () {
    $h = new Harness();
    $h->actions->register(NeedsRequest::class);

    expect(fn () => Operations::declaring(\Tinvar\Attributes\Tool::class, $h->actions, $h->queries, $h->entry))
        ->toThrow(\LogicException::class, 'takes the Request');
});
