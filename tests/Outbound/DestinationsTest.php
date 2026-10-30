<?php

declare(strict_types=1);

/**
 * Where one kind of request may go (Destinations), and the ports any may
 * (OutboundPolicy's `ports`), held at every hop before a name is looked up
 * (ADR 0102).
 */

namespace Tinvar\Tests\Outbound\Destinations;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\PinnedHttp;
use Tinvar\Outbound\StaticResolver;
use Tinvar\Outbound\Target;
use Tinvar\Tests\Outbound\Fixtures\Server;

require_once __DIR__ . '/Fixtures/server.php';

function reason(\Closure $send): ?string
{
    try {
        $send();
    } catch (OutboundRefused $e) {
        return $e->reason;
    }

    return null;
}

it('allows the hosts it names, names below a wildcard, and nothing else', function (string $url, ?string $why) {
    $destinations = Destinations::hosts(['push.example', '*.okta.example', '203.0.113.7']);

    expect($destinations->refusal(Target::parse($url)))->toBe($why);
})->with([
    'a name' => ['https://push.example/x', null],
    'below a wildcard' => ['https://acme.okta.example/', null],
    'deeper below one' => ['https://a.b.okta.example/', null],
    'the wildcard\'s own name' => ['https://okta.example/', 'destination'],
    'a name ending like one' => ['https://evilokta.example/', 'destination'],
    'a subdomain of a plain name' => ['https://x.push.example/', 'destination'],
    'an address it names' => ['https://203.0.113.7/', null],
    'another port' => ['https://push.example:8443/', 'port'],
    'plain http' => ['http://push.example:443/', 'scheme'],
]);

it('refuses a host it cannot name precisely', function (string $host) {
    Destinations::hosts([$host]);
})->with(['', '*', '*.', 'a.*.example', 'Push.Example', 'x*.example'])->throws(\InvalidArgumentException::class);

it('takes the one origin of a URL', function () {
    $origin = Destinations::originOf('https://idp.example:8443/token');

    expect($origin->refusal(Target::parse('https://idp.example:8443/other')))->toBeNull()
        ->and($origin->refusal(Target::parse('https://idp.example/token')))->toBe('port')
        ->and($origin->refusal(Target::parse('https://other.example:8443/token')))->toBe('destination');
});

it('is held by the fake, before anything is recorded', function () {
    $http = new FakeOutboundHttp();
    $request = new OutboundRequest('GET', 'https://elsewhere.example/', destinations: Destinations::hosts(['push.example']));

    expect(reason(fn () => $http->send($request)))->toBe('destination')
        ->and($http->sent)->toBe([]);
});

// Each hop is a new destination: a redirect off the allowed hosts is
// refused before its name is looked up, let alone connected to.
it('is held at every hop of a redirect, before the next name is looked up', function () {
    if (!\function_exists('pcntl_fork') || !\function_exists('curl_init')) {
        test()->markTestSkipped('pcntl or curl is not available.');
    }
    $server = Server::start([Server::reply(302, '', ['Location' => '/moved']), Server::reply(302, '', ['Location' => 'http://elsewhere.invalid/'])]);
    $resolver = new StaticResolver(['hooks.invalid' => ['127.0.0.1'], 'elsewhere.invalid' => ['127.0.0.1']]);
    $request = new OutboundRequest(
        'GET',
        "http://hooks.invalid:{$server->port}/",
        maxRedirects: 3,
        destinations: Destinations::hosts(['hooks.invalid'], [$server->port, 80], plainHttp: true),
    );

    try {
        $why = reason(fn () => new PinnedHttp(new OutboundPolicy(allow: ['127.0.0.1']), $resolver)->send($request));
    } finally {
        $requests = $server->stop();
    }

    expect($why)->toBe('destination')
        ->and($requests)->toHaveCount(2)
        ->and($resolver->lookups)->toBe(['hooks.invalid' => 2]);
});

describe('the port policy', function () {
    it('allows 80, 443 and the ports from 1024 by default, and refuses the rest', function (int $port, bool $allowed) {
        $policy = new OutboundPolicy();
        $refused = reason(fn () => $policy->assertPort(Target::parse("https://hooks.example:{$port}/")));

        expect($refused === null)->toBe($allowed);
    })->with([[80, true], [443, true], [1024, true], [8080, true], [65535, true], [22, false], [25, false], [1023, false], [8, false]]);

    it('takes ports and ranges a policy declares, and refuses what is not one', function () {
        $policy = new OutboundPolicy(ports: [443, '8000-8099']);

        expect(reason(fn () => $policy->assertPort(Target::parse('https://h.example:8050/'))))->toBeNull()
            ->and(reason(fn () => $policy->assertPort(Target::parse('https://h.example:8100/'))))->toBe('port')
            ->and(reason(fn () => $policy->assertPort(Target::parse('http://h.example/'))))->toBe('port')
            ->and(fn () => new OutboundPolicy(ports: ['9-1']))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new OutboundPolicy(ports: []))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new OutboundPolicy(ports: ['x']))->toThrow(\InvalidArgumentException::class);
    });

    it('refuses a port before the name is looked up, through the client and the fake', function () {
        $resolver = new StaticResolver(['smtp.invalid' => ['203.0.113.9']]);

        expect(reason(fn () => new PinnedHttp(new OutboundPolicy(), $resolver)->send(OutboundRequest::get('http://smtp.invalid:25/'))))->toBe('port')
            ->and($resolver->lookups)->toBe([])
            ->and(reason(fn () => new FakeOutboundHttp()->send(OutboundRequest::get('http://smtp.invalid:25/'))))->toBe('port');
    });

    it('reads a class\'s ports off the class itself, never what it extends', function () {
        expect(OutboundPolicy::of(Widened::class)->ports)->toBe([[443, 443], [25, 25]])
            ->and(OutboundPolicy::of(Below::class)->ports)->toBe([[80, 80], [443, 443], [1024, 65535]]);
    });
});

#[OutboundPolicy(ports: [443, 25])]
class Widened {}

final class Below extends Widened {}
