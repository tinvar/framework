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

/** @return array{0: Kernel, 1: Fixture} a kernel with bootstrap mounted */
function bootstrapKernel(?AuthUser $user = null): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $signer = new Tinvar\Support\Signer('k', Tinvar\Render\FragmentToken::PURPOSE);

    $routes = new RouteRegistry();
    $routes->registerAll([
        Tinvar\Tests\Routing\Fixtures\RootLayout::class,
        Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
        Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
    ]);

    $auth = new AuthContext();
    $auth->login($user);

    $kernel = new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        fragments: new Tinvar\Http\FragmentSupport(
            signer: $signer,
            builds: new Tinvar\Http\BuildRegistry('b1'),
            render: static fn (): mixed => null,
            sessionId: static fn (Request $r): string => 'session-abc',
        ),
        manifest: (new Tinvar\Render\FragmentManifest())->declare(
            'racks.show',
            'history',
            'UnitHistory',
            [],
            ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
        ),
    );

    return [$kernel, $fixture];
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

    /**
     * ADR 0008 said "a test asserts the list covers what exists, so adding
     * something request-scoped without registering it fails rather than
     * leaking quietly". The test it described asserted a hardcoded list
     * against itself: it failed when someone REGISTERED something new, and
     * could not fail in the case it was written for. Phase 2 then added
     * QueryRunner::$tagsRead and nothing noticed.
     *
     * This one reflects over the framework's own classes and fails when one
     * grows a reset-shaped method that nothing registers.
     */
    it('registers every resettable class the framework declares', function () {
        [$kernel] = kernel(new AuthUser('u-1'));

        $src = __DIR__ . '/../../src';
        $resettable = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'Tinvar\\' . str_replace(
                ['/', '.php'],
                ['\\', ''],
                substr($file->getPathname(), \strlen($src) + 1),
            );

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            foreach (['reset', 'forgetTagsRead'] as $method) {
                if ($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic()) {
                    $resettable[] = $reflection->getShortName() . '::' . $method;
                }
            }
        }

        // Known and deliberately NOT in the request scope, with the reason.
        $exempt = [
            // Not request state: a per-worker counter store, swept by size.
            'ArrayThrottleStore::reset',
            // The scope itself.
            'RequestScope::reset',
            // Reset by the render channel per render, which is narrower than
            // per request -- a render must not inherit the previous one's.
            'BatchScheduler::reset',
        ];

        $unregistered = array_values(array_diff($resettable, $exempt));

        // Every remaining resettable must be covered by one of the scope's
        // entries. The mapping is deliberately explicit: a new one fails
        // here until someone decides where it belongs.
        $covered = [
            'AuthContext::reset' => 'auth',
            'PolicyRegistry::reset' => 'policies',
            'PanelContext::reset' => 'panels',
            'QueryRunner::forgetTagsRead' => 'queries',
            'Orm::reset' => 'orm',
        ];

        $missing = array_values(array_diff($unregistered, array_keys($covered)));

        expect($missing)->toBe([], 'these have a reset method and no place in RequestScope');
    });

    // If something request-scoped is added later and nobody registers it, the
    // carry-over is silent. This fails instead.
    it('covers every resettable the framework owns', function () {
        [$kernel] = kernel();

        expect($kernel->scope->names())->toBe(['orm', 'auth', 'policies', 'queries']);
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

        expect($kernel->scope->names())->toBe(['orm', 'auth', 'policies', 'panels', 'queries']);
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

/**
 * The bootstrap endpoint had to be mounted to be reachable, and the first
 * attempt at mounting it silently did not apply -- the Kernel had no
 * bootstrap_ property at all, and the suite reported a warning rather than a
 * failure. These assert the wiring, not just the construction.
 */
describe('bootstrap through the kernel', function () {
    it('mounts one when an application has both a manifest and fragment support', function () {
        [$kernel, $fixture] = bootstrapKernel(new AuthUser('u-1'));

        expect($kernel->bootstrap_)->not->toBeNull();

        $response = $kernel->handle(
            guardedPost(Tinvar\Http\Endpoints\BootstrapEndpoint::PATH),
            render(),
            json_encode([
                'route' => 'racks.show',
                'params' => ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            ]),
        );

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['tokens'])->toHaveKey('history');
    });

    /**
     * The endpoint exists to hand a visitor the tokens for a STATICALLY
     * BUILT page, and such a visitor may well not be signed in. Mounting it
     * behind the action stack 401'd exactly the traffic it was built for.
     */
    it('serves an anonymous visitor, which is who static pages are for', function () {
        [$kernel, $fixture] = bootstrapKernel();

        $response = $kernel->handle(
            guardedPost(Tinvar\Http\Endpoints\BootstrapEndpoint::PATH),
            render(),
            json_encode([
                'route' => 'racks.show',
                'params' => ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            ]),
        );

        expect($response->status)->toBe(200);
    });

    // The carve-out is narrow: it drops the auth check and nothing else.
    it('still requires a CSRF token', function () {
        [$kernel, $fixture] = bootstrapKernel();

        $response = $kernel->handle(
            Request::post(Tinvar\Http\Endpoints\BootstrapEndpoint::PATH),
            render(),
            json_encode([
                'route' => 'racks.show',
                'params' => ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            ]),
        );

        expect($response->status)->toBe(419);
    });
});

/**
 * Revalidation through the composition root.
 *
 * Mounted features in this codebase have three times been declared done
 * while being unreachable from the Kernel -- the mint closure, the bootstrap
 * endpoint, the tenant check. This drives a real action request and asserts
 * a payload comes back, rather than asserting a property is non-null.
 */
describe('post-action revalidation through the kernel', function () {
    it('re-renders a mounted route the action invalidated', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        // Two signers, as an application must supply: one per purpose.
        $fragmentSigner = new Tinvar\Support\Signer('k', Tinvar\Render\FragmentToken::PURPOSE);
        $signer = new Tinvar\Support\Signer('k', Tinvar\Render\RouteToken::PURPOSE);

        $routes = new RouteRegistry();
        $routes->registerAll([
            Tinvar\Tests\Routing\Fixtures\RootLayout::class,
            Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
            Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
        ]);

        $actions = new ActionRegistry();
        $actions->register(A\RackActions::class);

        $auth = new AuthContext();
        $auth->login(new AuthUser('u-1'));

        $kernel = new Kernel(
            orm: $orm,
            routes: $routes,
            queries: new QueryRegistry(),
            actions: $actions,
            auth: $auth,
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            gate: new Tinvar\Auth\AllowAllGate(),
            fragments: new Tinvar\Http\FragmentSupport(
                signer: $fragmentSigner,
                builds: new Tinvar\Http\BuildRegistry('b1'),
                render: static fn (): mixed => null,
                sessionId: static fn (Request $r): string => 'session-abc',
                renderRoute: static fn (Tinvar\Routing\LoadedRoute $l, Request $r): string
                    => 'fresh:' . $l->chain->page->name,
                routeSigner: $signer,
            ),
        );

        $token = (new Tinvar\Render\RouteToken(new Tinvar\Render\TokenClaims(
            route: 'racks.show',
            params: ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            panel: '',
            tenant: '',
            build: 'b1',
            sessionHash: Tinvar\Render\TokenClaims::hashSession($signer, 'session-abc'),
            expiresAt: time() + 3600,
            issuedAt: time(),
        )))->encode($signer);

        $response = $kernel->handle(
            guardedPost('/_action/RackActions.moveUnit'),
            render(),
            json_encode([
                'rack' => $fixture->ids['racks'][0],
                'position' => 12,
                '_routes' => [$token],
            ]),
        );

        $body = json_decode($response->body(), true);

        expect($response->status)->toBe(200)
            ->and(array_values($body['payloads']))->toBe(['fresh:racks.show']);
    });

    /**
     * The other half of the loop: a page render hands its client the token
     * it will send back. A token nothing issues is the shape three features
     * in this codebase shipped in.
     */
    it('hands a page its route token', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        // Two signers, as an application must supply: one per purpose.
        $fragmentSigner = new Tinvar\Support\Signer('k', Tinvar\Render\FragmentToken::PURPOSE);
        $signer = new Tinvar\Support\Signer('k', Tinvar\Render\RouteToken::PURPOSE);

        $routes = new RouteRegistry();
        $routes->registerAll([
            Tinvar\Tests\Routing\Fixtures\RootLayout::class,
            Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
            Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
        ]);

        $auth = new AuthContext();
        $auth->login(new AuthUser('u-1'));

        $kernel = new Kernel(
            orm: $orm,
            routes: $routes,
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: $auth,
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            gate: new Tinvar\Auth\AllowAllGate(),
            fragments: new Tinvar\Http\FragmentSupport(
                signer: $fragmentSigner,
                builds: new Tinvar\Http\BuildRegistry('b1'),
                render: static fn (): mixed => null,
                sessionId: static fn (Request $r): string => 'session-abc',
                routeSigner: $signer,
            ),
        );

        $response = $kernel->handle(
            Request::get('/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0]),
            render(),
        );

        $token = $response->header('x-route-token');

        expect($token)->not->toBeNull('a page must hand its client a token to send back')
            ->and(Tinvar\Render\RouteToken::decode($token, $signer)->claims->route)
            ->toBe('racks.show');
    });

    // Without fragment support there is no signer and no session seam, so
    // revalidate() still names routes and simply carries no payloads.
    it('still succeeds with no payloads when revalidation is not configured', function () {
        [$kernel, $fixture] = kernel(new AuthUser('u-1'));

        $response = $kernel->handle(
            guardedPost('/_action/RackActions.moveUnit'),
            render(),
            json_encode(['rack' => $fixture->ids['racks'][0], 'position' => 12]),
        );

        $body = json_decode($response->body(), true);

        expect($response->status)->toBe(200)
            ->and($body['revalidated'])->toBe(['racks.show'])
            ->and($body['payloads'])->toBe([]);
    });
});

