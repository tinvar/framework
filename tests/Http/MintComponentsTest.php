<?php

declare(strict_types=1);

/**
 * Which components a render may mint for.
 *
 * ADR 0009 left one exposure standing: a renderer running hostile code can
 * have tokens signed "for subtrees the page did not contain", and closing it
 * "needs the component graph to be known to PHP ahead of the render". The
 * fragment manifest now carries the components the build found each route's
 * boundaries naming, and the page's minter signs for those alone.
 */

namespace Tinvar\Tests\Http\MintComponents;

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
use Tinvar\Render\FragmentTokenError;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures as R;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

function signer(): Signer
{
    return new Signer(str_repeat('k', 32), FragmentToken::PURPOSE);
}

/** The minter the Kernel hands a render of racks.show under this manifest. */
function minter(FragmentManifest $manifest): FragmentMinter
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $routes = new RouteRegistry();
    $routes->registerAll([R\RootLayout::class, R\SiteLayout::class, R\RackShowPage::class]);

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
            sessionId: static fn (Request $r): string => 'session-abc',
        ),
        manifest: $manifest,
    );

    $minter = null;
    $kernel->handle(
        Request::get('/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0]),
        static function (LoadedRoute $loaded, Request $request) use (&$minter): Response {
            $minter = $request->attribute(Kernel::FRAGMENT_MINTER);

            return Response::make(200);
        },
    );

    expect($minter)->toBeInstanceOf(FragmentMinter::class);
    \assert($minter instanceof FragmentMinter);

    return $minter;
}

it('signs for a component the build found on the route', function () {
    $minter = minter(new FragmentManifest()->declareRoute('racks.show', ['UnitHistory', 'AuditLog']));

    expect(FragmentToken::decode($minter->mint('AuditLog', []), signer())->component)->toBe('AuditLog');
});

// One boundary refused, as an oversized props object is: the render channel
// answers that mint with no token and the page carries on.
it('refuses a component the route does not render', function () {
    $minter = minter(new FragmentManifest()->declareRoute('racks.show', ['UnitHistory']));

    expect(fn () => $minter->mint('AdminPanel', ['all' => true]))
        ->toThrow(FragmentTokenError::class, 'not one this route renders');
});

// The build could not read a name, so it could not narrow.
it('signs for any component where the build allowed any', function () {
    $minter = minter(new FragmentManifest()->declareRoute('racks.show'));

    expect(FragmentToken::decode($minter->mint('Anything', []), signer())->component)->toBe('Anything');
});

it('takes the components from another route as no permission here', function () {
    $minter = minter(new FragmentManifest()
        ->declareRoute('racks.show', ['UnitHistory'])
        ->declareRoute('health', ['AdminPanel']));

    expect(fn () => $minter->mint('AdminPanel', []))->toThrow(FragmentTokenError::class);
});
