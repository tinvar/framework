<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\EndpointsSurface;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Actions\Revalidator;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Endpoints\QueryEndpoint;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Actions\Fixtures\RackActions;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Query\Fixtures\RackSummary;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

const SESSION = 'session-abc';

final class RedirectActions
{
    #[Action, NoAuthorization(reason: 'test')]
    public function signOut(): ActionResult
    {
        return ActionResult::redirect('/login')->withTags('session', 'nav');
    }
}

/** @return list<string> */
function chainClasses(): array
{
    return [RootLayout::class, SiteLayout::class, RackShowPage::class];
}

/** @return array{0: ActionEndpoint, 1: Fixture, 2: Signer} */
function actionEndpoint(bool $revalidating = false): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $registry = new ActionRegistry();
    $registry->register(RackActions::class);
    $registry->register(RedirectActions::class);
    $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));
    $signer = new Signer('test-app-key', RouteToken::PURPOSE);

    if (!$revalidating) {
        return [new ActionEndpoint($runner), $fixture, $signer];
    }

    $routes = new RouteRegistry();
    $routes->registerAll(chainClasses());

    $revalidator = new Revalidator(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
        signer: $signer,
        orm: $orm,
        render: static fn (LoadedRoute $loaded, Request $r): string => 'payload:' . $loaded->chain->page->name,
        builds: new BuildRegistry('build-1'),
        middlewareResolver: static fn (string $class): Middleware => new $class(),
    );

    return [
        new ActionEndpoint($runner, revalidator: $revalidator, sessionId: static fn (Request $r): string => SESSION),
        $fixture,
        $signer,
    ];
}

function routeToken(Signer $signer, Fixture $fixture): string
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

describe('the action endpoint', function () {
    it('refuses a request that names no action', function () {
        [$endpoint] = actionEndpoint();

        expect(fn () => $endpoint->handle(Request::post('/_action/')))
            ->toThrow(HttpException::class, 'No action named in the path.');
    });

    // 303, so reloading the page the browser lands on does not repeat the write.
    it('answers a redirecting action with a 303 that still lists its invalidated tags', function () {
        [$endpoint] = actionEndpoint();

        $response = $endpoint->handle(Request::post('/_action/RedirectActions.signOut'), '{}');

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/login')
            ->and($response->header('x-invalidated-tags'))->toBe('session,nav')
            ->and($response->body())->toBe('');
    });

    it('re-renders only from a list of route tokens, not from a map of them', function () {
        [$endpoint, $fixture, $signer] = actionEndpoint(revalidating: true);
        $input = ['rack' => $fixture->ids['racks'][0], 'position' => 12];
        $token = routeToken($signer, $fixture);

        $asList = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode([...$input, '_routes' => [$token]]),
        );
        $asMap = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode([...$input, '_routes' => ['racks.show' => $token]]),
        );

        expect(array_values(json_decode($asList->body(), true)['payloads']))->toBe(['payload:racks.show'])
            ->and($asMap->status)->toBe(200)
            ->and(json_decode($asMap->body(), true)['payloads'])->toBe([]);
    });
});

/** @return array{0: BootstrapEndpoint, 1: Fixture, 2: Signer} */
function bootstrapEndpoint(FragmentManifest $manifest, Fixture $fixture): array
{
    $routes = new RouteRegistry();
    $routes->registerAll(chainClasses());
    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);

    $support = new FragmentSupport(
        signer: $signer,
        builds: new BuildRegistry('build-1'),
        render: static fn (): mixed => null,
        sessionId: static fn (Request $r): string => SESSION,
    );

    return [new BootstrapEndpoint($routes, $manifest, $support), $fixture, $signer];
}

