<?php

declare(strict_types=1);

/**
 * Service calls held to #[Domain] (ADR 0149): an operation
 * held to a host -- by its own #[Domain] or its panel's -- runs over HTTP on
 * that host alone, and every context token is bound to the host
 * it was issued on, or carries the no-host marker, good only outside HTTP.
 */

namespace Tinvar\Tests\Services\ServiceCallHosts;

use Tinvar\Calls\CallResult;
use Tinvar\Calls\ContextToken;
use Tinvar\Render\SignedToken;
use Tinvar\Services\ServiceCalls;
use Tinvar\Support\Signer;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\Ran;

use const Tinvar\Tests\Calls\Fixtures\KEY;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';
require_once __DIR__ . '/../Calls/Fixtures/warehouse.php';

/** @return array<string, mixed> */
function claimsOf(string $token): array
{
    return json_decode((string) Signer::decode(explode('.', $token)[1]), true);
}

describe('a service call', function () {
    it('runs an operation held to a host by its #[Domain] on the host its token names alone', function () {
        $h = new Harness(hosts: true);
        $token = $h->services->context('warehouse', ['racks.label'], reusable: true, host: 'ops.shop.test');
        $call = fn (): CallResult => $h->services->call($token, 'racks.label', ['rack' => $h->rack()]);

        expect(claimsOf($token)['h'])->toBe('ops.shop.test')
            ->and($h->asWarehouse(fn () => $h->on('shop.test', $call))->reason)->toBe('context')
            ->and(Ran::$bodies)->toBe([])
            ->and($h->asWarehouse(fn () => $h->on('ops.shop.test', $call))->status)->toBe(CallResult::OK)
            // Outside HTTP, the host the token names holds it.
            ->and($h->asWarehouse($call)->status)->toBe(CallResult::OK);
    });

    it('refuses over HTTP a token issued in a job naming no host', function () {
        $h = new Harness(hosts: true);
        $token = $h->services->context('warehouse', ['racks.label', 'racks.show'], reusable: true);

        expect(claimsOf($token)['h'])->toBe(ContextToken::NO_HOST)
            ->and($h->asWarehouse(fn () => $h->on('ops.shop.test', fn () => $h->services->call($token, 'racks.label', ['rack' => $h->rack()])))->reason)->toBe('context')
            ->and($h->asWarehouse(fn () => $h->on('shop.test', fn () => $h->services->call($token, 'racks.show', ['rack' => $h->rack()])))->reason)->toBe('context')
            ->and($h->asWarehouse(fn () => $h->services->call($token, 'racks.label', ['rack' => $h->rack()]))->status)->toBe(CallResult::OK);
    });

    it('binds a token issued in a request to the request\'s host', function () {
        $h = new Harness(hosts: true);
        $token = $h->on('shop.test', fn () => $h->services->context('warehouse', ['racks.show'], reusable: true));
        $call = fn (): CallResult => $h->services->call($token, 'racks.show', ['rack' => $h->rack()]);

        expect(claimsOf($token)['h'])->toBe('shop.test')
            ->and($h->asWarehouse(fn () => $h->on('ops.shop.test', $call))->reason)->toBe('context')
            ->and($h->asWarehouse(fn () => $h->on('shop.test', $call))->status)->toBe(CallResult::OK)
            ->and(fn () => $h->on('shop.test', fn () => $h->services->context('warehouse', ['racks.show'], host: 'ops.shop.test')))
            ->toThrow(\LogicException::class, 'it is that host\'s');
    });

    it('holds an operation in a panel held to a host to the panel\'s host', function () {
        $h = new Harness(hosts: true);
        $h->staff->members['ada'] = ['racks.audit'];
        $token = $h->as('ada', fn () => $h->panels->within('admin', fn () => $h->services->context('warehouse', ['racks.audit'], reusable: true, host: 'admin.shop.test')));
        $call = fn (): CallResult => $h->services->call($token, 'racks.audit', ['rack' => $h->rack()]);

        expect($h->asWarehouse(fn () => $h->on('shop.test', $call))->reason)->toBe('context')
            ->and($h->asWarehouse(fn () => $h->on('admin.shop.test', $call))->status)->toBe(CallResult::OK);
    });

    it('refuses to name a host nobody serves, or one the operation is not held to', function () {
        $h = new Harness(hosts: true);

        expect(fn () => $h->services->context('warehouse', ['racks.label'], host: 'evil.example'))
            ->toThrow(\InvalidArgumentException::class, 'not a host this application serves')
            ->and(fn () => $h->services->context('warehouse', ['racks.label'], host: 'shop.test'))
            ->toThrow(\LogicException::class, "'racks.label' is held to ops.{root}")
            ->and(fn () => new Harness()->services->context('warehouse', ['racks.show'], host: 'shop.test'))
            ->toThrow(\LogicException::class, 'routes on none');
    });

    it('refuses a token carrying no host at all where the application routes by host', function () {
        $h = new Harness(hosts: true);
        $claims = claimsOf($h->services->context('warehouse', ['racks.show'], reusable: true, host: 'shop.test'));
        unset($claims['h']);
        $legacy = SignedToken::encode(new Signer(KEY, ServiceCalls::PURPOSE), ServiceCalls::PURPOSE, $claims);

        expect($h->asWarehouse(fn () => $h->on('shop.test', fn () => $h->services->call($legacy, 'racks.show', ['rack' => $h->rack()])))->reason)->toBe('context');
    });
});
