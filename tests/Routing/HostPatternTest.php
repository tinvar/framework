<?php

declare(strict_types=1);

/**
 * The host patterns #[Domain] writes and the allowlist Domains keeps
 * (ADR 0149): what a pattern may say, which hosts it matches, what its
 * parameters take, and how a request's host is read -- through a pinned
 * proxy only, held to the port, refused when nobody listed it.
 */

namespace Tinvar\Tests\Routing\HostPatterns;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\TrustedProxies;
use Tinvar\Routing\Domains;
use Tinvar\Routing\HostMatch;
use Tinvar\Routing\HostPattern;
use Tinvar\Routing\HostPatternRefused;

function host(string $host, array $headers = [], ?string $peer = null): Request
{
    return new Request('GET', '/', ['host' => $host, ...$headers], remoteAddress: $peer);
}

it('reads a configured domain at the end, and a parameter anywhere else', function () {
    $pattern = HostPattern::compile('{account}.{root}');

    expect($pattern->parameters)->toBe(['account'])
        ->and($pattern->root)->toBe('root')
        ->and($pattern->shape())->toBe('{}.{root}')
        ->and(HostPattern::compile('admin.example.com')->parameters)->toBe([])
        ->and(HostPattern::compile('admin.example.com')->root)->toBeNull();
});

it('refuses a malformed pattern', function (string $pattern) {
    HostPattern::compile($pattern);
})->throws(HostPatternRefused::class)->with([
    'empty' => [''],
    'a port' => ['example.com:8080'],
    'a scheme' => ['https://example.com'],
    'a trailing dot' => ['example.com.'],
    'uppercase' => ['Admin.example.com'],
    'a partial label' => ['shop-{id}.example.com'],
    'a wildcard' => ['*.example.com'],
    'a parameter twice' => ['{a}.{a}.example.com'],
    'a parameter under a top-level domain' => ['{tenant}.com'],
    'an address' => ['127.0.0.1'],
    'not a name' => ['{1x}.example.com'],
]);

it('allows a parameter under localhost, which never leaves the machine', function () {
    expect(HostPattern::compile('{tenant}.localhost')->parameters)->toBe(['tenant']);
});

it('matches a host label for label, and binds what the parameters take', function () {
    $roots = ['root' => 'shop.test'];

    expect(HostPattern::compile('{account}.{root}')->match('acme.shop.test', $roots))->toBe(['account' => 'acme'])
        ->and(HostPattern::compile('{account}.{root}')->match('shop.test', $roots))->toBeNull()
        // One label, never two: a wildcard certificate covers one level.
        ->and(HostPattern::compile('{account}.{root}')->match('x.acme.shop.test', $roots))->toBeNull()
        ->and(HostPattern::compile('admin.{root}')->match('admin.shop.test', $roots))->toBe([])
        ->and(HostPattern::compile('admin.{root}')->match('admin.shop.test.evil', $roots))->toBeNull()
        ->and(HostPattern::compile('{a}.{b}.example.com')->match('one.two.example.com', $roots))->toBe(['a' => 'one', 'b' => 'two']);
});

it('builds the host back from its parameters, and refuses a value that is not one label', function () {
    $roots = ['root' => 'shop.test'];

    expect(HostPattern::compile('{account}.{root}')->host(['account' => 'acme'], $roots))->toBe('acme.shop.test');
    HostPattern::compile('{account}.{root}')->host(['account' => 'evil.com/x'], $roots);
})->throws(HostPatternRefused::class);

it('answers a listed host, and refuses any other with a 421', function () {
    $domains = new Domains(['root' => 'shop.test'], patterns: ['admin.{root}', '{account}.{root}']);

    expect($domains->resolve(host('shop.test'))->pattern)->toBeNull()
        ->and($domains->resolve(host('admin.shop.test'))->pattern)->toBe('admin.{root}')
        ->and($domains->resolve(host('acme.shop.test'))->parameters)->toBe(['account' => 'acme']);

    try {
        $domains->resolve(host('evil.test'));
        $this->fail('An unlisted host was answered.');
    } catch (HttpException $e) {
        expect($e->status)->toBe(421);
    }
});

it('asks the most specific pattern first, whatever order they were declared in', function () {
    $domains = new Domains(['root' => 'shop.test'], patterns: ['{account}.{root}', 'admin.{root}']);

    expect($domains->resolve(host('admin.shop.test'))->pattern)->toBe('admin.{root}');
});

it('normalizes case and a trailing dot, and holds a port to the one configured', function () {
    $domains = Domains::fromUrl('http://localhost:8080', aliases: [])->serving(['admin.{root}']);

    expect($domains->resolve(host('ADMIN.localhost.:8080'))->pattern)->toBe('admin.{root}')
        ->and($domains->resolve(host('localhost'))->pattern)->toBeNull()
        ->and(fn () => $domains->resolve(host('localhost:9999')))->toThrow(HttpException::class);
});

