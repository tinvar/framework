<?php

declare(strict_types=1);

/**
 * Outbound requests: one client every feature that fetches a URL goes
 * through, which "resolves the host once and connects to that pinned
 * address", refuses "private, loopback, link-local, metadata, IPv4-mapped
 * IPv6, NAT64 and `0.0.0.0` addresses ... along with platform-owned
 * prefixes declared in `#[OutboundPolicy]`", and does not follow redirects
 * (tinvar-spec.md, "Outbound requests").
 *
 * The network these touch is a server on 127.0.0.1 in a child process,
 * reached under names a StaticResolver answers -- names no real DNS has, so
 * a request that arrives proves curl connected where it was pinned rather
 * than looking the name up itself.
 */

namespace Tinvar\Tests\Outbound\OutboundHttp;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Outbound\Cidr;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\PinnedHttp;
use Tinvar\Outbound\Resolver;
use Tinvar\Outbound\StaticResolver;
use Tinvar\Outbound\SystemResolver;
use Tinvar\Outbound\Target;
use Tinvar\Tests\Outbound\Fixtures\Server;

use function Tinvar\Tests\Outbound\Fixtures\closedPort;

require_once __DIR__ . '/Fixtures/server.php';

/** The test server stands in for a public host: loopback is allowed, one address of it. */
function local(): OutboundPolicy
{
    return new OutboundPolicy(allow: ['127.0.0.1']);
}

function needsFork(): void
{
    if (!\function_exists('pcntl_fork') || !\function_exists('curl_init')) {
        test()->markTestSkipped('pcntl or curl is not available.');
    }
}

/** A platform's application, declaring the platform's own prefixes. */
#[OutboundPolicy(deny: ['93.184.0.0/16'], allow: ['10.20.0.0/16'])]
class Platform {}

/** Extends it, and declares a policy of its own. */
#[OutboundPolicy(allow: ['192.168.7.0/24'])]
final class Tenanted extends Platform {}

/**
 * A DNS server that takes its time: `$takes` seconds for every lookup,
 * then an answer. One that `stops` gives up at the time it is given, as a
 * resolver with a deadline of its own would; one that does not answers
 * late, as getaddrinfo() does.
 */
final class SlowResolver implements Resolver
{
    /** @var list<float> the time each lookup was given */
    public array $given = [];

    /** @param list<string> $answer */
    public function __construct(
        private readonly float $takes,
        private readonly bool $stops,
        private readonly array $answer = ['127.0.0.1'],
    ) {}

    public function resolve(string $host, float $timeout): array
    {
        $this->given[] = $timeout;
        if ($this->stops && $this->takes > $timeout) {
            usleep((int) ($timeout * 1e6));

            throw new OutboundFailed(OutboundFailed::TIMEOUT, "{$host} did not resolve in time.");
        }
        usleep((int) ($this->takes * 1e6));

        return $this->answer;
    }
}

dataset('refused addresses', [
    'loopback' => ['127.0.0.1', 'loopback'],
    'loopback, elsewhere in 127/8' => ['127.45.0.9', 'loopback'],
    'private 10/8' => ['10.1.2.3', 'private'],
    'private 172.16/12' => ['172.31.255.255', 'private'],
    'private 192.168/16' => ['192.168.1.1', 'private'],
    'link-local' => ['169.254.1.1', 'link_local'],
    'the metadata service' => ['169.254.169.254', 'metadata'],
    'the container metadata service' => ['169.254.170.2', 'metadata'],
    'Azure\'s wire server' => ['168.63.129.16', 'metadata'],
    'Alibaba\'s metadata' => ['100.100.100.200', 'metadata'],
    '0.0.0.0' => ['0.0.0.0', 'unspecified'],
    'this network' => ['0.1.2.3', 'unspecified'],
    'shared (CGNAT)' => ['100.64.0.1', 'shared'],
    'multicast' => ['224.0.0.1', 'multicast'],
    'broadcast' => ['255.255.255.255', 'reserved'],
    'documentation' => ['192.0.2.1', 'documentation'],
    'benchmarking' => ['198.18.0.1', 'benchmarking'],
    'IPv6 loopback' => ['::1', 'loopback'],
    'IPv6 unspecified' => ['::', 'unspecified'],
    'IPv4-mapped loopback' => ['::ffff:127.0.0.1', 'ipv4_mapped'],
    'IPv4-mapped, of a public address too' => ['::ffff:8.8.8.8', 'ipv4_mapped'],
    'IPv4-compatible' => ['::127.0.0.1', 'ipv4_compatible'],
    'IPv4-translated' => ['::ffff:0:7f00:1', 'ipv4_translated'],
    'NAT64' => ['64:ff9b::7f00:1', 'nat64'],
    'local-use NAT64' => ['64:ff9b:1::a00:1', 'nat64'],
    '6to4' => ['2002:7f00:1::1', '6to4'],
    'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', 'teredo'],
    'IPv6 metadata' => ['fd00:ec2::254', 'metadata'],
    'unique local' => ['fd12:3456::1', 'unique_local'],
    'IPv6 link-local' => ['fe80::1', 'link_local'],
    'IPv6 multicast' => ['ff02::1', 'multicast'],
    'IPv6 documentation' => ['2001:db8::1', 'documentation'],
    'IPv6 discard' => ['100::1', 'reserved'],
    'outside global unicast' => ['4000::1', 'reserved'],
    'not an address' => ['localhost', 'address_invalid'],
]);

