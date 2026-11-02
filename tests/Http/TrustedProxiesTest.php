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

/** What the proxies below send in X-Tinvar-Proxy: a pinned range needs one. */
const PROXY_SECRET = 'trusted-proxy-secret-of-at-least-32-chars';

/** A request from $peer, carrying the proxies' secret as the deployment's own proxy sends it. */
function proxied(?string $peer, ?string $forwarded = null): Request
{
    return new Request('GET', '/', [...($forwarded === null ? [] : ['x-forwarded-for' => $forwarded]), TrustedProxies::SECRET_HEADER => PROXY_SECRET], remoteAddress: $peer);
}

describe('the client address', function () {
    $proxies = TrustedProxies::of(['10.0.0.0/8', '192.0.2.7', 'fd00::/8'], secret: PROXY_SECRET);

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
        $throttle = new Throttle(new ArrayThrottleStore(throttleClock()), new AuthContext(), $proxies->clientAddress(...));

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
        $proxies = TrustedProxies::of(['10.0.0.0/8', '172.16.0.0/12', '192.0.2.7', '::1', 'fd00::/8', '2001:db8::/32'], secret: PROXY_SECRET);

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
        expect(TrustedProxies::of(['10.0.0.0/8', '192.0.2.7', 'FD00:0::/8'], secret: PROXY_SECRET)->caddyRanges())
            ->toBe('10.0.0.0/8 192.0.2.7/32 fd00::/8')
            ->and(TrustedProxies::none()->caddyRanges())->toBe('');
    });

    it('is refused malformed, naming the entry by its place and never its text', function (string $entry) {
        try {
            TrustedProxies::of(['10.0.0.0/8', $entry], secret: PROXY_SECRET);
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

/**
 * The shipped deployments trust a range that holds the server's own
 * address: compose's subnet holds the container's, and 10.0.0.0/8 the
 * pod's. The renderers share that network namespace -- the one container's
 * interfaces, the pod's -- and nothing stops a compromised renderer
 * connecting to its own container's or pod's address, where it arrives
 * from that address: a trusted proxy, which then names any client it
 * likes, a throttle bucket of its own for every guess at a password. A
 * peer at one of this machine's own addresses connected from inside it,
 * and is never a proxy; loopback, which a terminator on the same machine
 * connects from, stays the list's to say.
 */
describe("a peer at the server's own address", function () {
    $proxies = TrustedProxies::of(['10.0.0.0/8', '127.0.0.1', 'fd00::/8'], own: ['10.213.89.2', 'fd00::2', '127.0.0.1', '::1'], secret: PROXY_SECRET);

    it('is never a proxy, whatever range holds it', function () use ($proxies) {
        expect($proxies->clientAddress(proxied('10.213.89.2', '198.51.100.1')))->toBe('10.213.89.2')
            ->and($proxies->clientAddress(proxied('::ffff:10.213.89.2', '198.51.100.1')))->toBe('::ffff:10.213.89.2')
            ->and($proxies->clientAddress(proxied('fd00::2', '198.51.100.1')))->toBe('fd00::2')
            ->and($proxies->host(new Request('GET', '/', ['host' => 'shop.test', 'x-forwarded-host' => 'admin.shop.test', TrustedProxies::SECRET_HEADER => PROXY_SECRET], remoteAddress: '10.213.89.2')))->toBe('shop.test');
    });

    it("leaves every other peer in the range a proxy, and loopback the list's to say", function () use ($proxies) {
        expect($proxies->clientAddress(proxied('10.213.89.1', '198.51.100.1')))->toBe('198.51.100.1')
            ->and($proxies->clientAddress(proxied('127.0.0.1', '198.51.100.1')))->toBe('198.51.100.1')
            ->and($proxies->host(new Request('GET', '/', ['host' => 'shop.test', 'x-forwarded-host' => 'admin.shop.test', TrustedProxies::SECRET_HEADER => PROXY_SECRET], remoteAddress: '10.213.89.1')))->toBe('admin.shop.test');
    });

    it('cannot spread its guesses over buckets of its own', function () use ($proxies) {
        $throttle = new Throttle(new ArrayThrottleStore(throttleClock()), new AuthContext(), $proxies->clientAddress(...));

        foreach (range(1, 3) as $i) {
            $throttle->hitAddress('sign-in', proxied('10.213.89.2', "198.51.100.{$i}"), 3, 60);
        }

        expect($throttle->hitAddress('sign-in', proxied('10.213.89.2', '198.51.100.9'), 3, 60)->allowed)->toBeFalse();
    });

    it("is read from the machine's interfaces where none is given, loopback left out", function () {
        $own = TrustedProxies::ownAddresses();

        expect($own)->each->toBeString()
            ->and(array_values(array_filter($own, static fn (string $a): bool => str_starts_with($a, '127.') || $a === '::1')))->toBe([]);
    });
});

/**
 * A range is not proof. The shipped ranges hold more than the ingress: the
 * node's own address, which the kubelet's probes and every hostNetwork pod
 * connect from and most network plugins let through any NetworkPolicy; the
 * bridge or node address a pod's connection to its own Service is
 * masqueraded to on the way back in (hairpin), which the renderers sharing
 * the pod can reach; compose's gateway, which a published port's userland
 * proxy connects from. Any of them, in the range, named any client it
 * liked. Given a secret, a peer is a proxy only when it also sends it, in
 * X-Tinvar-Proxy, as the deployment's own proxy is set to; the renderers
 * hold no secret, and nothing else on the node knows it.
 */
describe('a proxy secret', function () {
    $secret = str_repeat('s3cr3t-', 6);
    $proxies = TrustedProxies::of(['10.0.0.0/8'], own: [], secret: $secret);
    $from = static fn (string $peer, ?string $proof, string $forwarded = '198.51.100.1'): Request => new Request(
        'GET',
        '/',
        ['x-forwarded-for' => $forwarded, 'x-forwarded-host' => 'admin.shop.test', 'host' => 'shop.test', ...($proof === null ? [] : [TrustedProxies::SECRET_HEADER => $proof])],
        remoteAddress: $peer,
    );

    it('makes a peer in the range a proxy only when it sends it', function () use ($proxies, $from, $secret) {
        expect($proxies->clientAddress($from('10.244.0.7', $secret)))->toBe('198.51.100.1')
            ->and($proxies->host($from('10.244.0.7', $secret)))->toBe('admin.shop.test');
    });

    it('leaves a peer in the range that sends none, or another, the client itself', function (?string $proof) use ($proxies, $from) {
        // The node, a hairpinned Service connection, the bridge gateway: in
        // the range, and no proxy of the deployment's.
        expect($proxies->clientAddress($from('10.244.0.1', $proof)))->toBe('10.244.0.1')
            ->and($proxies->host($from('10.244.0.1', $proof)))->toBe('shop.test');
    })->with([
        'none' => [null],
        'empty' => [''],
        'another' => [str_repeat('x', 42)],
        'a prefix of it' => [str_repeat('s3cr3t-', 5)],
    ]);

    it('cannot spread guesses over buckets without it', function () use ($proxies, $from) {
        $throttle = new Throttle(new ArrayThrottleStore(throttleClock()), new AuthContext(), $proxies->clientAddress(...));

        foreach (range(1, 3) as $i) {
            $throttle->hitAddress('sign-in', $from('10.244.0.1', null, "198.51.100.{$i}"), 3, 60);
        }

        expect($throttle->hitAddress('sign-in', $from('10.244.0.1', null, '198.51.100.9'), 3, 60)->allowed)->toBeFalse();
    });

    it('is required wherever a range wider than one address is pinned', function (array $entries) {
        expect(fn () => TrustedProxies::of($entries, own: []))
            ->toThrow(\InvalidArgumentException::class, 'TINVAR_TRUSTED_PROXY_SECRET is required')
            ->and(TrustedProxies::of($entries, own: [], secret: PROXY_SECRET)->isEmpty())->toBeFalse();
    })->with([
        'an IPv4 range' => [['10.0.0.0/8']],
        'a /31' => [['192.0.2.6/31']],
        'an IPv6 range' => [['fd00::/8']],
        'a /127' => [['2001:db8::/127']],
        'a single address beside a range' => [['192.0.2.7', '10.213.89.0/24']],
    ]);

    it('names the entry that needs it by its place, and says how to fix it', function () {
        expect(fn () => TrustedProxies::of(['192.0.2.7', '10.213.89.0/24'], own: []))
            ->toThrow(\InvalidArgumentException::class, "TINVAR_TRUSTED_PROXIES's 2nd entry is a range wider than one address")
            ->and(fn () => TrustedProxies::of(['10.213.89.0/24'], own: []))
            ->toThrow(\InvalidArgumentException::class, 'pin the proxy\'s single address (/32 or /128)');
    });

    it('is not needed for single addresses', function () {
        $proxies = TrustedProxies::of(['127.0.0.1', '192.0.2.7/32', '::1', '2001:db8::7/128'], own: []);

        expect($proxies->clientAddress(new Request('GET', '/', ['x-forwarded-for' => '198.51.100.1'], remoteAddress: '192.0.2.7')))->toBe('198.51.100.1');
    });

    it('is refused when it is short enough to guess, or given with no proxy to prove', function () {
        expect(fn () => TrustedProxies::of(['10.0.0.0/8'], own: [], secret: 'short'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => TrustedProxies::of([], own: [], secret: str_repeat('s', 32)))->toThrow(\InvalidArgumentException::class);
    });
});

/*
 * Behind Cloudflare, every Cloudflare range is pinned and its Transform Rule
 * sends the secret -- for a request a Cloudflare Worker of anyone's sends to
 * the domain as well. The Worker writes X-Forwarded-For as it likes, and read
 * from the right through Cloudflare's own hops it named any client it chose.
 * So the rule also sets X-Tinvar-Client to `ip.src`, Cloudflare's own view of
 * who connected, overwriting any a client sent, and where the deployment says
 * so (TINVAR_TRUSTED_PROXY_CLIENT=header) the client is read from that alone.
 */
describe('a client header', function () {
    $proxies = TrustedProxies::of(['172.16.0.0/12', '104.16.0.0/13'], own: [], secret: PROXY_SECRET, clientHeader: true);
    $from = static fn (string $peer, ?string $client, string $forwarded = '198.51.100.1, 104.16.0.9', ?string $proof = PROXY_SECRET): Request => new Request(
        'GET',
        '/',
        [
            'x-forwarded-for' => $forwarded,
            ...($client === null ? [] : [TrustedProxies::CLIENT_HEADER => $client]),
            ...($proof === null ? [] : [TrustedProxies::SECRET_HEADER => $proof]),
        ],
        remoteAddress: $peer,
    );

    it('names the client from a proxy that proves itself, whatever X-Forwarded-For says', function () use ($proxies, $from) {
        // A Worker's request: the X-Forwarded-For it wrote names someone
        // else; Cloudflare's ip.src names the Worker.
        expect($proxies->clientAddress($from('172.16.3.4', '2a06:98c0:3600::103')))->toBe('2a06:98c0:3600::103')
            ->and($proxies->clientAddress($from('172.16.3.4', '203.0.113.9', '198.51.100.77')))->toBe('203.0.113.9')
            ->and($proxies->clientAddress($from('172.16.3.4', '::ffff:203.0.113.9')))->toBe('203.0.113.9');
    });

    it('names no client when a proven proxy sends none, or anything but one bare address -- never X-Forwarded-For', function (?string $client) use ($proxies, $from) {
        expect($proxies->clientAddress($from('172.16.3.4', $client)))->toBe('');
    })->with([
        'none' => [null],
        'empty' => [''],
        'two' => ['203.0.113.9, 198.51.100.1'],
        'a port' => ['203.0.113.9:443'],
        'a name' => ['unknown'],
    ]);

    it('is believed from a proven proxy alone: a peer without the secret is the client itself', function () use ($proxies, $from) {
        expect($proxies->clientAddress($from('172.16.3.4', '203.0.113.9', proof: null)))->toBe('172.16.3.4')
            ->and($proxies->clientAddress($from('198.51.100.20', '203.0.113.9')))->toBe('198.51.100.20');
    });

    it('is not read where the deployment did not ask for it', function () use ($from) {
        $forwarded = TrustedProxies::of(['172.16.0.0/12', '104.16.0.0/13'], own: [], secret: PROXY_SECRET);

        expect($forwarded->clientAddress($from('172.16.3.4', '203.0.113.9')))->toBe('198.51.100.1');
    });

    it('is refused without a secret: only a proxy that proves itself may name the client', function () {
        expect(fn () => TrustedProxies::of(['192.0.2.7'], own: [], clientHeader: true))
            ->toThrow(\InvalidArgumentException::class, 'TINVAR_TRUSTED_PROXY_CLIENT');
    });
});

/*
 * The same Worker writes X-Forwarded-Host as it likes too. Where the proven
 * proxy names the client (TINVAR_TRUSTED_PROXY_CLIENT=header), its rule
 * also sets X-Tinvar-Host to `http.host`, the host Cloudflare itself was
 * sent to, and the host is read from that alone: never X-Forwarded-Host,
 * and never the Host header behind it. Missing, it names no host (a 400);
 * anything but one host name, the empty host no one serves (a 421).
 */
describe('a host header', function () {
    $proxies = TrustedProxies::of(['172.16.0.0/12'], own: [], secret: PROXY_SECRET, clientHeader: true);
    $from = static fn (?string $host, ?string $proof = PROXY_SECRET, string $peer = '172.16.3.4'): Request => new Request(
        'GET',
        '/',
        [
            'host' => 'app.fly.dev',
            'x-forwarded-host' => 'admin.shop.test',
            ...($host === null ? [] : [TrustedProxies::HOST_HEADER => $host]),
            ...($proof === null ? [] : [TrustedProxies::SECRET_HEADER => $proof]),
        ],
        remoteAddress: $peer,
    );

    it('is the host from a proxy that proves itself, whatever X-Forwarded-Host says', function () use ($proxies, $from) {
        expect($proxies->host($from('shop.test')))->toBe('shop.test')
            ->and($proxies->host($from(' Shop.Test:443 ')))->toBe('Shop.Test:443');
    });

    it('names no host when a proven proxy sends none -- never X-Forwarded-Host or Host', function () use ($proxies, $from) {
        expect($proxies->host($from(null)))->toBeNull();
    });

    it('names the empty host for anything but one host name', function (string $host) use ($proxies, $from) {
        expect($proxies->host($from($host)))->toBe('');
    })->with([
        'empty' => [''],
        'two' => ['shop.test, admin.shop.test'],
        'an address' => ['203.0.113.9'],
        'a bracketed address' => ['[::1]'],
        'a path' => ['shop.test/admin'],
        'a stray character' => ['shop.test@evil.test'],
    ]);

    it('is not read from a peer that does not prove itself', function () use ($proxies, $from) {
        expect($proxies->host($from('shop.test', proof: null)))->toBe('app.fly.dev')
            ->and($proxies->host($from('shop.test', peer: '198.51.100.20')))->toBe('app.fly.dev');
    });

    it('is not read where the deployment did not ask for it', function () use ($from) {
        $forwarded = TrustedProxies::of(['172.16.0.0/12'], own: [], secret: PROXY_SECRET);

        expect($forwarded->host($from('shop.test')))->toBe('admin.shop.test');
    });

    it('refuses a request without it as naming no host, and a bad one as misdirected', function () use ($proxies, $from) {
        $domains = Tinvar\Routing\Domains::fromUrl('https://shop.test', proxies: $proxies);

        expect($domains->hostOf($from('shop.test')))->toBe('shop.test')
            ->and($domains->refusal($from(null))->status)->toBe(400)
            ->and($domains->refusal($from('203.0.113.9'))->status)->toBe(421);
    });
});
