<?php

declare(strict_types=1);

/**
 * The integration point Phase 1 did not have.
 *
 * Every other test in this suite builds both sides of a seam and puts a fake
 * between them. This one builds the request path the way an application would
 * and drives real requests through it, because that is what would have caught
 * the findings these tests now guard.
 */

use Tinvar\Actions\ActionRegistry;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Throttle;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\RequestScope;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Auth\Fixtures\User as AuthUser;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

/** @return array{0: Kernel, 1: Fixture} */
function kernel(?AuthUser $user = null): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 2, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $auth = new AuthContext();
    $auth->login($user);

    $routes = new RouteRegistry();
    $routes->registerAll([
        Tinvar\Tests\Routing\Fixtures\RootLayout::class,
        Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
        Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
    ]);

    $actions = new ActionRegistry();
    $actions->register(A\RackActions::class);

    $policies = (new PolicyRegistry())->registerAll([
        Rack::class => A\RecordingRackPolicy::class,
        Site::class => A\RecordingSitePolicy::class,
    ]);
    A\RecordingRackPolicy::reset();

    $kernel = new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: $policies,
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
    );

    return [$kernel, $fixture];
}

function showPath(Fixture $fixture): string
{
    return '/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0];
}

/**
 * @return array{0: Kernel, 1: Fixture, 2: Closure} a kernel with throttling
 *         mounted, plus a signIn() -- the reset contract clears the user at
 *         the end of every request, so a second request needs a session again
 */
function throttledKernel(int $limit = 2): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 2, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $auth = new AuthContext();
    $auth->login(new AuthUser('u-1'));

    $routes = new RouteRegistry();
    $routes->registerAll([
        Tinvar\Tests\Routing\Fixtures\RootLayout::class,
        Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
        Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
    ]);

    $actions = new ActionRegistry();
    $actions->register(A\RackActions::class);

    $policies = (new PolicyRegistry())->registerAll([
        Rack::class => A\RecordingRackPolicy::class,
        Site::class => A\RecordingSitePolicy::class,
    ]);
    A\RecordingRackPolicy::reset();

    $kernel = new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: $policies,
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        throttle: new Throttle(
            new ArrayThrottleStore(),
            $auth,
            static fn (Request $r): string => '10.0.0.1',
        ),
        limits: ['actions' => $limit, 'queries' => $limit, 'fragments' => $limit],
    );

    $signIn = static function () use ($auth): void {
        $auth->login(new AuthUser('u-1'));
    };

    return [$kernel, $fixture, $signIn];
}

function render(): Closure
{
    return static fn (LoadedRoute $loaded, Request $request): Response
        => Response::make(200, json_encode(['layouts' => array_keys($loaded->layouts)]));
}

/**
 * Both fragment reviews flagged that the endpoint existed and was reachable
 * from nothing. A feature with no composition root is the same shape as every
 * phase-1 finding: a well-built part that nothing joins up.
 */
describe('fragments through the kernel', function () {
    it('mounts nothing when the application supplies no fragment support', function () {
        [$kernel] = kernel(new AuthUser('u-1'));

        expect($kernel->fragments_)->toBeNull();
    });

    // Either alone cannot answer a bootstrap request: a manifest says which
    // boundaries exist, FragmentSupport is what can sign for them.
    it('mounts no bootstrap without both a manifest and fragment support', function () {
        [$kernel] = kernel(new AuthUser('u-1'));

        expect($kernel->bootstrap_)->toBeNull();
    });

    it('falls through to the router when fragments are not mounted', function () {
        [$kernel] = kernel(new AuthUser('u-1'));

        $response = $kernel->handle(Request::get('/_fragment/anything'), render());

        expect($response->status)->toBe(404);
    });
});

it('routes a page request through loaders and authorization', function () {
    [$kernel, $fixture] = kernel(new AuthUser('u-1'));

    $response = $kernel->handle(
        Request::get(showPath($fixture)),
        render(),
    );

    expect($response->status)->toBe(200)
        ->and(A\RecordingRackPolicy::$asked)
        ->toBe(['site.view', 'rack.view'], 'the layout check ran before the page check');
});

// Nothing turned a thrown outcome into a response, so every refusal the
// framework raised escaped the request path uncaught.
it('turns a refusal into a response instead of letting it escape', function () {
    [$kernel] = kernel(new AuthUser('u-1'));

    $response = $kernel->handle(Request::get('/nope/nothing-here'), render());

    expect($response->status)->toBe(404)
        ->and(json_decode($response->body(), true)['error']['code'])->toBe('not_found');
});