it('refuses the address space the spec names, and the rest of the special-purpose registry', function (string $address, string $reason) {
    expect(new OutboundPolicy()->refusal($address))->toBe($reason);
})->with('refused addresses');

it('lets a public address through', function (string $address) {
    expect(new OutboundPolicy()->refusal($address))->toBeNull();
})->with(['8.8.8.8', '1.1.1.1', '93.184.215.14', '2606:4700:4700::1111', '2a00:1450:4001:80b::200e']);

it('opens a soft range by allow, never a hard one, and lets deny win over both', function () {
    $policy = new OutboundPolicy(deny: ['10.9.0.0/16', '8.8.8.0/24'], allow: ['10.0.0.0/8', '169.254.0.0/16', '::ffff:0:0/96', 'fd00::/8']);

    expect($policy->refusal('10.1.2.3'))->toBeNull()
        ->and($policy->refusal('fd12::1'))->toBeNull()
        ->and($policy->refusal('169.254.1.1'))->toBeNull()
        // Metadata inside the allowed link-local range, and a mapped
        // address however it is allowed, stay refused.
        ->and($policy->refusal('169.254.169.254'))->toBe('metadata')
        ->and($policy->refusal('::ffff:10.1.2.3'))->toBe('ipv4_mapped')
        // The platform's own prefixes: inside allow, and public.
        ->and($policy->refusal('10.9.1.1'))->toBe('platform')
        ->and($policy->refusal('8.8.8.8'))->toBe('platform')
        ->and($policy->refusal('8.8.4.4'))->toBeNull()
        ->and($policy->refusal('192.168.1.1'))->toBe('private');
});

it('keeps every metadata address hard, however wide the allowed range around it', function (string $address) {
    // Every soft range there is, and the mapped block, allowed wholesale.
    $policy = new OutboundPolicy(allow: ['169.254.0.0/16', '192.0.0.0/24', '100.64.0.0/10', 'fc00::/7', '::ffff:0:0/96', '0.0.0.0/0', '::/0']);

    expect($policy->refusal($address))->toBe('metadata')
        ->and($policy->refusal("::ffff:{$address}"))->toBe(str_contains($address, ':') ? 'address_invalid' : 'ipv4_mapped')
        ->and(fn () => new PinnedHttp($policy, new StaticResolver(['imds.invalid' => [$address]]))->send(OutboundRequest::get('http://imds.invalid/')))
        ->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('metadata'));

    if (!str_contains($address, ':')) {
        expect(fn () => new PinnedHttp($policy)->send(OutboundRequest::get("http://[::ffff:{$address}]/latest/meta-data/")))
            ->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('ipv4_mapped'));
    }
})->with([
    'the IMDS most providers share' => ['169.254.169.254'],
    'AWS time sync' => ['169.254.169.123'],
    'AWS DNS resolver' => ['169.254.169.253'],
    'ECS task metadata' => ['169.254.170.2'],
    'EKS pod identity' => ['169.254.170.23'],
    'Tencent metadata' => ['169.254.0.23'],
    'Azure wire server' => ['168.63.129.16'],
    'Oracle' => ['192.0.0.192'],
    'Alibaba' => ['100.100.100.200'],
    'AWS IMDS over IPv6' => ['fd00:ec2::254'],
    'AWS time sync over IPv6' => ['fd00:ec2::123'],
    'AWS DNS resolver over IPv6' => ['fd00:ec2::253'],
    'EKS pod identity over IPv6' => ['fd00:ec2::23'],
    'GCP over IPv6' => ['fd20:ce::254'],
]);

