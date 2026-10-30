<?php

declare(strict_types=1);

/**
 * GET /_fragment/{token}/{token}/...: one <Deferred group>, loaded in one
 * request (ADR 0018).
 *
 * The security model is the lone token's, applied to each: every token is
 * verified on its own, and they must all name one page -- route, params,
 * panel, tenant, build -- so the route's chain, run once, is the chain each
 * would have run alone. Anything else is the one 404, for the whole request.
 */

namespace Tinvar\Tests\Http\FragmentGroup;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Render\FragmentToken;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

const SESSION = 'session-abc';

final class Rendered
{
    /** @var list<list<string>> the components each group render was handed, in order */
    public array $groups = [];

    /** @var list<string> the component each lone render was handed */
    public array $lone = [];

    /** @var list<list<string>> the query allowlist each render was handed */
    public array $allowed = [];
}

/**
 * @return array{FragmentEndpoint, Fixture, Signer, Rendered, AllowAllGate}
 */
function endpoint(bool $groups = true, ?\Closure $allowedQueries = null): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);
    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);
    $rendered = new Rendered();
    $gate = new AllowAllGate();

    $endpoint = new FragmentEndpoint(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), $gate),
        orm: $orm,
        signer: $signer,
        builds: new BuildRegistry('build-2', ['build-1']),
        render: static function (FragmentToken $token, Request $request, array $models, array $allowed) use ($rendered): Response {
            $rendered->lone[] = $token->component;
            $rendered->allowed[] = $allowed;

            return Response::make(200, 'ONE', ['content-type' => 'text/x-component']);
        },
        sessionId: static fn (Request $r): string => SESSION,
        middlewareResolver: static fn (string $class): Middleware => new $class(),
        panels: new PanelContext(),
        allowedQueries: $allowedQueries,
        renderGroup: $groups ? static function (array $tokens, Request $request, array $models, array $allowed) use ($rendered): Response {
            $rendered->groups[] = array_map(static fn (FragmentToken $t): string => $t->component, $tokens);
            $rendered->allowed[] = $allowed;

            return Response::make(200, 'MANY', ['content-type' => 'text/x-component']);
        } : null,
    );

    return [$endpoint, $fixture, $signer, $rendered, $gate];
}

/** @param array<string, mixed> $overrides */
function token(Signer $signer, Fixture $fixture, array $overrides = []): FragmentToken
{
    return new FragmentToken(
        component: $overrides['component'] ?? 'UnitHistory',
        props: $overrides['props'] ?? [],
        route: 'racks.show',
        params: $overrides['params'] ?? ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
        panel: '',
        tenant: '',
        build: $overrides['build'] ?? 'build-2',
        sessionHash: $overrides['sessionHash'] ?? FragmentToken::hashSession($signer, SESSION),
        expiresAt: $overrides['expiresAt'] ?? time() + 3600,
        issuedAt: time(),
    );
}

/** @param list<FragmentToken|string> $tokens */
function group(Signer $signer, array $tokens): Request
{
    return new Request('GET', FragmentEndpoint::PREFIX . implode('/', array_map(
        static fn (FragmentToken|string $t): string => \is_string($t) ? $t : $t->encode($signer),
        $tokens,
    )));
}

it('renders a group of one page\'s boundaries in one render, in the order they were sent', function () {
    [$endpoint, $fixture, $signer, $rendered] = endpoint();

    $response = $endpoint->handle(group($signer, [
        token($signer, $fixture, ['component' => 'UnitHistory']),
        token($signer, $fixture, ['component' => 'RackPower']),
        token($signer, $fixture, ['component' => 'RackNotes']),
    ]));

    expect($response->status)->toBe(200)
        ->and($response->body())->toBe('MANY')
        ->and($response->header('content-type'))->toBe('text/x-component')
        ->and($response->header('cache-control'))->toBe('private, no-store')
        ->and($rendered->groups)->toBe([['UnitHistory', 'RackPower', 'RackNotes']])
        ->and($rendered->lone)->toBe([]);
});

// One page, so one chain: the #[Authorize] checks a lone token runs, run once
// for the whole group rather than once per token.
it('runs the page\'s chain once for the whole group', function () {
    [$endpoint, $fixture, $signer, , $gate] = endpoint();
    $endpoint->handle(group($signer, [token($signer, $fixture)]));
    $alone = \count($gate->checks);

    [$grouped, $fixture, $signer, , $gate] = endpoint();
    $grouped->handle(group($signer, [
        token($signer, $fixture, ['component' => 'A']),
        token($signer, $fixture, ['component' => 'B']),
    ]));

    expect($alone)->toBeGreaterThan(0)
        ->and(\count($gate->checks))->toBe($alone);
});

it('hands each boundary its refreshed token, in order', function () {
    [$endpoint, $fixture, $signer] = endpoint();

    $response = $endpoint->handle(group($signer, [
        token($signer, $fixture, ['component' => 'A', 'expiresAt' => time() + 5]),
        token($signer, $fixture, ['component' => 'B', 'expiresAt' => time() + 5]),
    ]));

    $refreshed = array_map(
        static fn (string $t): string => FragmentToken::decode($t, $signer)->component,
        explode(',', (string) $response->header('x-fragment-token')),
    );

    expect($refreshed)->toBe(['A', 'B']);
});

