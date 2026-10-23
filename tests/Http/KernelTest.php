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
});