it('reads every deny along a class\'s lineage, and allow off the class alone', function () {
    $platform = OutboundPolicy::of(Platform::class);
    $tenanted = OutboundPolicy::of(Tenanted::class);

    expect($platform->refusal('93.184.215.14'))->toBe('platform')
        ->and($platform->refusal('10.20.1.1'))->toBeNull()
        // The subclass's own policy keeps its parent's deny and none of its allow.
        ->and($tenanted->refusal('93.184.215.14'))->toBe('platform')
        ->and($tenanted->refusal('10.20.1.1'))->toBe('private')
        ->and($tenanted->refusal('192.168.7.5'))->toBeNull()
        ->and(OutboundPolicy::of(\stdClass::class)->refusal('10.20.1.1'))->toBe('private');
});

it('refuses a prefix that is not one', function (string $prefix) {
    expect(fn () => Cidr::parse($prefix))->toThrow(\InvalidArgumentException::class);
})->with(['10.0.0.0/33', '::/129', '10.0.0/8', 'example.com', '10.0.0.0/8/1', '10.0.0.0/08', '10.0.0.0/']);

it('parses a URL once, by the WHATWG standard, into what curl is handed', function () {
    $hex = Target::parse('http://0x7f.1/a?b=1#frag');
    $decimal = Target::parse('http://2130706433:8080/');
    $mapped = Target::parse('https://[::ffff:127.0.0.1]/x');
    $name = Target::parse('https://Bücher.example/p%20q?x=1');

    expect($hex->literal)->toBe('127.0.0.1')
        ->and($hex->serialized())->toBe('http://127.0.0.1/a?b=1')
        ->and($hex->requestTarget())->toBe('/a?b=1')
        ->and($decimal->literal)->toBe('127.0.0.1')
        ->and($decimal->port)->toBe(8080)
        ->and($mapped->literal)->toBe('::ffff:7f00:1')
        ->and($mapped->origin())->toBe('https://[::ffff:7f00:1]:443')
        ->and($name->literal)->toBeNull()
        ->and($name->host)->toBe('xn--bcher-kva.example')
        ->and($name->port)->toBe(443)
        ->and($name->requestTarget())->toBe('/p%20q?x=1');
});

it('refuses a URL outbound requests do not use', function (string $url, string $reason) {
    expect(fn () => Target::parse($url))->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe($reason));
})->with([
    'file' => ['file:///etc/passwd', 'scheme'],
    'gopher' => ['gopher://example.com/', 'scheme'],
    'ftp' => ['ftp://example.com/', 'scheme'],
    'a user name' => ['https://admin@example.com/', 'credentials'],
    'a password' => ['https://admin:hunter2@example.com/', 'credentials'],
    'port 0' => ['http://example.com:0/', 'url_invalid'],
    'nonsense' => ['http://exa mple.com/', 'url_invalid'],
    'relative' => ['/just/a/path', 'url_invalid'],
]);

it('refuses a literal address before any lookup, and a name by every address it resolves to', function () {
    $resolver = new StaticResolver([
        'internal.test' => ['10.0.0.5'],
        'mixed.test' => ['127.0.0.1', '10.0.0.5'],
        'metadata.test' => ['169.254.169.254'],
    ]);
    $http = new PinnedHttp(local(), $resolver);

    $reason = static function (string $url) use ($http): string {
        try {
            $http->send(OutboundRequest::get($url));
        } catch (OutboundRefused $e) {
            return $e->reason;
        }

        return 'sent';
    };

    expect($reason('http://169.254.169.254/latest/meta-data/'))->toBe('metadata')
        ->and($reason('http://[::ffff:169.254.169.254]/'))->toBe('ipv4_mapped')
        ->and($reason('http://0x0a.0.0.1/'))->toBe('private')
        ->and($reason('http://0.0.0.0:8080/'))->toBe('unspecified')
        ->and($reason('http://internal.test/'))->toBe('private')
        // One good answer beside a bad one is refused, not picked from.
        ->and($reason('http://mixed.test/'))->toBe('private')
        ->and($reason('http://metadata.test/'))->toBe('metadata')
        ->and($resolver->lookups)->toBe(['internal.test' => 1, 'mixed.test' => 1, 'metadata.test' => 1]);
});

