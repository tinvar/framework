<?php

declare(strict_types=1);

/**
 * Service calls and service context tokens (ADR 0098): an operation marked
 * #[ServiceCall] for a service, called by that service signed in as itself,
 * with a token the application issued it -- verified, then authorized, in
 * the tenant and panel the token was issued for.
 */

namespace Tinvar\Tests\Services\ServiceCalls;

use Tinvar\Calls\CallResult;
use Tinvar\Http\Request;
use Tinvar\Services\ServiceCalls;
use Tinvar\Support\Signer;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\Ran;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';
require_once __DIR__ . '/../Calls/Fixtures/warehouse.php';

it('runs a service\'s call in the context its token was issued for, through the action\'s own validation and policy', function () {
    $h = new Harness();
    $token = $h->services->context('warehouse', ['racks.rename'], reusable: true);

    $result = $h->asWarehouse(fn () => $h->services->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'W-1']));

    expect($result->status)->toBe(CallResult::OK)
        ->and($h->rackName())->toBe('W-1');

    $invalid = $h->asWarehouse(fn () => $h->services->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => '']));
    expect($invalid->status)->toBe(CallResult::INVALID);
});

it('reads a token from its header', function () {
    expect(ServiceCalls::tokenOf(new Request('POST', '/', ['x-service-context' => 'v1.a.b'])))->toBe('v1.a.b')
        ->and(ServiceCalls::tokenOf(new Request('POST', '/')))->toBe('');
});

it('refuses a caller that is not signed in as the service the token was issued to', function () {
    $h = new Harness();
    $token = $h->services->context('warehouse', ['racks.show']);
    $call = fn (): CallResult => $h->services->call($token, 'racks.show', ['rack' => $h->rack()]);

    expect($call()->reason)->toBe('unauthenticated')
        // A user with the token in hand is no service.
        ->and($h->as('ada', $call)->reason)->toBe('unauthenticated')
        // The carrier may call racks.show, but not with the warehouse's token.
        ->and($h->asWarehouse($call, 'carrier')->reason)->toBe('context')
        ->and($h->asWarehouse($call)->status)->toBe(CallResult::OK);
});

it('refuses an operation the service may not call, or the token does not name, as one that does not exist', function () {
    $h = new Harness();
    $token = $h->services->context('warehouse', ['racks.show']);

    expect($h->asWarehouse(fn () => $h->services->call($token, 'racks.scrap', ['rack' => $h->rack()]))->reason)->toBe('unknown_operation')
        ->and($h->asWarehouse(fn () => $h->services->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'x']))->reason)->toBe('context')
        ->and($h->asWarehouse(fn () => $h->services->call($token, 'racks.show', ['rack' => $h->rack()]), 'carrier')->reason)->toBe('context')
        ->and(fn () => $h->services->context('carrier', ['racks.rename']))->toThrow(\InvalidArgumentException::class, "not marked #[ServiceCall] for 'carrier'")
        ->and(Ran::$bodies)->toBe([]);
});

it('runs in the token\'s tenant, and refuses one presented in another', function () {
    $h = new Harness();
    $token = $h->orm->actAs('acme', fn () => $h->services->context('warehouse', ['racks.rename']));
    $call = fn (): CallResult => $h->services->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'T-1']);

    expect($h->asWarehouse($call)->status)->toBe(CallResult::OK)
        ->and(Ran::$tenants)->toBe(['acme'])
        ->and($h->asWarehouse(fn () => $h->orm->actAs('globex', $call))->reason)->toBe('context')
        ->and($h->asWarehouse(fn () => $h->orm->acrossTenants($call))->reason)->toBe('context')
        ->and(Ran::$tenants)->toBe(['acme']);
});

it('refuses a token that is forged, expired, issued for too long, or from a build no longer answered for', function () {
    $h = new Harness();
    $token = $h->services->context('warehouse', ['racks.show']);
    $call = fn (string $t): CallResult => $h->asWarehouse(fn () => $h->services->call($t, 'racks.show', ['rack' => $h->rack()]));

    [$v, $payload, $mac] = explode('.', $token);
    $claims = json_decode((string) Signer::decode($payload), true);
    $claims['y'] = 'globex';
    expect($call($v . '.' . Signer::encode((string) json_encode($claims)) . '.' . $mac)->reason)->toBe('context')
        ->and(fn () => $h->services->context('warehouse', ['racks.show'], ServiceCalls::MAX_TTL + 1))->toThrow(\InvalidArgumentException::class);

    $h->now += ServiceCalls::TTL;
    expect($call($token)->reason)->toBe('context');
});