it('refuses a host that names a second port behind the one it is held to', function (string $sent) {
    $domains = new Domains(['root' => 'shop.test'], patterns: ['admin.{root}']);

    expect(fn () => $domains->resolve(host($sent)))->toThrow(HttpException::class);
})->with(['shop.test:8443:443', 'admin.shop.test:1:443', 'shop.test:8443:', 'shop.test::443', 'shop.test:']);

it('refuses an address unless it is listed as an alias of the primary host', function () {
    $plain = Domains::fromUrl('http://localhost:8080');
    $aliased = Domains::fromUrl('http://localhost:8080', aliases: ['127.0.0.1', '::1']);

    expect(fn () => $plain->resolve(host('127.0.0.1:8080')))->toThrow(HttpException::class)
        ->and($aliased->resolve(host('127.0.0.1:8080'))->pattern)->toBeNull()
        ->and($aliased->resolve(host('[::1]:8080'))->pattern)->toBeNull();
});

it('refuses a request that names no host with a 400, never reading it as the primary host', function () {
    $domains = new Domains(['root' => 'shop.test'], patterns: ['admin.{root}']);

    expect(fn () => $domains->resolve(new Request('GET', '/')))->toThrow(HttpException::class, 'names no host')
        ->and($domains->match(''))->toBeNull();
    try {
        $domains->resolve(new Request('GET', '/'));
    } catch (HttpException $e) {
        expect($e->status)->toBe(400);
    }
});

it('believes X-Forwarded-Host from a pinned proxy only, and the value nearest it', function () {
    $domains = new Domains(['root' => 'shop.test'], patterns: ['admin.{root}'], proxies: TrustedProxies::of(['10.0.0.0/8']));

    // From the proxy: the host it wrote, the right-most.
    expect($domains->resolve(host('internal:8080', ['x-forwarded-host' => 'evil.test, admin.shop.test'], '10.1.2.3'))->pattern)->toBe('admin.{root}')
        // From anyone else, the header is the client's own text and is not read.
        ->and($domains->resolve(host('shop.test', ['x-forwarded-host' => 'admin.shop.test'], '203.0.113.9'))->pattern)->toBeNull();

    // Without pinned proxies the header is never read.
    $none = new Domains(['root' => 'shop.test'], patterns: ['admin.{root}']);
    expect($none->resolve(host('shop.test', ['x-forwarded-host' => 'admin.shop.test'], '10.1.2.3'))->pattern)->toBeNull();
});

it('refuses an empty or malformed hop a pinned proxy forwarded, never reading it as no host', function (string $forwarded) {
    $domains = new Domains(['root' => 'shop.test'], patterns: ['admin.{root}'], proxies: TrustedProxies::of(['10.0.0.0/8']));

    expect(fn () => $domains->resolve(host('admin.shop.test', ['x-forwarded-host' => $forwarded], '10.1.2.3')))
        ->toThrow(HttpException::class);
})->with([',', 'shop.test, ', 'shop.test,', ' ', 'shop.test, ,', 'shop test']);

it('carries one resolution on the request for everything after to read', function () {
    $domains = new Domains(['root' => 'shop.test'], patterns: ['admin.{root}']);
    $request = host('evil.test')->withAttribute(HostMatch::ATTRIBUTE, new HostMatch('admin.{root}', 'admin.shop.test'));

    expect($domains->resolve($request)->host)->toBe('admin.shop.test');
});

it('refuses two patterns that match the same hosts, and one that is the primary host', function (array $patterns) {
    new Domains(['root' => 'shop.test', 'admin' => 'admin.shop.test'], patterns: $patterns);
})->throws(\InvalidArgumentException::class)->with([
    'two spellings of one shape' => [['{a}.{root}', '{b}.{root}']],
    'a configured domain and its literal' => [['{admin}', 'admin.shop.test']],
    'the primary host itself' => [['shop.test']],
]);

it('refuses a pattern naming a domain nobody configured', function () {
    new Domains(['root' => 'shop.test'])->serving(['admin.{admn}']);
})->throws(\InvalidArgumentException::class, "configures no domain called 'admn'");

it('answers any port only for hosts that never leave the machine', function () {
    new Domains(['root' => 'shop.test'], scheme: 'http', anyPort: true);
})->throws(\InvalidArgumentException::class);

it('reads its domains from an origin, as an application configures its address', function () {
    $dev = Domains::fromUrl('http://localhost:8080');
    $production = Domains::fromUrl('shop.example.com', ['admin' => 'admin-portal.example.net']);

    expect($dev->origin('admin.{root}'))->toBe('http://admin.localhost:8080')
        ->and($dev->origin(null))->toBe('http://localhost:8080')
        ->and($production->origin('{admin}'))->toBe('https://admin-portal.example.net')
        ->and($production->origin('{account}.{root}', ['account' => 'acme']))->toBe('https://acme.shop.example.com')
        ->and($production->forClient())->toBe([
            'scheme' => 'https',
            'port' => '',
            // The primary host's domain alone: no page is told of a host
            // it links nowhere on (ADR 0149).
            'roots' => ['root' => 'shop.example.com'],
            'primary' => '{root}',
            'current' => ['pattern' => null, 'params' => []],
        ])
        ->and($production->forClient(linked: ['{admin}', null])['roots'])
        ->toBe(['root' => 'shop.example.com', 'admin' => 'admin-portal.example.net'])
        ->and($production->forClient(new HostMatch('{admin}', 'admin-portal.example.net'))['roots'])
        ->toBe(['root' => 'shop.example.com', 'admin' => 'admin-portal.example.net'])
        ->and($production->forClient(new HostMatch('{account}.{root}', 'acme.shop.example.com', ['account' => 'acme']))['current'])
        ->toBe(['pattern' => '{account}.{root}', 'params' => ['account' => 'acme']]);
});