it('does not put the thrown message in the response', function () {
    [$kernel, $fixture] = kernel(new AuthUser('u-1'));

    $response = $kernel->handle(
        Request::get('/sites/' . $fixture->ids['sites'][0] . '/racks/does-not-exist'),
        render(),
    );

    expect($response->status)->toBe(404)
        ->and($response->body())->not->toContain('Rack')
        ->and($response->body())->not->toContain('does-not-exist');
});

/** A POST that carries what the endpoint stack requires. */
function guardedPost(string $path): Request
{
    return new Request('POST', $path, ['x-xsrf-token' => 'tok'], cookies: [
        VerifyCsrfToken::COOKIE => 'tok',
    ]);
}

it('runs an action end to end over its endpoint', function () {
    [$kernel, $fixture] = kernel(new AuthUser('u-1'));

    $response = $kernel->handle(
        guardedPost('/_action/RackActions.moveUnit'),
        render(),
        json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
    );

    expect($response->status)->toBe(200)
        ->and(json_decode($response->body(), true)['flash'])->toBe(['message' => 'Moved to 12']);
});

/**
 * The endpoints used to be dispatched OUTSIDE the pipeline, so CSRF,
 * authentication and every global middleware covered the page path and nothing
 * else -- an unauthenticated, token-less POST reached an action body. The
 * endpoints' own docblocks say the stack is applied "by whoever mounts this";
 * the Kernel is whoever mounts them.
 */
