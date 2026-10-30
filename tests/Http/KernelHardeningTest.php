<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\KernelHardening;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

const ADDRESS = '192.0.2.10';

/**
 * @param array<string, int> $limits
 * @return array{0: Kernel, 1: Fixture, 2: AuthContext}
 */
function hardenedKernel(array $limits = [], ?AuthContext $throttleAuth = null): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $auth = new AuthContext();
    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);
    $actions = new ActionRegistry();
    $actions->register(A\RackActions::class);

    $kernel = new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: (new PolicyRegistry())->registerAll([Rack::class => A\RecordingRackPolicy::class, Site::class => A\RecordingSitePolicy::class]),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        throttle: new Throttle(new ArrayThrottleStore(), $throttleAuth ?? $auth, static fn (Request $r): string => $r->remoteAddress ?? ''),
        limits: $limits,
    );

    return [$kernel, $fixture, $auth];
}

function noPage(): \Closure
{
    return static fn (): Response => throw new \LogicException('no page here');
}

/** A POST a page on another site can make the visitor's browser send: no token, no cookie. */
function crossSitePost(string $path): Request
{
    return new Request('POST', $path, [], [], [], [], null, ADDRESS);
}

/** The visitor's own POST, carrying the double-submitted token. */
function sameSitePost(string $path): Request
{
    return new Request('POST', $path, ['x-xsrf-token' => 'tok'], [], [], [VerifyCsrfToken::COOKIE => 'tok'], null, ADDRESS);
}

// A hostile page in the visitor's browser sends from the visitor's address.
// Counted before CSRF, sixty of those spent the visitor's own action budget.
it('does not let requests that fail CSRF spend the budget of the address they came from', function () {
    [$kernel] = hardenedKernel(Kernel::DEFAULT_LIMITS);

    for ($i = 0; $i < Kernel::DEFAULT_LIMITS['actions'] + 5; $i++) {
        expect($kernel->handle(crossSitePost('/_action/RackActions.moveUnit'), noPage(), '{}')->status)->toBe(419);
    }

    $own = $kernel->handle(sameSitePost('/_action/RackActions.moveUnit'), noPage(), '{}');
    expect($own->status)->not->toBe(429);
});

it('still refuses a flood before anything else runs', function () {
    [$kernel] = hardenedKernel(['actions' => 2]);

    $codes = [];
    for ($i = 0; $i < 2 * 10 + 1; $i++) {
        $codes[] = $kernel->handle(crossSitePost('/_action/RackActions.moveUnit'), noPage(), '{}')->status;
    }

    expect(array_count_values($codes))->toBe([419 => 20, 429 => 1]);
});

it('advertises the budget a client should pace itself by, not the flood guard', function () {
    [$kernel, $fixture, $auth] = hardenedKernel(['actions' => 5]);
    $auth->login(new \Tinvar\Tests\Auth\Fixtures\User('u-1'));

    $response = $kernel->handle(
        sameSitePost('/_action/RackActions.moveUnit'),
        noPage(),
        json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
    );

    expect($response->header('x-ratelimit-limit'))->toBe('5');
});

// Passing one bucket replaced the whole table, switching the others off.
it('keeps the default for every bucket a partial limits table leaves out', function () {
    [$kernel] = hardenedKernel(['actions' => 3]);

    $codes = [];
    for ($i = 0; $i < Kernel::DEFAULT_LIMITS['queries'] + 1; $i++) {
        $codes[] = $kernel->handle(sameSitePost('/_query/none.here'), noPage(), '{}')->status;
    }

    expect(end($codes))->toBe(429);
});

it('turns a bucket off only when it is set to 0', function () {
    [$kernel] = hardenedKernel(['actions' => 0]);

    $response = $kernel->handle(sameSitePost('/_action/RackActions.moveUnit'), noPage(), '{}');

    expect($response->header('x-ratelimit-limit'))->toBeNull();
});

// The account pass reads who is signed in from the context it was built
// with; the example built it with a fresh one, so it never saw anyone.
it('refuses a throttle that reads a different auth context', function () {
    expect(fn () => hardenedKernel(throttleAuth: new AuthContext()))
        ->toThrow(\InvalidArgumentException::class, 'different AuthContext');
});

describe('a page response', function () {
    $path = static fn (Fixture $f): string => '/sites/' . $f->ids['sites'][0] . '/racks/' . $f->ids['racks'][0];

    // It ran loaders and authorization for whoever asked.
    it('is private by default', function () use ($path) {
        [$kernel, $fixture] = hardenedKernel();

        $response = $kernel->handle(new Request('GET', $path($fixture)), static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'));

        expect($response->header('cache-control'))->toBe('private, no-cache')
            ->and($response->header('vary'))->toContain('Cookie');
    });

    it('keeps a cache policy the page chose', function () use ($path) {
        [$kernel, $fixture] = hardenedKernel();

        $response = $kernel->handle(
            new Request('GET', $path($fixture)),
            static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page', ['cache-control' => 'public, max-age=60']),
        );

        expect($response->header('cache-control'))->toBe('public, max-age=60');
    });
});