it('reaches an operation in a panel only for the member it was issued for, on their epoch, holding the permission', function () {
    $h = new Harness();
    $h->staff->members['ada'] = ['racks.audit'];
    $token = $h->as('ada', fn () => $h->panels->within('admin', fn () => $h->services->context('warehouse', ['racks.audit'])));
    $call = fn (): CallResult => $h->asWarehouse(fn () => $h->services->call($token, 'racks.audit', ['rack' => $h->rack()]));

    expect($call()->status)->toBe(CallResult::OK);

    $h->staff->members['ada'] = [];
    expect($call()->status)->toBe(CallResult::FORBIDDEN);

    $h->staff->members['ada'] = ['racks.audit'];
    $h->users->epochs['ada'] = 'e2';
    expect($call()->reason)->toBe('context');

    unset($h->users->epochs['ada']);
    expect($call()->reason)->toBe('context')
        ->and(Ran::$bodies)->toBe(['audit']);
});

it('issues no token for a panel\'s operation with nobody signed in, nor by a service, nor across tenants', function () {
    $h = new Harness();

    expect(fn () => $h->panels->within('admin', fn () => $h->services->context('warehouse', ['racks.audit'])))->toThrow(\LogicException::class, 'nobody is signed in')
        ->and(fn () => $h->services->context('warehouse', ['racks.audit']))->toThrow(\LogicException::class, "panel 'admin'")
        ->and(fn () => $h->asWarehouse(fn () => $h->services->context('warehouse', ['racks.show'])))->toThrow(\LogicException::class, 'not by a service')
        ->and(fn () => $h->orm->acrossTenants(fn () => $h->services->context('warehouse', ['racks.show'])))->toThrow(\LogicException::class, 'acrossTenants');
});

// The tools' grant beside the services' token: both modules, wired
// together as an application has them, so the whole suite's alone.
it('refuses a tool grant presented as a service context token', function () {
    $h = new Harness();
    $grant = $h->as('ada', fn () => $h->tools->grant(['show_rack']));

    expect($h->asWarehouse(fn () => $h->services->call($grant, 'racks.show', ['rack' => $h->rack()]))->reason)->toBe('context');
})->group('wired');

// ADR 0119: a token stands for one answer, unless it was issued reusable.
it('spends a token at its first call that passes its checks, and refuses it after', function () {
    $h = new Harness();
    $token = $h->orm->actAs('acme', fn () => $h->services->context('warehouse', ['racks.rename']));
    $call = fn (string $name): CallResult => $h->asWarehouse(fn () => $h->services->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => $name]));

    // Refused before its checks passed -- presented in another tenant --
    // it is not spent; its first call that fits is the one.
    expect($h->asWarehouse(fn () => $h->orm->actAs('globex', fn () => $h->services->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => 'X'])))->reason)->toBe('context')
        ->and($call('S-1')->status)->toBe(CallResult::OK)
        ->and($call('S-2')->reason)->toBe('spent')
        ->and($h->rackName())->toBe('S-1');
});

it('lets a token issued reusable be called with until it expires', function () {
    $h = new Harness();
    $token = $h->services->context('warehouse', ['racks.rename'], reusable: true);
    $call = fn (string $name): CallResult => $h->asWarehouse(fn () => $h->services->call($token, 'racks.rename', ['rack' => $h->rack(), 'name' => $name]));

    expect($call('R-1')->status)->toBe(CallResult::OK)
        ->and($call('R-2')->status)->toBe(CallResult::OK);
});

it('refuses a single-use token where nothing could spend it, and one that does not say whether it is', function () {
    $h = new Harness();
    $unspendable = new ServiceCalls(
        ServiceCalls::discover($h->actions, $h->queries, $h->entry),
        $h->runner,
        ServiceCalls::tokens(\Tinvar\Tests\Calls\Fixtures\KEY, $h->builds, fn (): int => $h->now),
        $h->auth,
        $h->orm,
        $h->panels,
        $h->staff,
        $h->users,
    );
    $token = $unspendable->context('warehouse', ['racks.show']);
    expect($h->asWarehouse(fn () => $unspendable->call($token, 'racks.show', ['rack' => $h->rack()]))->reason)->toBe('context');

    // A token from before tokens said, signed as one: no 's' claim.
    $claims = [
        'j' => 'old', 'a' => 'warehouse', 'o' => ['racks.show'], 'y' => '', 'n' => '', 'w' => '', 'u' => '',
        'b' => 'build-1', 'i' => $h->now, 'e' => $h->now + 60,
    ];
    $old = \Tinvar\Render\SignedToken::encode(new \Tinvar\Support\Signer(\Tinvar\Tests\Calls\Fixtures\KEY, ServiceCalls::PURPOSE), ServiceCalls::PURPOSE, $claims);
    expect($h->asWarehouse(fn () => $h->services->call($old, 'racks.show', ['rack' => $h->rack()]))->reason)->toBe('context');
});