it('serves a tenant\'s custom domain as the pattern it names, with the parameters its lookup gives', function () {
    $domains = new Domains(
        ['root' => 'shop.test'],
        custom: static fn (string $host): ?array => $host === 'acme-store.com' ? ['tenant' => 'acme'] : null,
        customAs: '{tenant}.{root}',
    );

    expect($domains->resolve(host('acme-store.com'))->parameters)->toBe(['tenant' => 'acme'])
        ->and($domains->resolve(host('acme-store.com'))->pattern)->toBe('{tenant}.{root}')
        ->and(fn () => $domains->resolve(host('other.com')))->toThrow(HttpException::class);
});

it('sends route() the hosts with every render, and the page its copy', function () {
    $hosts = Domains::fromUrl('http://localhost:8080')->forClient();
    $frame = \Tinvar\Protocol\Frame::render('shop/orders', [], [], 'rid', 'nonce', [], new \Tinvar\Protocol\PageMetadata('/orders', 'b1'), hosts: $hosts);
    $payload = \Tinvar\Protocol\Frame::render('shop/orders', [], [], 'rid', 'nonce', []);

    expect($frame['hosts'])->toBe($hosts)
        ->and($frame['page']['hosts'])->toBe($hosts)
        ->and($payload)->not->toHaveKey('hosts');
});

it('lets an action send a browser to one of the application\'s own hosts, and to no host like it', function () {
    $domains = Domains::fromUrl('http://localhost:8080', aliases: ['127.0.0.1'])->serving(['admin.{root}']);
    $origins = new \Tinvar\Http\ExternalOrigins()->serving($domains);

    expect($origins->allows('http://admin.localhost:8080/orders'))->toBeTrue()
        ->and($origins->allows('http://localhost:8080/'))->toBeTrue()
        ->and($origins->allows('https://admin.localhost:8080/orders'))->toBeFalse()
        ->and($origins->allows('http://admin.localhost:9999/orders'))->toBeFalse()
        ->and($origins->allows('http://evil.localhost:8080/'))->toBeFalse()
        ->and($origins->allows('http://127.0.0.1:8080/'))->toBeFalse()
        ->and($origins->allows('http://x@admin.localhost:8080/'))->toBeFalse()
        ->and(new \Tinvar\Http\ExternalOrigins()->allows('http://admin.localhost:8080/orders'))->toBeFalse();
});

it('answers any port only on a machine\'s own hosts, when asked to', function () {
    $local = Domains::fromUrl('http://localhost:8080', anyPort: true);

    expect($local->resolve(host('localhost:51234'))->pattern)->toBeNull()
        ->and(fn () => new Domains(['root' => 'shop.test'], anyPort: true))->toThrow(\InvalidArgumentException::class)
        ->and(new Domains(['root' => 'shop.test'], scheme: 'http')->origin(null))->toBe('http://shop.test');
});

it('lets an action send a browser to a host a parameter takes, or a custom domain, only when opted in and over https', function () {
    $custom = static fn (string $host): ?array => $host === 'acme-store.com' ? ['tenant' => 'acme'] : null;
    $closed = new Domains(['root' => 'example.com'], patterns: ['admin.{root}', '{account}.{root}'], custom: $custom, customAs: '{tenant}.shops.{root}');
    $opened = new Domains(['root' => 'example.com'], patterns: ['admin.{root}', '{account}.{root}'], custom: $custom, customAs: '{tenant}.shops.{root}', redirectable: ['{account}.{root}', '{tenant}.shops.{root}']);
    $plain = new Domains(['root' => 'example.com'], scheme: 'http', patterns: ['{account}.{root}'], redirectable: ['{account}.{root}']);

    expect($closed->owns('https://admin.example.com/orders'))->toBeTrue()
        ->and($closed->owns('https://example.com/'))->toBeTrue()
        ->and($closed->owns('https://attacker.example.com/'))->toBeFalse()
        ->and($closed->owns('https://acme-store.com/'))->toBeFalse()
        ->and($opened->owns('https://attacker.example.com/'))->toBeTrue()
        ->and($opened->owns('https://acme-store.com/'))->toBeTrue()
        ->and($plain->owns('http://attacker.example.com/'))->toBeFalse()
        ->and(new \Tinvar\Http\ExternalOrigins()->serving($closed)->allows('https://attacker.example.com/'))->toBeFalse();
});