describe('the bootstrap endpoint', function () {
    it('refuses params that are not an object', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        [$endpoint] = bootstrapEndpoint(new FragmentManifest(), $fixture);

        expect(fn () => $endpoint->handle(
            Request::post(BootstrapEndpoint::PATH),
            json_encode(['route' => 'racks.show', 'params' => 'site=1']),
        ))->toThrow(HttpException::class, 'Bootstrap params must be an object.');
    });

    // A boundary this server will not sign is a build problem: the page's
    // other boundaries still get their tokens.
    it('omits a boundary it will not sign and still mints the rest', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $params = ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]];
        $manifest = (new FragmentManifest())
            ->declare('racks.show', 'broken', '../not a component', [], $params)
            ->declare('racks.show', 'history', 'UnitHistory', [], $params);
        [$endpoint, , $signer] = bootstrapEndpoint($manifest, $fixture);

        $response = $endpoint->handle(
            Request::post(BootstrapEndpoint::PATH),
            json_encode(['route' => 'racks.show', 'params' => $params]),
        );
        $tokens = json_decode($response->body(), true)['tokens'];

        expect($response->status)->toBe(200)
            ->and(array_keys($tokens))->toBe(['history'])
            ->and(FragmentToken::decode($tokens['history'], $signer)->component)->toBe('UnitHistory');
    });
});

/**
 * @param \Closure(FragmentToken, Request, array<string, mixed>, list<string>): Response $render
 * @return array{0: FragmentEndpoint, 1: Fixture, 2: Signer}
 */
function fragmentEndpoint(\Closure $render): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $routes = new RouteRegistry();
    $routes->registerAll(chainClasses());
    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);

    $endpoint = new FragmentEndpoint(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
        orm: $orm,
        signer: $signer,
        builds: new BuildRegistry('build-1'),
        render: $render,
        sessionId: static fn (Request $r): string => SESSION,
        middlewareResolver: static fn (string $class): Middleware => new $class(),
    );

    return [$endpoint, $fixture, $signer];
}

/** @param array{route?: string, issuedAt?: int, expiresAt?: int} $overrides */
function fragmentRequest(Signer $signer, Fixture $fixture, array $overrides = []): Request
{
    $token = new FragmentToken(
        component: 'UnitHistory',
        props: [],
        route: $overrides['route'] ?? 'racks.show',
        params: ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
        panel: '',
        tenant: '',
        build: 'build-1',
        sessionHash: FragmentToken::hashSession($signer, SESSION),
        expiresAt: $overrides['expiresAt'] ?? time() + 3600,
        issuedAt: $overrides['issuedAt'] ?? time(),
    );

    return new Request('GET', FragmentEndpoint::PREFIX . $token->encode($signer));
}

function rendersPayload(): \Closure
{
    return static fn (): Response => Response::make(200, 'RSC-PAYLOAD');
}

describe('the fragment endpoint', function () {
    // Deploy skew: the route was renamed or removed after the token was minted.
    it('answers 404 for a token naming a route this server no longer has', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint(rendersPayload());

        try {
            $endpoint->handle(fragmentRequest($signer, $fixture, ['route' => 'racks.renamed']));
            expect()->fail('a token for an unknown route was acted on');
        } catch (HttpException $e) {
            expect($e->status)->toBe(404)
                ->and($e->code_)->toBe('fragment_unavailable');
        }
    });

    it('passes a failure the renderer produced through without refreshing the token', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint(
            static fn (): Response => Response::make(500, 'render failed', ['content-type' => 'text/plain']),
        );

        $response = $endpoint->handle(fragmentRequest($signer, $fixture));

        expect($response->status)->toBe(500)
            ->and($response->body())->toBe('render failed')
            ->and($response->header('x-fragment-token'))->toBeNull()
            ->and($response->header('content-type'))->toBe('text/plain');
    });
});

/** @return array{0: QueryEndpoint, 1: Fixture} */
function queryEndpoint(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 2);
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $registry = new QueryRegistry(static fn (string $c): object => new $c());
    $registry->register(RackSummary::class);
    $runner = new QueryRunner($registry, $orm, new AllowAllGate());

    return [new QueryEndpoint($runner, static fn (): array => ['racks.summary']), $fixture];
}

describe('the query endpoint', function () {
    it('refuses a request that names no query', function () {
        [$endpoint] = queryEndpoint();

        expect(fn () => $endpoint->handle(Request::post('/_query/')))
            ->toThrow(HttpException::class, 'No query named in the path.');
    });

    it('refuses arguments that are not a JSON object', function (mixed $args) {
        [$endpoint] = queryEndpoint();

        expect(fn () => $endpoint->handle(Request::post('/_query/racks.summary'), json_encode(['args' => $args])))
            ->toThrow(HttpException::class, 'Query arguments must be a JSON object.');
    })->with([
        'a list' => [['r1', 'r2']],
        'a string' => ['r1'],
        'a number' => [7],
    ]);
});