/**
 * Transport details the reviews caught: the token list is not user input,
 * and a response carrying a per-visitor token is not cacheable.
 */
describe('revalidation transport', function () {
    it('keeps the token list out of the action input', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new ActionRegistry();
        $registry->register(A\RackActions::class);

        // An action that reports what input it was given.
        $endpoint = new Tinvar\Http\Endpoints\ActionEndpoint(
            new Tinvar\Actions\ActionRunner(
                $registry,
                $orm,
                new Tinvar\Auth\AllowAllGate(),
                new Tinvar\Validation\Validator($orm),
            ),
        );

        // With no revalidator the list is still stripped, so it can never
        // reach a form request as a field.
        $response = $endpoint->handle(
            Request::post('/_action/RackActions.moveUnit'),
            json_encode([
                'rack' => $fixture->ids['racks'][0],
                'position' => 12,
                '_routes' => ['v1.whatever.mac'],
            ]),
        );

        expect($response->status)->toBe(200);
    });

    it('does not let a page token be cached across visitors', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $fragmentSigner = new Tinvar\Support\Signer('k', Tinvar\Render\FragmentToken::PURPOSE);
        $routeSigner = new Tinvar\Support\Signer('k', Tinvar\Render\RouteToken::PURPOSE);

        $routes = new RouteRegistry();
        $routes->registerAll([
            Tinvar\Tests\Routing\Fixtures\RootLayout::class,
            Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
            Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
        ]);

        $auth = new AuthContext();
        $auth->login(new AuthUser('u-1'));

        $kernel = new Kernel(
            orm: $orm,
            routes: $routes,
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: $auth,
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            gate: new Tinvar\Auth\AllowAllGate(),
            fragments: new Tinvar\Http\FragmentSupport(
                signer: $fragmentSigner,
                builds: new Tinvar\Http\BuildRegistry('b1'),
                render: static fn (): mixed => null,
                sessionId: static fn (Request $r): string => 'session-abc',
                routeSigner: $routeSigner,
            ),
        );

        $response = $kernel->handle(
            Request::get('/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0]),
            render(),
        );

        expect($response->header('x-route-token'))->not->toBeNull()
            ->and($response->header('cache-control'))->toBe('private, no-store')
            ->and($response->header('vary'))->toContain('Cookie');
    });
});

