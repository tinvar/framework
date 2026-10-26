<?php

declare(strict_types=1);

/**
 * A BuildRegistry read from a store: what an operator records with
 * `tinvar build:revoke` and `build:floor` is in force on the next request,
 * on a worker that was booted before it -- at every place that checks a
 * build: a client's X-Version, a fragment token, a route token.
 */

namespace Tinvar\Tests\Http\PersistedBuildRegistry;

use Tinvar\Actions\Revalidator;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildHistory;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\BuildStore;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\FileBuildStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\CheckVersion;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

const SESSION = 'session-abc';

/** A store over a file in a fresh directory, removed when the test process ends. */
function store(): FileBuildStore
{
    $directory = sys_get_temp_dir() . '/tinvar-registry-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    return new FileBuildStore("{$directory}/builds.json");
}

/**
 * What an operator's command does: another store over the same file, as the
 * CLI is another process.
 *
 * @param \Closure(BuildHistory): BuildHistory $change
 */
function record(FileBuildStore $store, \Closure $change): void
{
    new FileBuildStore($store->path)->update($change);
}

/** b1, b2 and b3 released an hour apart, from $start or three hours ago. */
function released(FileBuildStore $store, ?int $start = null): void
{
    $start ??= time() - 3 * 3600;
    record($store, static fn (BuildHistory $h): BuildHistory => $h
        ->released('b1', $start)->released('b2', $start + 3600)->released('b3', $start + 7200));
}

describe('a registry read from a store', function () {
    it('answers for every recorded build, oldest first, and its own', function () {
        $store = store();
        released($store);

        $builds = BuildRegistry::persisted('b3', $store);

        expect($builds->retained())->toBe(['b1', 'b2', 'b3'])
            ->and($builds->accepts('b2'))->toBeTrue()
            ->and($builds->standing('b3'))->toBe(BuildRegistry::CURRENT);
    });

    it('takes a revocation recorded after it was built, without a restart', function () {
        $store = store();
        released($store);
        $builds = BuildRegistry::persisted('b3', $store);
        expect($builds->accepts('b1'))->toBeTrue();

        record($store, static fn (BuildHistory $h): BuildHistory => $h->revoking('b1'));

        expect($builds->accepts('b1'))->toBeFalse()
            ->and($builds->standing('b1'))->toBe(BuildRegistry::REVOKED)
            ->and($builds->accepts('b2'))->toBeTrue();
    });

    it('takes a floor recorded after it was built', function () {
        $store = store();
        released($store);
        $builds = BuildRegistry::persisted('b3', $store);

        record($store, static fn (BuildHistory $h): BuildHistory => $h->flooredAt('b2'));

        expect($builds->standing('b1'))->toBe(BuildRegistry::BELOW_FLOOR)
            ->and($builds->accepts('b2'))->toBeTrue()
            ->and($builds->accepts('b3'))->toBeTrue();
    });

    // The order is the record. Lexically, "b10" is below "b9".
    it('compares the floor by release order, never by the ids', function () {
        $store = store();
        record($store, static fn (BuildHistory $h): BuildHistory => $h
            ->released('b9', 1)->released('b10', 2)->flooredAt('b10'));

        $builds = BuildRegistry::persisted('b10', $store);

        expect($builds->accepts('b9'))->toBeFalse()
            ->and($builds->accepts('b10'))->toBeTrue();
    });

    it('stops retaining a superseded build a day after it was superseded', function () {
        $store = store();
        released($store, start: 1_000_000);
        $now = 1_000_000 + 3600 + BuildRegistry::RETENTION - 1;
        $builds = BuildRegistry::persisted('b3', $store, clock: static function () use (&$now): int {
            return $now;
        });

        // b1 was superseded by b2 at +1h: a day later it has expired; b2,
        // superseded at +2h, has an hour left.
        expect($builds->accepts('b1'))->toBeTrue();
        $now++;
        expect($builds->standing('b1'))->toBe(BuildRegistry::EXPIRED)
            ->and($builds->accepts('b2'))->toBeTrue();
    });

    it('never expires the build it runs, however long ago it was released', function () {
        $store = store();
        released($store, start: 1);

        expect(BuildRegistry::persisted('b3', $store, clock: static fn (): int => PHP_INT_MAX)->accepts('b3'))->toBeTrue();
    });

    // A rolling deploy: b4 is released, and a server not yet replaced still
    // runs b3. It answers for its own build, and for b4's clients.
    it('answers for its own build while a newer one is recorded', function () {
        $store = store();
        released($store);
        record($store, static fn (BuildHistory $h): BuildHistory => $h->released('b4', 2_000_000));

        $builds = BuildRegistry::persisted('b3', $store, clock: static fn (): int => 2_000_001);

        expect($builds->standing('b3'))->toBe(BuildRegistry::CURRENT)
            ->and($builds->standing('b4'))->toBe(BuildRegistry::RETAINED);
    });

    it('answers for its own build alone when nothing is recorded', function () {
        $builds = BuildRegistry::persisted('dev', store());

        expect($builds->retained())->toBe(['dev'])
            ->and($builds->accepts('dev'))->toBeTrue()
            ->and($builds->standing('b1'))->toBe(BuildRegistry::UNKNOWN);
    });

    // Every older client reloads -- the most a revocation could have asked
    // -- and the site stays up rather than failing every navigation.
    it('answers for its own build alone while the store cannot be read, and recovers', function () {
        $store = store();
        released($store);
        $builds = BuildRegistry::persisted('b3', $store);
        expect($builds->accepts('b2'))->toBeTrue();

        file_put_contents($store->path . '.tmp', '{broken');
        rename($store->path . '.tmp', $store->path);

        expect($builds->accepts('b2'))->toBeFalse()
            ->and($builds->accepts('b3'))->toBeTrue();

        unlink($store->path);
        released($store);

        expect($builds->accepts('b2'))->toBeTrue();
    });

    it('asks the store on every check, so the store decides what that costs', function () {
        $reads = 0;
        $store = new class ($reads) implements BuildStore {
            private BuildHistory $history;

            public function __construct(private int &$reads)
            {
                $this->history = new BuildHistory()->released('b1', 1)->released('b2', 2);
            }

            public function read(): BuildHistory
            {
                $this->reads++;

                return $this->history;
            }

            public function update(\Closure $change): BuildHistory
            {
                return $this->history = $change($this->history);
            }
        };
        $builds = BuildRegistry::persisted('b2', $store);

        $builds->accepts('b1');
        $builds->accepts('b1');

        expect($reads)->toBe(2);
    });

    // In code it would last until the store next changed, on one worker.
    it('refuses a change made in code', function (\Closure $change) {
        $builds = BuildRegistry::persisted('b2', store());

        expect(fn () => $change($builds))->toThrow(\LogicException::class, 'tinvar build:');
    })->with([
        'revoke' => [static fn (BuildRegistry $b): mixed => $b->revoke('b1')],
        'floor' => [static fn (BuildRegistry $b): mixed => $b->floor('b1')],
        'retain' => [static fn (BuildRegistry $b): mixed => $b->retain('b1')],
    ]);
});

