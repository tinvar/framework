<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\MintGate;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\FragmentToken;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures as R;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

/**
 * Which renders may mint fragment tokens.
 *
 * ADR 0009: "A page with no deferred boundaries cannot be talked into
 * minting." Every named page with a session was handed a minter, so a
 * compromised renderer could have the signing key used from any page. Now a
 * render gets one only when the fragment manifest says its route has a
 * boundary, and otherwise none -- a mint frame from it is a protocol
 * violation.
 */
function signer(): Signer
{
    return new Signer(str_repeat('k', 32), FragmentToken::PURPOSE);
}

/** @return array{0: Kernel, 1: string} the kernel and the racks.show path */
function kernel(?FragmentManifest $manifest, string $session = 'session-abc'): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    $routes = new RouteRegistry();
    $routes->registerAll([R\RootLayout::class, R\SiteLayout::class, R\RackShowPage::class, R\HealthPage::class]);

    $kernel = new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new AllowAllGate(),
        fragments: new FragmentSupport(
            signer: signer(),
            builds: new BuildRegistry('b1'),
            render: static fn (): Response => Response::make(200),
            sessionId: static fn (Request $r): string => $session,
        ),
        manifest: $manifest,
    );

    return [$kernel, '/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0]];
}

/** The minter the Kernel handed the page render, if any. */
function minterFor(Kernel $kernel, string $path): ?FragmentMinter
{
    $minter = false;
    $response = $kernel->handle(
        Request::get($path),
        static function (LoadedRoute $loaded, Request $request) use (&$minter): Response {
            $minter = $request->attribute(Kernel::FRAGMENT_MINTER);

            return Response::make(200);
        },
    );

    expect($response->status)->toBe(200, 'the page rendered');
    \assert($minter === null || $minter instanceof FragmentMinter);

    return $minter;
}

it('hands a minter to the render of a route with a boundary, bound to that route', function () {
    [$kernel, $path] = kernel(new FragmentManifest(routes: ['racks.show']));

    $minter = minterFor($kernel, $path);
    expect($minter)->toBeInstanceOf(FragmentMinter::class);

    // Route and params come from the chain that matched, not from anyone
    // describing it.
    $token = FragmentToken::decode($minter->mint('UnitHistory', ['unit' => 'u-1']), signer());
    expect($token->route)->toBe('racks.show')
        ->and('/sites/' . $token->params['site'] . '/racks/' . $token->params['rack'])->toBe($path)
        ->and($token->belongsTo(signer(), 'session-abc'))->toBeTrue();
});

it('hands none to the render of a route without one', function () {
    [$kernel] = kernel(new FragmentManifest(routes: ['racks.show']));

    expect(minterFor($kernel, '/health'))->toBeNull();
});

it('hands none to any render when there is no manifest to say', function () {
    [$kernel, $path] = kernel(null);

    expect(minterFor($kernel, $path))->toBeNull();
});

it('hands none without a session, which the minter would refuse', function () {
    [$kernel, $path] = kernel(new FragmentManifest(routes: ['racks.show']), session: '');

    expect(minterFor($kernel, $path))->toBeNull();
});

// A statically-built page's boundaries are declared per page; the route
// they belong to defers wherever it is rendered.
it('counts a route with a statically-built boundary as one that defers', function () {
    $manifest = (new FragmentManifest())->declare('racks.show', 'history', 'UnitHistory', [], ['rack' => 'r-1']);
    [$kernel, $path] = kernel($manifest);

    expect($manifest->defersOn('racks.show'))->toBeTrue()
        ->and($manifest->defersOn('health'))->toBeFalse()
        ->and(minterFor($kernel, $path))->toBeInstanceOf(FragmentMinter::class);
});

describe('the manifest', function () {
    it('says which routes defer', function () {
        $manifest = new FragmentManifest(routes: ['racks.history']);

        expect($manifest->defersOn('racks.history'))->toBeTrue()
            ->and($manifest->defersOn('racks.show'))->toBeFalse();
    });

    // A route declared by name is rendered dynamically and mints as it
    // renders; it has nothing for a bootstrap request to hand out.
    it('does not mount a bootstrap endpoint for routes declared by name', function () {
        [$kernel] = kernel(new FragmentManifest(routes: ['racks.show']));

        expect((new FragmentManifest(routes: ['racks.show']))->hasAny())->toBeFalse()
            ->and($kernel->bootstrap_)->toBeNull();
    });

    it('refuses a nameless route', function () {
        expect(fn () => new FragmentManifest(routes: ['']))->toThrow(\InvalidArgumentException::class);
    });
});