it('sends to the address it vetted: curl is pinned to it, under a name no DNS has', function () {
    needsFork();
    $server = Server::start([Server::reply(201, '{"ok":true}', ['X-Seen' => 'yes', 'Set-Cookie' => 'a=1'])]);
    $resolver = new StaticResolver(['hooks.invalid' => ['127.0.0.1']]);

    try {
        $response = new PinnedHttp(local(), $resolver)->send(OutboundRequest::post(
            "http://hooks.invalid:{$server->port}/in?x=1",
            '{"event":"ping"}',
            ['content-type' => 'application/json', 'authorization' => 'Bearer t0ken', 'x-empty' => ''],
        ));
    } finally {
        $requests = $server->stop();
    }

    expect($response->status)->toBe(201)
        ->and($response->body)->toBe('{"ok":true}')
        ->and($response->json())->toBe(['ok' => true])
        ->and($response->header('X-SEEN'))->toBe('yes')
        ->and($response->address)->toBe('127.0.0.1')
        ->and($response->milliseconds)->toBeGreaterThanOrEqual(0)
        ->and($resolver->lookups)->toBe(['hooks.invalid' => 1])
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]['method'])->toBe('POST')
        ->and($requests[0]['target'])->toBe('/in?x=1')
        ->and($requests[0]['headers']['host'])->toBe("hooks.invalid:{$server->port}")
        ->and($requests[0]['headers']['authorization'])->toBe('Bearer t0ken')
        ->and($requests[0]['headers']['x-empty'])->toBe('')
        ->and($requests[0]['headers']['user-agent'])->toBe(PinnedHttp::USER_AGENT)
        ->and($requests[0]['headers'])->not->toHaveKey('expect')
        ->and($requests[0]['body'])->toBe('{"event":"ping"}');
});

it('asks DNS once, so a rebinding answer never reaches the connection', function () {
    needsFork();
    $server = Server::start([Server::reply(200, 'from the vetted address')]);
    // Public (here: the allowed test server) to the check, the metadata
    // service to anything that asks again.
    $resolver = new StaticResolver(['rebind.invalid' => static fn (int $lookup): array => $lookup === 1 ? ['127.0.0.1'] : ['169.254.169.254']]);

    try {
        $response = new PinnedHttp(local(), $resolver)->send(OutboundRequest::get("http://rebind.invalid:{$server->port}/"));
    } finally {
        $requests = $server->stop();
    }

    expect($response->body)->toBe('from the vetted address')
        ->and($resolver->lookups['rebind.invalid'])->toBe(1)
        ->and($requests)->toHaveCount(1);
});

it('refuses a rebinding answer given to the first lookup, and connects to nothing', function () {
    needsFork();
    $server = Server::start([Server::reply(200)]);
    $resolver = new StaticResolver(['rebind.invalid' => static fn (int $lookup): array => $lookup === 1 ? ['169.254.169.254'] : ['127.0.0.1']]);

    try {
        expect(fn () => new PinnedHttp(local(), $resolver)->send(OutboundRequest::get("http://rebind.invalid:{$server->port}/")))
            ->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('metadata'));
    } finally {
        $requests = $server->stop();
    }

    expect($requests)->toBe([]);
});

it('refuses loopback unless the policy allows it, and the server never hears of it', function () {
    needsFork();
    $server = Server::start([Server::reply(200)]);

    try {
        expect(fn () => new PinnedHttp()->send(OutboundRequest::get("http://127.0.0.1:{$server->port}/")))
            ->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('loopback'))
            ->and(fn () => new PinnedHttp(new OutboundPolicy(allow: ['127.0.0.2']))->send(OutboundRequest::get("http://127.0.0.1:{$server->port}/")))
            ->toThrow(OutboundRefused::class);
    } finally {
        $requests = $server->stop();
    }

    expect($requests)->toBe([]);
});

