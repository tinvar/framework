<?php

declare(strict_types=1);

/**
 * The spec writes `revalidate('racks/show')`, a view path, and only route
 * names ever matched a token -- so the spec's own example re-rendered
 * nothing. A target that referred to no route at all failed just as
 * silently.
 */

namespace Tinvar\Tests\Actions\RevalidationTargets;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Actions\Revalidator;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

const SESSION = 'session-targets';

#[Page('/about', view: 'about')]
final class UnnamedAbout {}

final class TargetActions
{
    #[Action, NoAuthorization(reason: 'fixture: revalidation targets, not access control')]
    public function typo(): ActionResult
    {
        return ActionResult::revalidate('racks/shwo');
    }
}

/** @return array{0: Revalidator, 1: Fixture, 2: Signer, 3: Orm} */
function revalidator(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $signer = new Signer('test-app-key', RouteToken::PURPOSE);

    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class, UnnamedAbout::class]);

    return [new Revalidator(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
        signer: $signer,
        orm: $orm,
        render: static fn (LoadedRoute $loaded, Request $r): string => 'payload:' . $loaded->chain->page->name,
        builds: new BuildRegistry('build-1'),
        middlewareResolver: static fn (string $class): \Tinvar\Http\Middleware => new $class(),
        panels: new PanelContext(),
    ), $fixture, $signer, $orm];
}

function token(Signer $signer, Fixture $fixture): string
{
    return (new RouteToken(new TokenClaims(
        route: 'racks.show',
        params: ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
        panel: '',
        tenant: '',
        build: 'build-1',
        sessionHash: TokenClaims::hashSession($signer, SESSION),
        expiresAt: time() + 3600,
        issuedAt: time(),
    )))->encode($signer);
}

it("re-renders the page a view path names, the spec's form", function () {
    [$revalidator, $fixture, $signer] = revalidator();

    $payloads = $revalidator->rerender(
        ActionResult::revalidate('racks/show')->revalidatedRoutes,
        [token($signer, $fixture)],
        Request::post('/_action/x'),
        static fn (): string => SESSION,
    );

    expect(array_values($payloads))->toBe(['payload:racks.show']);
});

it('maps both forms to the route name', function () {
    [$revalidator] = revalidator();

    expect($revalidator->routesFor(['racks/show', 'racks.show']))->toBe(['racks.show']);
});

it('refuses a target that refers to no named page, even with no token to match', function (string $target) {
    [$revalidator] = revalidator();

    expect(fn () => $revalidator->rerender([$target], [], Request::post('/_action/x'), static fn (): string => SESSION))
        ->toThrow(RoutingException::class, "revalidates '{$target}'");
})->with([
    'a typo' => ['racks/shwo'],
    'an unknown name' => ['racks.gone'],
    "an unnamed page's view" => ['about'],
    "a layout's view" => ['sites/layout'],
]);

it('refuses it when the action endpoint answers the result, whatever tokens the client sent', function () {
    [$revalidator, , , $orm] = revalidator();
    $registry = new ActionRegistry();
    $registry->register(TargetActions::class);

    $endpoint = new ActionEndpoint(
        new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm)),
        revalidator: $revalidator,
        sessionId: static fn (): string => SESSION,
    );

    expect(fn () => $endpoint->handle(Request::post('/_action/TargetActions.typo'), '{}'))
        ->toThrow(RoutingException::class, "revalidates 'racks/shwo'");
});