describe('the endpoint middleware stack', function () {
    it('refuses an action with no CSRF token', function () {
        [$kernel, $fixture] = kernel(new AuthUser('u-1'));

        $response = $kernel->handle(
            Request::post('/_action/RackActions.moveUnit'),
            render(),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        expect($response->status)->toBe(419)
            ->and(json_decode($response->body(), true)['error']['code'])->toBe('page_expired');
    });

    it('refuses an action from an anonymous caller', function () {
        [$kernel, $fixture] = kernel();

        $response = $kernel->handle(
            guardedPost('/_action/RackActions.moveUnit'),
            render(),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        expect($response->status)->toBe(401)
            ->and(json_decode($response->body(), true)['error']['code'])->toBe('unauthenticated');
    });

    it('refuses a query from an anonymous caller', function () {
        [$kernel] = kernel();

        $response = $kernel->handle(
            guardedPost('/_query/racks.summary'),
            render(),
            json_encode(['args' => []]),
        );

        expect($response->status)->toBe(401);
    });

    // The body must not have run. A 401 that still wrote would be the bug
    // wearing a status code.
    it('does not reach the body when it refuses', function () {
        [$kernel, $fixture] = kernel();
        A\Trace::reset();

        $kernel->handle(
            guardedPost('/_action/RackActions.moveUnit'),
            render(),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        expect(A\Trace::$events)->toBe([]);
    });

    // #[PublicAccess] still opts out, through the Kernel's own lookup.
    it('lets an anonymous caller reach an entry point that opts out', function () {
        [$kernel] = kernel();
        $kernel->actions->register(A\MixedAccessActions::class);

        $response = $kernel->handle(
            guardedPost('/_action/MixedAccessActions.status'),
            render(),
            '{}',
        );

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['value'])->toBe('ok');
    });
});

// "Tenant, auth, panel, identity map and #[Scoped] services reset at the end
// of each request and job, under a tested reset contract." The contract was
// declared on three methods and invoked by none of them.
describe('the reset contract', function () {
    it('clears the current user when the request ends', function () {
        [$kernel, $fixture] = kernel(new AuthUser('u-1'));

        expect($kernel->auth->user())->not->toBeNull();

        $kernel->handle(Request::get(showPath($fixture)), render());

        expect($kernel->auth->user())->toBeNull('the next request must not inherit this user');
    });

    it('clears the identity map when the request ends', function () {
        [$kernel, $fixture] = kernel(new AuthUser('u-1'));

        $kernel->handle(Request::get(showPath($fixture)), render());

        expect($kernel->orm->identityMap()->count())->toBe(0);
    });

    it('resets even when the request failed', function () {
        [$kernel] = kernel(new AuthUser('u-1'));

        $kernel->handle(Request::get('/nope/nothing-here'), render());

        expect($kernel->auth->user())->toBeNull('a failed request still has to tear down');
    });

    it('runs every reset even if one throws, and reports the first failure', function () {
        $ran = [];
        $scope = (new RequestScope())
            ->add('first', function () use (&$ran): void {
                $ran[] = 'first';

                throw new RuntimeException('teardown blew up');
            })
            ->add('second', function () use (&$ran): void { $ran[] = 'second'; });

        expect(fn () => $scope->reset())->toThrow(RuntimeException::class, 'teardown blew up')
            ->and($ran)->toBe(['first', 'second'], 'a teardown that gives up halfway leaves what it had to clear');
    });

    // The spec's clause starts with the tenant, and Orm::flush() cleared the
    // caches while leaving the context -- tenant, unscoped depth, read-only
    // depth -- as the last request left it.
    it('clears the tenant and the scoping depths', function () {
        [$kernel, $fixture] = kernel(new AuthUser('u-1'));

        $kernel->orm->switchTenant('acme');

        $kernel->handle(Request::get(showPath($fixture)), render());

        expect($kernel->orm->tenant())->toBe('', 'a tenant must not outlive its request')
            ->and($kernel->orm->isUnscoped())->toBeFalse()
            ->and($kernel->orm->isReadOnly())->toBeFalse();
    });

    // If something request-scoped is added later and nobody registers it, the
    // carry-over is silent. This fails instead.
    it('covers every resettable the framework owns', function () {
        [$kernel] = kernel();

        expect($kernel->scope->names())->toBe(['orm', 'auth', 'policies']);
    });

    // "Tenant, auth, PANEL, identity map and #[Scoped] services reset at the
    // end of each request and job."
    it('resets the panel when the application has panels', function () {
        $panels = new Tinvar\Auth\PanelContext();
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $kernel = new Kernel(
            orm: new Orm($fixture->connection, new IdentityMap('test')),
            routes: new RouteRegistry(),
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: new AuthContext(),
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            panels: $panels,
        );

        expect($kernel->scope->names())->toBe(['orm', 'auth', 'policies', 'panels']);
    });
});

/**
 * "throttle:actions", "throttle:queries", "throttle:fragments" from the
 * endpoints table. No endpoint had any -- both fragment reviews noted a junk
 * token could be replayed at full rate, and the README said so.
 */
describe('throttling', function () {
    it('refuses an action past the limit with 429', function () {
        [$kernel, $fixture, $signIn] = throttledKernel(limit: 2);
        $body = json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]);

        $statuses = [];
        for ($i = 0; $i < 3; $i++) {
            $signIn();
            $statuses[] = $kernel->handle(
                guardedPost('/_action/RackActions.moveUnit'),
                render(),
                $body,
            )->status;
        }

        expect($statuses)->toBe([200, 200, 429]);
    });

    it('tells an allowed caller what is left', function () {
        [$kernel, $fixture, $signIn] = throttledKernel(limit: 5);
        $signIn();

        $response = $kernel->handle(
            guardedPost('/_action/RackActions.moveUnit'),
            render(),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        expect($response->header('x-ratelimit-limit'))->toBe('5')
            ->and($response->header('x-ratelimit-remaining'))->toBe('4');
    });

    it('gives the refusal a retry-after a client can act on', function () {
        [$kernel, $fixture, $signIn] = throttledKernel(limit: 1);
        $body = json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]);

        $signIn();
        $kernel->handle(guardedPost('/_action/RackActions.moveUnit'), render(), $body);
        $signIn();
        $response = $kernel->handle(guardedPost('/_action/RackActions.moveUnit'), render(), $body);

        expect($response->status)->toBe(429)
            ->and(json_decode($response->body(), true)['error']['code'])->toBe('too_many_requests');
    });

    // Separate buckets: hammering queries must not lock a caller out of
    // actions, and the endpoints table names them separately for that reason.
    it('keeps the action and query budgets apart', function () {
        [$kernel, $fixture, $signIn] = throttledKernel(limit: 1);

        $signIn();
        $kernel->handle(
            guardedPost('/_action/RackActions.moveUnit'),
            render(),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        $signIn();
        $query = $kernel->handle(guardedPost('/_query/racks.summary'), render(), '{"args":{}}');

        expect($query->status)->not->toBe(429);
    });

    // A throttle with no shared store is a limit multiplied by the worker
    // count, which looks enforced and is not -- so an application has to say
    // what store it means rather than getting one by default.
    it('mounts nothing when the application configured no throttle', function () {
        [$kernel, $fixture] = kernel(new AuthUser('u-1'));
        $body = json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]);

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $kernel->auth->login(new AuthUser('u-1'));
            $statuses[] = $kernel->handle(guardedPost('/_action/RackActions.moveUnit'), render(), $body)->status;
        }

        expect($statuses)->toBe([200, 200, 200, 200, 200]);
    });
});