it('returns a redirect as the response unless asked to follow it', function () {
    needsFork();
    $server = Server::start([Server::reply(302, '', ['Location' => '/elsewhere'])]);

    try {
        $response = new PinnedHttp(local())->send(OutboundRequest::get("http://127.0.0.1:{$server->port}/"));
    } finally {
        $requests = $server->stop();
    }

    expect($response->status)->toBe(302)
        ->and($response->redirect())->toBeTrue()
        ->and($response->header('location'))->toBe('/elsewhere')
        ->and($requests)->toHaveCount(1);
});

it('vets every hop of a redirect it follows, as a new destination', function () {
    needsFork();
    $server = Server::start([
        Server::reply(301, '', ['Location' => '/second']),
        Server::reply(302, '', ['Location' => 'http://metadata.test/latest/meta-data/iam']),
        Server::reply(200, 'never'),
    ]);
    $resolver = new StaticResolver(['hooks.invalid' => ['127.0.0.1'], 'metadata.test' => ['169.254.169.254']]);

    try {
        expect(fn () => new PinnedHttp(local(), $resolver)->send(new OutboundRequest('GET', "http://hooks.invalid:{$server->port}/first", maxRedirects: 3)))
            ->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('metadata'));
    } finally {
        $requests = $server->stop();
    }

    expect(array_column($requests, 'target'))->toBe(['/first', '/second'])
        ->and($resolver->lookups)->toBe(['hooks.invalid' => 2, 'metadata.test' => 1]);
});

it('keeps headers on a hop to the same origin and drops them all on a hop to another', function () {
    needsFork();
    $server = Server::start([
        Server::reply(302, '', ['Location' => '/same']),
        // The same server under another name: another origin.
        static fn (array $r): string => Server::reply(307, '', ['Location' => 'http://other.invalid:' . explode(':', $r['headers']['host'])[1] . '/other']),
        Server::reply(200, 'arrived'),
    ]);
    $resolver = new StaticResolver(['hooks.invalid' => ['127.0.0.1'], 'other.invalid' => ['127.0.0.1']]);

    try {
        $response = new PinnedHttp(local(), $resolver)->send(new OutboundRequest(
            'GET',
            "http://hooks.invalid:{$server->port}/start",
            ['authorization' => 'Bearer t0ken', 'x-api-key' => 'k3y'],
            maxRedirects: 5,
        ));
    } finally {
        $requests = $server->stop();
    }

    expect($response->body)->toBe('arrived')
        ->and(array_column($requests, 'target'))->toBe(['/start', '/same', '/other'])
        ->and($requests[1]['headers']['authorization'])->toBe('Bearer t0ken')
        ->and($requests[2]['headers'])->not->toHaveKey('authorization')
        ->and($requests[2]['headers'])->not->toHaveKey('x-api-key')
        ->and($requests[2]['headers']['host'])->toBe("other.invalid:{$server->port}");
});

it('caps the redirects it follows, and follows none for a POST', function () {
    needsFork();
    $again = Server::reply(302, '', ['Location' => '/again']);
    $server = Server::start([$again, $again, $again, $again]);

    try {
        expect(fn () => new PinnedHttp(local())->send(new OutboundRequest('GET', "http://127.0.0.1:{$server->port}/", maxRedirects: 2)))
            ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TOO_MANY_REDIRECTS)->and($e->status)->toBe(302));
        $post = new PinnedHttp(local())->send(new OutboundRequest('POST', "http://127.0.0.1:{$server->port}/", body: 'x', maxRedirects: 5));
    } finally {
        $requests = $server->stop();
    }

    expect($requests)->toHaveCount(4)
        ->and($post->status)->toBe(302)
        ->and(fn () => new OutboundRequest('GET', 'http://example.com/', maxRedirects: 6))->toThrow(\InvalidArgumentException::class);
});

it('stops a request at its timeout', function () {
    needsFork();
    $server = Server::start([static fn (): ?string => null]);

    $started = microtime(true);
    try {
        expect(fn () => new PinnedHttp(local())->send(new OutboundRequest('GET', "http://127.0.0.1:{$server->port}/", timeout: 0.5)))
            ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TIMEOUT));
    } finally {
        $server->stop();
    }

    expect(microtime(true) - $started)->toBeLessThan(3.0);
});

