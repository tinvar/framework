<?php

declare(strict_types=1);

/**
 * Tool calls held to #[Domain] (ADR 0149): an operation
 * held to a host -- by its own #[Domain] or its panel's -- runs over HTTP on
 * that host alone, and every grant is bound to the host
 * it was issued on, or carries the no-host marker, good only outside HTTP.
 */

namespace Tinvar\Tests\Tools\CallHosts;

use Tinvar\Calls\CallResult;
use Tinvar\Calls\ContextToken;
use Tinvar\Render\SignedToken;
use Tinvar\Support\Signer;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\Ran;
use Tinvar\Tools\ToolCalls;

use const Tinvar\Tests\Calls\Fixtures\KEY;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';

/** @return array<string, mixed> */
function claimsOf(string $token): array
{
    return json_decode((string) Signer::decode(explode('.', $token)[1]), true);
}

describe('a tool call', function () {
    it('runs a tool held to a host by its #[Domain] on that host alone', function () {
        $h = new Harness(hosts: true);
        $grant = $h->as('ada', fn () => $h->on('ops.shop.test', fn () => $h->tools->grant(['racks_label'])));
        $call = fn (): CallResult => $h->tools->call($grant, 'racks_label', ['rack' => $h->rack()]);

        expect(claimsOf($grant)['h'])->toBe('ops.shop.test')
            ->and($h->as('ada', fn () => $h->on('shop.test', $call))->status)->toBe(CallResult::REFUSED)
            ->and($h->as('ada', fn () => $h->on('admin.shop.test', $call))->status)->toBe(CallResult::REFUSED)
            ->and(Ran::$bodies)->toBe([])
            ->and($h->as('ada', fn () => $h->on('ops.shop.test', $call))->status)->toBe(CallResult::OK);
    });

    it('is granted a tool held to a host only on that host', function () {
        $h = new Harness(hosts: true);

        expect(fn () => $h->as('ada', fn () => $h->on('shop.test', fn () => $h->tools->grant(['racks_label']))))
            ->toThrow(\LogicException::class, "'racks.label' is held to ops.{root}, and this token would be for shop.test")
            ->and(fn () => $h->as('ada', fn () => $h->on('shop.test', fn () => $h->panels->within('admin', fn () => $h->tools->grant(['racks_audit'])))))
            ->toThrow(\LogicException::class, "'racks.audit' is held to admin.{root}");
    });

    it('holds a tool in a panel held to a host to the panel\'s host', function () {
        $h = new Harness(hosts: true);
        $h->staff->members['ada'] = ['racks.audit'];
        $grant = $h->as('ada', fn () => $h->on('admin.shop.test', fn () => $h->panels->within('admin', fn () => $h->tools->grant(['racks_audit']))));
        $call = fn (): CallResult => $h->panels->within('admin', fn () => $h->tools->call($grant, 'racks_audit', ['rack' => $h->rack()]));

        expect($h->as('ada', fn () => $h->on('shop.test', $call))->status)->toBe(CallResult::REFUSED)
            ->and($h->as('ada', fn () => $h->on('admin.shop.test', $call))->status)->toBe(CallResult::OK);
    });

    it('binds a grant issued in a job to no host, which is good outside HTTP alone', function () {
        $h = new Harness(hosts: true);
        $grant = $h->as('ada', fn () => $h->tools->grant(['racks_label', 'show_rack']));
        $call = fn (): CallResult => $h->tools->call($grant, 'racks_label', ['rack' => $h->rack()]);

        expect(claimsOf($grant))->toHaveKey('h')
            ->and(claimsOf($grant)['h'])->toBe(ContextToken::NO_HOST)
            ->and($h->as('ada', fn () => $h->on('ops.shop.test', $call))->reason)->toBe('grant')
            ->and($h->as('ada', fn () => $h->on('shop.test', fn () => $h->tools->call($grant, 'show_rack', ['rack' => $h->rack()])))->reason)->toBe('grant')
            ->and($h->as('ada', $call)->status)->toBe(CallResult::OK);
    });

    it('refuses a grant issued on one host on another, and one carrying no host at all', function () {
        $h = new Harness(hosts: true);
        $grant = $h->as('ada', fn () => $h->on('shop.test', fn () => $h->tools->grant(['show_rack'])));
        $call = fn (): CallResult => $h->tools->call($grant, 'show_rack', ['rack' => $h->rack()]);

        // A grant from before grants carried a host: refused where the application routes by host.
        $claims = claimsOf($grant);
        unset($claims['h']);
        $legacy = SignedToken::encode(new Signer(KEY, ToolCalls::PURPOSE), ToolCalls::PURPOSE, $claims);

        expect($h->as('ada', fn () => $h->on('ops.shop.test', $call))->reason)->toBe('grant')
            ->and($h->as('ada', fn () => $h->on('shop.test', fn () => $h->tools->call($legacy, 'show_rack', ['rack' => $h->rack()])))->reason)->toBe('grant')
            ->and($h->as('ada', fn () => $h->tools->call($legacy, 'show_rack', ['rack' => $h->rack()]))->reason)->toBe('grant')
            ->and($h->as('ada', fn () => $h->on('shop.test', $call))->status)->toBe(CallResult::OK);
    });

    it('binds nothing to a host where the application routes on none', function () {
        $h = new Harness();
        $grant = $h->as('ada', fn () => $h->tools->grant(['show_rack']));

        expect(claimsOf($grant)['h'])->toBe(ContextToken::NO_HOST)
            ->and($h->as('ada', fn () => $h->tools->call($grant, 'show_rack', ['rack' => $h->rack()]))->status)->toBe(CallResult::OK);
    });
});
