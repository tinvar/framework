<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\FragmentPanelOrder;

use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\Page;
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
use Tinvar\Tests\Routing\Fixtures\RootLayout;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

/**
 * Which panel a fragment's route middleware runs in.
 *
 * The endpoint used to apply the token's panel only AFTER the route's
 * middleware had run, and only if nothing else had set one -- the other way
 * round from the Revalidator. So the middleware that guards a panel
 * (membership, MFA, a network allowlist) saw no panel at all, and could not
 * refuse the one the fragment then rendered in.
 */

/** A route guard that records the panel it sees, and can refuse or set one. */
final class PanelGuard implements Middleware
{
    /** @var list<string|null> */
    public array $seen = [];

    public function __construct(
        private readonly PanelContext $panels,
        public ?string $refuse = null,
        public ?string $switchTo = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $current = $this->panels->currentOrNull();
        $this->seen[] = $current;

        if ($this->refuse !== null && $current === $this->refuse) {
            throw new HttpException(403, 'Not a member of this panel.', 'forbidden');
        }

        return $this->switchTo === null
            ? $next($request)
            : $this->panels->within($this->switchTo, static fn (): Response => $next($request));
    }
}

#[Page('/guarded', view: 'guarded', name: 'guarded'), UseMiddleware(PanelGuard::class)]
final class GuardedPage {}

/** @return array{0: FragmentEndpoint, 1: Signer, 2: PanelGuard, 3: PanelContext, 4: \ArrayObject<int, string|null>} */
function endpoint(?string $refuse = null, ?string $switchTo = null): array
{
    $panels = new PanelContext();
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, GuardedPage::class]);

    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);
    $guard = new PanelGuard($panels, $refuse, $switchTo);

    /** @var \ArrayObject<int, string|null> $rendered the panel each render ran in */
    $rendered = new \ArrayObject();

    $endpoint = new FragmentEndpoint(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
        orm: $orm,
        signer: $signer,
        builds: new BuildRegistry('build-1'),
        render: static function () use ($panels, $rendered): Response {
            $rendered[] = $panels->currentOrNull();

            return Response::make(200, 'RSC-PAYLOAD');
        },
        sessionId: static fn (Request $r): string => 'session-abc',
        middlewareResolver: static fn (string $class): Middleware => $class === PanelGuard::class ? $guard : new $class(),
        panels: $panels,
    );

    return [$endpoint, $signer, $guard, $panels, $rendered];
}

function request(Signer $signer, string $panel): Request
{
    $token = new FragmentToken(
        component: 'UnitHistory',
        props: [],
        route: 'guarded',
        params: [],
        panel: $panel,
        tenant: '',
        build: 'build-1',
        sessionHash: FragmentToken::hashSession($signer, 'session-abc'),
        expiresAt: time() + 3600,
        issuedAt: time(),
    );

    return new Request('GET', FragmentEndpoint::PREFIX . $token->encode($signer));
}

function status(\Closure $request): int
{
    try {
        return $request()->status;
    } catch (HttpException $e) {
        return $e->status;
    }
}

it('runs the route middleware inside the token panel', function () {
    [$endpoint, $signer, $guard, , $rendered] = endpoint();

    $endpoint->handle(request($signer, 'admin'));

    expect($guard->seen)->toBe(['admin'])
        ->and($rendered->getArrayCopy())->toBe(['admin']);
});

// The point of seeing it: a guard can say no before anything renders.
it('lets the route middleware refuse the token panel', function () {
    [$endpoint, $signer, , , $rendered] = endpoint(refuse: 'admin');

    expect(status(static fn (): Response => $endpoint->handle(request($signer, 'admin'))))->toBe(403)
        ->and($rendered->getArrayCopy())->toBe([]);
});

it('runs a panel-less token with no panel, middleware included', function () {
    [$endpoint, $signer, $guard, , $rendered] = endpoint();

    $endpoint->handle(request($signer, ''));

    expect($guard->seen)->toBe([null])
        ->and($rendered->getArrayCopy())->toBe([null]);
});

// A panel the request sets that is not the token's: the page this token
// came from is not the page this request would render now.
describe('a panel the request sets that disagrees with the token', function () {
    it('is refused when the route middleware sets it', function (string $token, string $set) {
        [$endpoint, $signer, , , $rendered] = endpoint(switchTo: $set);

        expect(status(static fn (): Response => $endpoint->handle(request($signer, $token))))->toBe(404)
            ->and($rendered->getArrayCopy())->toBe([]);
    })->with([
        'another panel' => ['admin', 'customer'],
        'a panel the token did not have' => ['', 'admin'],
    ]);

    it('is refused when the request had set it before the route', function () {
        [$endpoint, $signer, $guard, $panels, $rendered] = endpoint();

        $status = $panels->within('customer', static fn (): int => status(
            static fn (): Response => $endpoint->handle(request($signer, 'admin')),
        ));

        expect($status)->toBe(404)
            ->and($guard->seen)->toBe([], 'refused before the route middleware ran')
            ->and($rendered->getArrayCopy())->toBe([]);
    });

    it('is not a disagreement when the middleware sets the token panel itself', function () {
        [$endpoint, $signer, , , $rendered] = endpoint(switchTo: 'admin');

        expect(status(static fn (): Response => $endpoint->handle(request($signer, 'admin'))))->toBe(200)
            ->and($rendered->getArrayCopy())->toBe(['admin']);
    });
});

it('leaves no panel set once the request is done', function () {
    [$endpoint, $signer, , $panels] = endpoint();

    $endpoint->handle(request($signer, 'admin'));

    expect($panels->isSet())->toBeFalse();
});
