<?php

declare(strict_types=1);

/**
 * Behind the proxy the shipped deployments put in front, every visitor's
 * connection comes from that proxy, and every throttle keyed on the peer
 * counted the whole internet as one caller: ten bad passwords locked any
 * customer out. The client's address is the right-most X-Forwarded-For hop
 * that is not a proxy the deployment pinned, read only when the peer is
 * one of them; anything malformed, or no hop to take, fails closed.
 */

use Tinvar\Auth\AuthContext;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Request;
use Tinvar\Http\Throttle;
use Tinvar\Http\TrustedProxies;

function proxied(?string $peer, ?string $forwarded = null): Request
{
    return new Request('GET', '/', $forwarded === null ? [] : ['x-forwarded-for' => $forwarded], remoteAddress: $peer);
}

describe('the client address', function () {
    $proxies = TrustedProxies::of(['10.0.0.0/8', '192.0.2.7', 'fd00::/8']);

    it('is the peer when no proxy is trusted, whatever the header says', function () {
        expect(TrustedProxies::none()->clientAddress(proxied('203.0.113.5', '198.51.100.1')))->toBe('203.0.113.5');
    });

    it('is the peer when the peer is not a trusted proxy', function () use ($proxies) {
        expect($proxies->clientAddress(proxied('203.0.113.5', '198.51.100.1')))->toBe('203.0.113.5');
    });

    it('is the right-most hop that is not a trusted proxy, from a trusted peer', function () use ($proxies) {
        expect($proxies->clientAddress(proxied('10.1.2.3', '198.51.100.1')))->toBe('198.51.100.1')
            // What the client sent itself, left of the proxies' hops, is not believed.
            ->and($proxies->clientAddress(proxied('10.1.2.3', '1.2.3.4, 198.51.100.1, 10.9.9.9')))->toBe('198.51.100.1')
            ->and($proxies->clientAddress(proxied('192.0.2.7', '1.2.3.4,198.51.100.1 , 192.0.2.7')))->toBe('198.51.100.1')
            ->and($proxies->clientAddress(proxied('fd00::1', '2001:db8::1, fd12::3')))->toBe('2001:db8::1');
    });

    it('takes no hop left of the right-most untrusted one, however malformed', function () use ($proxies) {
        expect($proxies->clientAddress(proxied('10.1.2.3', 'garbage, 198.51.100.1')))->toBe('198.51.100.1');
    });

    it('reads one written as an IPv4-mapped IPv6 address as the IPv4 one', function () use ($proxies) {
        expect($proxies->clientAddress(proxied('::ffff:10.1.2.3', '::ffff:198.51.100.1')))->toBe('198.51.100.1');
    });

    it('names no one when a trusted peer says nothing, or only proxies', function () use ($proxies) {
        expect($proxies->clientAddress(proxied('10.1.2.3')))->toBe('')
            ->and($proxies->clientAddress(proxied('10.1.2.3', '')))->toBe('')
            ->and($proxies->clientAddress(proxied('10.1.2.3', '10.0.0.1, 10.0.0.2')))->toBe('');
    });

    it('names no one for a hop it cannot read before an untrusted one', function (string $forwarded) use ($proxies) {
        expect($proxies->clientAddress(proxied('10.1.2.3', $forwarded)))->toBe('');
    })->with([
        'not an address' => ['unknown'],
        'a port' => ['198.51.100.1:443'],
        'a bracketed IPv6' => ['[2001:db8::1]'],
        'a zone' => ['fe80::1%eth0'],
        'an empty hop' => ['198.51.100.1,,'],
        'a trailing comma' => ['198.51.100.1,'],
        'a leading zero' => ['198.051.100.1'],
    ]);

    it('names no one for a peer it cannot read, when proxies are trusted', function () use ($proxies) {
        expect($proxies->clientAddress(proxied(null, '198.51.100.1')))->toBe('')
            ->and($proxies->clientAddress(proxied('', '198.51.100.1')))->toBe('');
    });

    it('charges every visitor behind the proxy a bucket of their own, and the unreadable one bucket', function () use ($proxies) {
        $throttle = new Throttle(new ArrayThrottleStore(), new AuthContext(), $proxies->clientAddress(...));

        foreach (range(1, 3) as $_) {
            $throttle->hitAddress('sign-in', proxied('10.1.2.3', '198.51.100.1'), 3, 60);
        }

        expect($throttle->hitAddress('sign-in', proxied('10.1.2.3', '198.51.100.1'), 3, 60)->allowed)->toBeFalse()
            ->and($throttle->hitAddress('sign-in', proxied('10.1.2.3', '198.51.100.2'), 3, 60)->allowed)->toBeTrue()
            ->and($throttle->addressFor(proxied('10.1.2.3', 'unknown')))->toBe(Throttle::UNIDENTIFIED);
    });
});

describe('the list of proxies', function () {
    it('takes IPv4 and IPv6 networks and single addresses', function () {
        $proxies = TrustedProxies::of(['10.0.0.0/8', '172.16.0.0/12', '192.0.2.7', '::1', 'fd00::/8', '2001:db8::/32']);

        expect($proxies->trusts('172.31.255.255'))->toBeTrue()
            ->and($proxies->trusts('172.32.0.0'))->toBeFalse()
            ->and($proxies->trusts('192.0.2.7'))->toBeTrue()
            ->and($proxies->trusts('192.0.2.8'))->toBeFalse()
            ->and($proxies->trusts('::1'))->toBeTrue()
            ->and($proxies->trusts('fdff::1'))->toBeTrue()
            ->and($proxies->trusts('fe00::1'))->toBeFalse()
            ->and($proxies->trusts('2001:db8:ffff::1'))->toBeTrue()
            ->and($proxies->trusts('2001:db9::1'))->toBeFalse()
            ->and($proxies->trusts('not an address'))->toBeFalse()
            ->and($proxies->isEmpty())->toBeFalse()
            ->and(TrustedProxies::of([])->isEmpty())->toBeTrue();
    });

    it('is handed to Caddy space-separated, each network written the one way', function () {
        expect(TrustedProxies::of(['10.0.0.0/8', '192.0.2.7', 'FD00:0::/8'])->caddyRanges())
            ->toBe('10.0.0.0/8 192.0.2.7/32 fd00::/8')
            ->and(TrustedProxies::none()->caddyRanges())->toBe('');
    });

    it('is refused malformed, naming the entry by its place and never its text', function (string $entry) {
        try {
            TrustedProxies::of(['10.0.0.0/8', $entry]);
        } catch (\InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('2nd');
            if ($entry !== '') {
                expect($e->getMessage())->not->toContain($entry);
            }

            return;
        }

        throw new \LogicException('expected a refusal');
    })->with([
        'not an address' => ['proxy.internal'],
        'a prefix past 32' => ['10.0.0.0/33'],
        'a prefix past 128' => ['fd00::/129'],
        'a prefix not digits' => ['10.0.0.0/x'],
        'a prefix with a sign' => ['10.0.0.0/+8'],
        'two prefixes' => ['10.0.0.0/8/8'],
        'bits past the prefix' => ['10.0.0.1/8'],
        'everything, IPv4' => ['0.0.0.0/0'],
        'everything, IPv6' => ['::/0'],
        'a port' => ['10.0.0.1:80'],
        // Read as the IPv4 address it maps, whose prefix is at most 32.
        'an IPv4-mapped range' => ['::ffff:198.51.100.0/120'],
        'empty' => [''],
    ]);
});