// The lookup was outside the timeout: a DNS server slow to answer held a
// request with a half-second timeout for as long as it liked.
it('gives a name lookup what is left of the timeout, and fails the request when it runs out', function () {
    needsFork();
    $server = Server::start([Server::reply(200)]);
    $resolver = new SlowResolver(takes: 5.0, stops: true);

    $started = microtime(true);
    try {
        expect(fn () => new PinnedHttp(local(), $resolver)->send(new OutboundRequest('GET', "http://slow.invalid:{$server->port}/", timeout: 0.5)))
            ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TIMEOUT));
    } finally {
        $requests = $server->stop();
    }

    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and($resolver->given)->toHaveCount(1)
        ->and($resolver->given[0])->toBeGreaterThan(0.0)
        ->and($resolver->given[0])->toBeLessThanOrEqual(0.5)
        ->and($requests)->toBe([]);
});

it('does not use an answer that comes after the timeout, and connects to nothing', function () {
    needsFork();
    $server = Server::start([Server::reply(200)]);
    $resolver = new SlowResolver(takes: 0.6, stops: false);

    try {
        expect(fn () => new PinnedHttp(local(), $resolver)->send(new OutboundRequest('GET', "http://slow.invalid:{$server->port}/", timeout: 0.3)))
            ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TIMEOUT)
                ->and($e->getMessage())->toContain('looking up its name')
                ->and($e->milliseconds)->toBeGreaterThanOrEqual(600));
    } finally {
        $requests = $server->stop();
    }

    expect($requests)->toBe([]);
});

it('gives each hop\'s lookup only what the hops before it left', function () {
    needsFork();
    $server = Server::start([Server::reply(302, '', ['Location' => '/second']), Server::reply(200, 'arrived')]);
    $resolver = new SlowResolver(takes: 0.2, stops: true);

    try {
        $response = new PinnedHttp(local(), $resolver)->send(new OutboundRequest('GET', "http://slow.invalid:{$server->port}/first", maxRedirects: 1, timeout: 3.0));
    } finally {
        $server->stop();
    }

    expect($response->body)->toBe('arrived')
        ->and($resolver->given)->toHaveCount(2)
        ->and($resolver->given[0])->toBeLessThanOrEqual(3.0)
        ->and($resolver->given[1])->toBeLessThanOrEqual($resolver->given[0] - 0.2);
});

it('stops reading a body over the cap, by its length or as it streams', function () {
    needsFork();
    $chunked = "HTTP/1.1 200 OK\r\nConnection: close\r\nTransfer-Encoding: chunked\r\n\r\n" . dechex(4096) . "\r\n" . str_repeat('y', 4096) . "\r\n0\r\n\r\n";
    $server = Server::start([Server::reply(200, str_repeat('x', 4096)), $chunked, Server::reply(200, str_repeat('z', 1024))]);
    $http = new PinnedHttp(local(), maxResponseBytes: 1024);

    try {
        expect(fn () => $http->send(OutboundRequest::get("http://127.0.0.1:{$server->port}/")))
            ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TOO_LARGE))
            ->and(fn () => $http->send(OutboundRequest::get("http://127.0.0.1:{$server->port}/")))
            ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TOO_LARGE)->and($e->status)->toBe(200));
        $exact = $http->send(OutboundRequest::get("http://127.0.0.1:{$server->port}/"));
    } finally {
        $server->stop();
    }

    expect(\strlen($exact->body))->toBe(1024);
});

it('reads only the status when asked for no body', function () {
    needsFork();
    $server = Server::start([Server::reply(202, str_repeat('b', 200_000))]);

    try {
        $response = new PinnedHttp(local(), maxResponseBytes: 10)->send(new OutboundRequest('POST', "http://127.0.0.1:{$server->port}/", body: '{}', readBody: false));
    } finally {
        $server->stop();
    }

    expect($response->status)->toBe(202)->and($response->body)->toBe('');
});

it('says a connection failed when nothing listens', function () {
    needsFork();

    expect(fn () => new PinnedHttp(local())->send(OutboundRequest::get('http://127.0.0.1:' . closedPort() . '/')))
        ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::CONNECT));
});