/** A revocation recorded while the worker runs, reaching each place a build is checked. */
describe('refused everywhere a build is checked', function () {
    it('answers a client navigation on a revoked build with 409 and X-Location', function () {
        $store = store();
        released($store);
        $versions = new CheckVersion(BuildRegistry::persisted('b3', $store));
        $next = static fn (Request $r): Response => Response::make(200, 'page');
        $navigation = Request::get('/racks/r-1', ['RSC' => '1', 'X-Version' => 'b1']);

        expect($versions->handle($navigation, $next)->status)->toBe(200);

        record($store, static fn (BuildHistory $h): BuildHistory => $h->revoking('b1'));
        $refused = $versions->handle($navigation, $next);

        expect($refused->status)->toBe(409)
            ->and($refused->header('x-location'))->toBe('/racks/r-1');
    });

    it('refuses a fragment token from a build below the floor', function () {
        $store = store();
        released($store);
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);
        $signer = new Signer('test-app-key', FragmentToken::PURPOSE);

        $endpoint = new FragmentEndpoint(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            orm: $orm,
            signer: $signer,
            builds: BuildRegistry::persisted('b3', $store),
            render: static fn (): Response => Response::make(200, 'RSC', ['content-type' => 'text/x-component']),
            sessionId: static fn (Request $r): string => SESSION,
            middlewareResolver: static fn (string $class): Middleware => new $class(),
            panels: new PanelContext(),
        );
        $request = new Request('GET', FragmentEndpoint::PREFIX . new FragmentToken(
            component: 'UnitHistory',
            props: [],
            route: 'racks.show',
            params: ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            panel: '',
            tenant: '',
            build: 'b2',
            sessionHash: FragmentToken::hashSession($signer, SESSION),
            expiresAt: time() + 3600,
            issuedAt: time(),
        )->encode($signer));

        expect($endpoint->handle($request)->status)->toBe(200);

        record($store, static fn (BuildHistory $h): BuildHistory => $h->flooredAt('b3'));

        expect(fn () => $endpoint->handle($request))->toThrow(HttpException::class, 'Fragment unavailable');
    });

    // A route token is what an action's caller sends back to have its page
    // re-rendered; one from a revoked build gets no payload -- the client
    // reloads instead -- and the action's write still stands.
    it('re-renders nothing for a route token from a revoked build', function () {
        $store = store();
        released($store);
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $routes = new RouteRegistry();
        $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);
        $signer = new Signer('test-app-key', RouteToken::PURPOSE);

        $revalidator = new Revalidator(
            routes: $routes,
            loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()),
            signer: $signer,
            orm: $orm,
            render: static fn (LoadedRoute $loaded, Request $r): string => 'payload',
            builds: BuildRegistry::persisted('b3', $store),
            middlewareResolver: static fn (string $class): Middleware => new $class(),
            panels: new PanelContext(),
        );
        $token = new RouteToken(new TokenClaims(
            route: 'racks.show',
            params: ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            panel: '',
            tenant: '',
            build: 'b2',
            sessionHash: TokenClaims::hashSession($signer, SESSION),
            expiresAt: time() + 3600,
            issuedAt: time(),
        ))->encode($signer);
        $rerender = static fn (): array => $revalidator->rerender(
            ['racks.show'],
            [$token],
            Request::post('/_action/x'),
            static fn (Request $r): string => SESSION,
        );

        expect($rerender())->toHaveCount(1);

        record($store, static fn (BuildHistory $h): BuildHistory => $h->revoking('b2'));

        expect($rerender())->toBe([]);
    });
});