// A lone token keeps the payload a client from before groups expects.
it('renders a lone token as it always has', function () {
    [$endpoint, $fixture, $signer, $rendered] = endpoint();

    $response = $endpoint->handle(group($signer, [token($signer, $fixture)]));

    expect($response->body())->toBe('ONE')
        ->and($rendered->lone)->toBe(['UnitHistory'])
        ->and($rendered->groups)->toBe([]);
});

describe('what a group must satisfy', function () {
    // Two pages the caller may each see are still not a group: the chain
    // run once would be one page's, and the other's tokens would ride on it.
    it('refuses tokens minted for different pages', function () {
        [$endpoint, $fixture, $signer, $rendered] = endpoint();

        expect(fn () => $endpoint->handle(group($signer, [
            token($signer, $fixture),
            token($signer, $fixture, ['params' => ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][1]]]),
        ])))->toThrow(HttpException::class, 'different pages');

        expect($rendered->groups)->toBe([]);
    });

    it('refuses the whole group for one token it will not act on', function (array $overrides) {
        [$endpoint, $fixture, $signer, $rendered] = endpoint();

        expect(fn () => $endpoint->handle(group($signer, [
            token($signer, $fixture),
            token($signer, $fixture, ['component' => 'B', ...$overrides]),
        ])))->toThrow(HttpException::class, 'Fragment unavailable');

        expect($rendered->groups)->toBe([]);
    })->with([
        'another session' => [['sessionHash' => 'not-this-session']],
        'a revoked build' => [['build' => 'build-0']],
        'expired' => [['expiresAt' => time() - 10]],
    ]);

    it('refuses a token that does not verify among good ones', function () {
        [$endpoint, $fixture, $signer] = endpoint();

        expect(fn () => $endpoint->handle(group($signer, [token($signer, $fixture), 'v1.forged.mac'])))
            ->toThrow(HttpException::class, 'Fragment unavailable');
    });

    it('refuses the same token twice', function () {
        [$endpoint, $fixture, $signer] = endpoint();
        $token = token($signer, $fixture);

        expect(fn () => $endpoint->handle(group($signer, [$token, $token])))
            ->toThrow(HttpException::class, 'repeated');
    });

    it('refuses more tokens than one group may carry', function () {
        [$endpoint, $fixture, $signer] = endpoint();
        $tokens = array_map(
            static fn (int $i): FragmentToken => token($signer, $fixture, ['component' => "C{$i}"]),
            range(0, FragmentEndpoint::MAX_GROUP),
        );

        expect(fn () => $endpoint->handle(group($signer, $tokens)))->toThrow(HttpException::class, 'more of them');
    });

    // The tokens are good and the page has a group on it; the application
    // has not said how to render one. That is a bug to see, not a refusal.
    it('fails loudly where the application has no group renderer', function () {
        [$endpoint, $fixture, $signer] = endpoint(groups: false);

        expect(fn () => $endpoint->handle(group($signer, [
            token($signer, $fixture, ['component' => 'A']),
            token($signer, $fixture, ['component' => 'B']),
        ])))->toThrow(\LogicException::class, 'no renderGroup');
    });
});

describe('what a group is charged', function () {
    it('counts the tokens in the path, before anything is verified', function () {
        expect(FragmentEndpoint::tokenCount(new Request('GET', '/_fragment/a')))->toBe(1)
            ->and(FragmentEndpoint::tokenCount(new Request('GET', '/_fragment/a/b/c')))->toBe(3)
            ->and(FragmentEndpoint::tokenCount(new Request('GET', '/_fragment/' . str_repeat('a/', 40))))
            ->toBe(FragmentEndpoint::MAX_GROUP);
    });
});

it("renders each fragment under its own component's allowlist, and a group under its components'", function () {
    $manifest = new \Tinvar\Render\FragmentManifest()
        ->declareQueries('racks.show', ['racks.stats', 'units.history', 'audit.entries'])
        ->declareComponentQueries('UnitHistory', ['units.history'])
        ->declareComponentQueries('AuditLog', ['audit.entries']);
    [$endpoint, $fixture, $signer, $rendered] = endpoint(allowedQueries: $manifest->queriesFor(...));

    $endpoint->handle(group($signer, [token($signer, $fixture, ['component' => 'UnitHistory'])]));
    $endpoint->handle(group($signer, [
        token($signer, $fixture, ['component' => 'UnitHistory']),
        token($signer, $fixture, ['component' => 'AuditLog']),
    ]));
    $endpoint->handle(group($signer, [token($signer, $fixture, ['component' => 'Unlisted'])]));

    // Never the page's own racks.stats; nothing for a component no build listed.
    expect($rendered->allowed)->toBe([['units.history'], ['units.history', 'audit.entries'], []]);
});