it('keeps headers, bodies, paths and queries out of what it throws and what a dump shows', function () {
    needsFork();
    $request = new OutboundRequest(
        'POST',
        'http://127.0.0.1:' . closedPort() . '/services/T0/B0/s3cret-path?token=s3cret-query',
        ['authorization' => 'Bearer s3cret-header'],
        's3cret-body',
    );
    $messages = [];
    foreach ([new PinnedHttp(), new PinnedHttp(local())] as $http) {
        try {
            $http->send($request);
        } catch (OutboundRefused|OutboundFailed $e) {
            $messages[] = $e->getMessage();
        }
    }
    $response = new OutboundResponse(200, ['set-cookie' => ['session=s3cret-cookie']], 's3cret-response');
    $dumped = print_r($request, true) . print_r($response, true) . json_encode([$request, $response], JSON_THROW_ON_ERROR);

    expect($messages)->toHaveCount(2)
        ->and(implode("\n", $messages))->not->toContain('s3cret')
        ->and($messages[0])->toContain('http://127.0.0.1:')
        ->and($dumped)->not->toContain('s3cret')
        ->and($dumped)->toContain('authorization')
        ->and($dumped)->toContain('set-cookie');
});

it('refuses a header that would write another, or that frames the request', function (array $headers) {
    expect(fn () => new OutboundRequest('GET', 'https://example.com/', $headers))->toThrow(\InvalidArgumentException::class);
})->with([
    'a line break in a value' => [['x-a' => "1\r\nHost: evil.test"]],
    'a bare newline' => [['x-a' => "1\nx-b: 2"]],
    'a NUL' => [['x-a' => "1\0"]],
    'a space in a name' => [['x a' => '1']],
    'a colon in a name' => [['x:a' => '1']],
    'Host' => [['Host' => 'internal.test']],
    'Content-Length' => [['content-length' => '0']],
    'Transfer-Encoding' => [['transfer-encoding' => 'chunked']],
    'a proxy header' => [['proxy-authorization' => 'Basic x']],
    'a numeric name' => [[0 => 'x']],
]);

it('sends only the methods it knows', function () {
    expect(fn () => new OutboundRequest('TRACE', 'https://example.com/'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new OutboundRequest('CONNECT', 'https://example.com/'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new OutboundRequest('get', 'https://example.com/'))->toThrow(\InvalidArgumentException::class)
        ->and(new OutboundRequest('PATCH', 'https://example.com/')->method)->toBe('PATCH');
});

it('resolves through the machine\'s resolver, and says when a name does not', function () {
    expect(new SystemResolver()->resolve('localhost', 5.0))->toContain('127.0.0.1')
        ->and(fn () => new SystemResolver()->resolve('no-such-host.invalid', 5.0))
        ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::DNS));
});

// getaddrinfo() cannot be stopped once it is asked, so the machine's
// resolver is not asked at all with no time left.
it('looks nothing up through the machine\'s resolver with no time left', function () {
    expect(fn () => new SystemResolver()->resolve('localhost', 0.0))
        ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TIMEOUT));
});

it('fakes the client for tests: records, answers, and refuses what the real one would', function () {
    $fake = new FakeOutboundHttp(static fn (OutboundRequest $r): OutboundResponse => new OutboundResponse($r->method === 'POST' ? 202 : 404));

    $posted = $fake->send(OutboundRequest::post('https://hooks.example.test/in', '{}'));

    expect($posted->status)->toBe(202)
        ->and($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]->url)->toBe('https://hooks.example.test/in')
        ->and(fn () => $fake->send(OutboundRequest::get('http://127.0.0.1/')))
        ->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('loopback'))
        ->and(fn () => $fake->send(OutboundRequest::get('ftp://example.com/')))->toThrow(OutboundRefused::class)
        ->and($fake->sent)->toHaveCount(1);

    $resolving = new FakeOutboundHttp(resolver: new StaticResolver(['internal.test' => ['10.0.0.1'], 'public.test' => ['8.8.8.8']]));
    $failing = new FakeOutboundHttp(static fn (): never => throw new OutboundFailed(OutboundFailed::TIMEOUT, 'timed out'));

    expect(fn () => $resolving->send(OutboundRequest::get('https://internal.test/')))
        ->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('private'))
        ->and($resolving->send(OutboundRequest::get('https://public.test/'))->status)->toBe(200)
        ->and(fn () => $failing->send(OutboundRequest::get('https://public.test/')))
        ->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TIMEOUT));
});