/**
 * Through the composition root, by a real request -- the standard every
 * mounted feature has had to meet after three were declared done while
 * unreachable from here.
 */
describe('version skew through the kernel', function () {
    function versionedKernel(): array
    {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $routes = new RouteRegistry();
        $routes->registerAll([
            Tinvar\Tests\Routing\Fixtures\RootLayout::class,
            Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
            Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
        ]);

        $auth = new AuthContext();
        $auth->login(new AuthUser('u-1'));

        $kernel = new Kernel(
            orm: new Orm($fixture->connection, new IdentityMap('test')),
            routes: $routes,
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: $auth,
            policies: new PolicyRegistry(),
            resolver: static fn (string $class): object => new $class(),
            allowedQueries: static fn (Request $r): array => [],
            gate: new Tinvar\Auth\AllowAllGate(),
            builds: (new Tinvar\Http\BuildRegistry('b2', ['b1']))->revoke('b1'),
        );

        return [$kernel, '/sites/' . $fixture->ids['sites'][0] . '/racks/' . $fixture->ids['racks'][0]];
    }

    it('tells a client on a revoked build to reload', function () {
        [$kernel, $path] = versionedKernel();

        $response = $kernel->handle(Request::get($path, ['RSC' => '1', 'X-Version' => 'b1']), render());

        expect($response->status)->toBe(409)
            ->and($response->header('x-location'))->toBe($path);
    });

    it('serves a client on the current build', function () {
        [$kernel, $path] = versionedKernel();

        $response = $kernel->handle(Request::get($path, ['RSC' => '1', 'X-Version' => 'b2']), render());

        expect($response->status)->toBe(200);
    });

    // A redirect from the page reached the router as an opaque response it
    // could only answer with a full load of the URL it had asked for.
    it('hands a client navigation a redirect it can follow', function () {
        [$kernel, $path] = versionedKernel();

        $response = $kernel->handle(
            Request::get($path, ['RSC' => '1', 'X-Version' => 'b2']),
            static fn (): Response => Response::redirect('/login'),
        );

        expect($response->status)->toBe(409)
            ->and($response->header('x-redirect'))->toBe('/login');
    });

    it('serves a document load whatever its version', function () {
        [$kernel, $path] = versionedKernel();

        expect($kernel->handle(Request::get($path), render())->status)->toBe(200);
    });
});
