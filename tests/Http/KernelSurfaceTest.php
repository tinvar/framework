<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\KernelSurface;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\ExceptionHandler;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Actions\Fixtures\RackActions;
use Tinvar\Tests\Auth\Fixtures\User as AuthUser;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

const SESSION = 'session-abc';

/**
 * A kernel over the rack chain, with the given fragment support.
 *
 * @param array<string, mixed> $options extra Kernel arguments
 * @return array{0: Kernel, 1: Fixture}
 */
function kernel(?FragmentSupport $fragments = null, ?AuthUser $user = new AuthUser('u-1'), array $options = []): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

    $actions = new ActionRegistry();
    $actions->register(RackActions::class);

    $auth = new AuthContext();
    $auth->login($user);

    $kernel = new Kernel(...[
        'orm' => new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat()),
        'routes' => $routes,
        'queries' => new QueryRegistry(),
        'actions' => $actions,
        'auth' => $auth,
        'policies' => new PolicyRegistry(),
        'resolver' => static fn (string $class): object => new $class(),
        'allowedQueries' => static fn (Request $r): array => [],
        'gate' => new AllowAllGate(),
        'fragments' => $fragments,
        ...$options,
    ]);

    return [$kernel, $fixture];
}

function support(?Signer $routeSigner = null, ?\Closure $renderRoute = null, ?PanelContext $panels = null): FragmentSupport
{
    return new FragmentSupport(
        signer: new Signer('k', FragmentToken::PURPOSE),
        builds: new BuildRegistry('b1'),
        render: static fn (): Response => Response::make(200, 'RSC'),
        sessionId: static fn (Request $r): string => SESSION,
        panels: $panels,
        renderRoute: $renderRoute,
        routeSigner: $routeSigner,
    );
}

function showPath(Fixture $fixture): string
{
    return '/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0];
}

function guardedPost(string $path): Request
{
    return new Request('POST', $path, ['x-xsrf-token' => 'tok'], cookies: [VerifyCsrfToken::COOKIE => 'tok']);
}

describe('composing the kernel', function () {
    // Minting and verifying would read two contexts, so a fragment minted
    // under one panel would be checked against one that never saw the switch.
    it('refuses fragment support carrying a different panel context than its own', function () {
        expect(fn () => kernel(support(panels: new PanelContext()), options: ['panels' => new PanelContext()]))
            ->toThrow(\InvalidArgumentException::class, 'FragmentSupport carries a different PanelContext');
    });

    it('names a global middleware class that does not exist', function () {
        expect(fn () => kernel(options: ['globalMiddleware' => ['Tinvar\\Tests\\Http\\KernelSurface\\Missing']]))
            ->toThrow(\LogicException::class, 'No middleware class Tinvar\\Tests\\Http\\KernelSurface\\Missing.');
    });
});

describe('dispatching', function () {
    it('hands a fragment request to the fragment endpoint once one is mounted', function () {
        [$kernel] = kernel(support());

        $response = $kernel->handle(Request::get('/_fragment/not-a-token'), static fn (): Response => Response::make());

        expect($response->status)->toBe(404)
            ->and(json_decode($response->body(), true)['error']['code'])->toBe('fragment_unavailable');
    });

    // Telling an anonymous caller "no such action" would be an enumeration
    // oracle, so an unknown name is refused exactly like a known one.
    it('refuses an anonymous caller naming an unknown action as it would a known one', function () {
        [$kernel] = kernel(user: null);

        $unknown = $kernel->handle(guardedPost('/_action/Nope.nothing'), static fn (): Response => Response::make(), '{}');
        $known = $kernel->handle(guardedPost('/_action/RackActions.ping'), static fn (): Response => Response::make(), '{}');

        expect($unknown->status)->toBe(401)
            ->and(json_decode($unknown->body(), true)['error']['code'])->toBe('unauthenticated')
            ->and($known->status)->toBe(401);
    });
});

describe('route tokens', function () {
    it('mints no route token when the application supplied no route signer', function () {
        [$kernel, $fixture] = kernel(support());
        $attribute = 'unset';

        $response = $kernel->handle(
            Request::get(showPath($fixture)),
            static function (LoadedRoute $l, Request $r) use (&$attribute): Response {
                $attribute = $r->attribute(Kernel::ROUTE_TOKEN);

                return Response::make(200, 'page');
            },
        );

        expect($response->status)->toBe(200)
            ->and($response->header('x-route-token'))->toBeNull()
            ->and($attribute)->toBeNull();
    });

    it('logs a re-render that failed, with the digest its log line is matched by', function () {
        $logged = [];
        $routeSigner = new Signer('k', RouteToken::PURPOSE);
        $support = support(
            routeSigner: $routeSigner,
            renderRoute: static function (): string {
                throw new \RuntimeException('the renderer is down');
            },
        );
        [$kernel, $fixture] = kernel($support, options: [
            'log' => static function (\Throwable $e, string $digest) use (&$logged): void {
                $logged[] = [$e, $digest];
            },
        ]);

        $token = (new RouteToken(new TokenClaims(
            route: 'racks.show',
            params: ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            panel: '',
            tenant: '',
            build: 'b1',
            sessionHash: TokenClaims::hashSession($routeSigner, SESSION),
            expiresAt: time() + 3600,
            issuedAt: time(),
        )))->encode($routeSigner);

        $response = $kernel->handle(
            guardedPost('/_action/RackActions.moveUnit'),
            static fn (): Response => Response::make(),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12, '_routes' => [$token]]),
        );

        expect($response->status)->toBe(200, 'the write still stands')
            ->and(json_decode($response->body(), true)['payloads'])->toBe([])
            ->and($logged)->toHaveCount(1)
            ->and($logged[0][0]->getMessage())->toBe('the renderer is down')
            ->and($logged[0][1])->toBe(ExceptionHandler::digest($logged[0][0]));
    });
});
