<?php

declare(strict_types=1);

/**
 * Endpoints matched on host and path, as pages are (ADR 0149): one path on
 * two hosts, a host parameter as an input, and a page and an endpoint
 * overlapping only when they share a host.
 */

namespace Tinvar\Tests\Endpoints\EndpointHost;

use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\Request;
use Tinvar\Routing\Domains;
use Tinvar\Routing\RouteRegistry;

final class Feeds
{
    #[Get('/feed', name: 'shop.feed'), NoAuthorization(reason: 'test')]
    public function shop(): void {}

    #[Get('/feed', name: 'admin.feed'), Domain('admin.{root}'), NoAuthorization(reason: 'test')]
    public function admin(): void {}

    #[Get('/status', name: 'accounts.status'), Domain('{account}.{root}'), NoAuthorization(reason: 'test')]
    public function account(string $account): void {}
}

final class Clash
{
    #[Get('/x/{account}', name: 'clash'), Domain('{account}.{root}'), NoAuthorization(reason: 'test')]
    public function clash(string $account): void {}
}

#[Page('/feed', view: 'admin/feed'), Domain('admin.{root}')]
final class AdminFeedPage {}

function registry(): EndpointRegistry
{
    $endpoints = new EndpointRegistry();
    $endpoints->register(Feeds::class);
    $endpoints->serveOn(new Domains(['root' => 'shop.test'])->serving($endpoints->hostPatterns()));

    return $endpoints;
}

function on(string $host, string $path): ?array
{
    return registry()->match(new Request('GET', $path, ['host' => $host]));
}

it('answers one path with each host\'s own endpoint', function () {
    expect(on('shop.test', '/feed')['endpoint']->name)->toBe('shop.feed')
        ->and(on('admin.shop.test', '/feed')['endpoint']->name)->toBe('admin.feed');
});

it('hands a host parameter to the endpoint as an input', function () {
    expect(on('acme.shop.test', '/status')['parameters'])->toBe(['account' => 'acme'])
        ->and(on('shop.test', '/status'))->toBeNull();
});

it('matches nothing on a host the application does not serve', function () {
    expect(on('evil.test', '/feed'))->toBeNull();
});

it('refuses a parameter taken from both the host and the path', function () {
    new EndpointRegistry()->register(Clash::class);
})->throws(EndpointException::class, 'both its host and its path');

it('holds a page and an endpoint apart only on the host they share', function () {
    $routes = new RouteRegistry();
    $routes->register(AdminFeedPage::class);

    $conflicts = array_map(static fn ($v) => $v->where, registry()->conflicts($routes));

    expect($conflicts)->toBe([Feeds::class . '::admin()']);
});
