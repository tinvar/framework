<?php

declare(strict_types=1);

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\DenyGate;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Render\FragmentToken;
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

const FRAGMENT_SESSION = 'session-abc';

/** Collects what the render closure was asked to render. */
final class RenderLog
{
    /** @var list<array{component: string, models: list<string>}> */
    public array $calls = [];
}

/** @return array{0: FragmentEndpoint, 1: Fixture, 2: Signer, 3: BuildRegistry, 4: RenderLog, 5: PanelContext, 6: Orm} */
function fragmentEndpoint(?object $gate = null, array $globalMiddleware = []): array
{
    $panels = new PanelContext();
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class]);

    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);
    $builds = new BuildRegistry('build-2', ['build-1']);
    $log = new RenderLog();

    $endpoint = new FragmentEndpoint(
        routes: $routes,
        loaders: new LoaderRunner($orm, static fn (string $c): object => new $c(), $gate ?? new AllowAllGate()),
        orm: $orm,
        signer: $signer,
        builds: $builds,
        render: static function (FragmentToken $token, Request $request, array $models) use ($log, $panels): Response {
            $log->calls[] = [
                'component' => $token->component,
                'models' => array_keys($models),
                'panel' => $panels->currentOrNull(),
            ];

            return Response::make(200, 'RSC-PAYLOAD', ['content-type' => 'text/x-component']);
        },
        sessionId: static fn (Request $r): string => $r->header('x-session') ?? FRAGMENT_SESSION,
        middlewareResolver: static fn (string $class): Middleware => new $class(),
        globalMiddleware: $globalMiddleware,
        panels: $panels,
    );

    return [$endpoint, $fixture, $signer, $builds, $log, $panels, $orm];
}

function fragmentToken(Signer $signer, Fixture $fixture, array $overrides = []): FragmentToken
{
    return new FragmentToken(
        component: $overrides['component'] ?? 'UnitHistory',
        props: $overrides['props'] ?? ['unit' => 'u-1'],
        route: $overrides['route'] ?? 'racks.show',
        params: $overrides['params'] ?? [
            'site' => $fixture->ids['sites'][0],
            'rack' => $fixture->ids['racks'][0],
        ],
        panel: $overrides['panel'] ?? '',
        tenant: $overrides['tenant'] ?? '',
        build: $overrides['build'] ?? 'build-2',
        sessionHash: $overrides['sessionHash'] ?? FragmentToken::hashSession($signer, FRAGMENT_SESSION),
        expiresAt: $overrides['expiresAt'] ?? time() + 3600,
        issuedAt: $overrides['issuedAt'] ?? time(),
    );
}

function fragmentRequest(Signer $signer, FragmentToken $token, array $headers = []): Request
{
    return new Request('GET', FragmentEndpoint::PREFIX . $token->encode($signer), $headers);
}

describe('the fragment endpoint', function () {
    it('renders the subtree a valid token names', function () {
        [$endpoint, $fixture, $signer, , $log] = fragmentEndpoint();

        $response = $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture)));

        expect($response->status)->toBe(200)
            ->and($response->body())->toBe('RSC-PAYLOAD')
            ->and($log->calls[0]['component'])->toBe('UnitHistory');
    });

    // "sent private, no-store with a refreshed token"
    it('returns a refreshed token and does not let it be cached', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();
        $token = fragmentToken($signer, $fixture, ['expiresAt' => time() + 5]);

        $response = $endpoint->handle(fragmentRequest($signer, $token));
        $refreshed = FragmentToken::decode($response->header('x-fragment-token'), $signer);

        expect($response->header('cache-control'))->toBe('private, no-store')
            ->and($refreshed->expiresAt)->toBeGreaterThan($token->expiresAt)
            ->and($refreshed->component)->toBe($token->component)
            ->and($response->header('vary'))->toContain('RSC');
    });

    it('refuses anything but GET', function (string $method) {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();
        $request = new Request($method, FragmentEndpoint::PREFIX . fragmentToken($signer, $fixture)->encode($signer));

        expect(fn () => $endpoint->handle($request))->toThrow(HttpException::class, 'GET only');
    })->with(['POST', 'PUT', 'DELETE']);
});

/**
 * "PHP verifies the token, runs the originating route's full middleware,
 * layout and page #[Authorize] chain". The token says WHICH fragment, never
 * WHETHER -- it is readable in page source, so anyone holding one already had
 * the page it came from.
 */
describe('a token is not a capability', function () {
    it('runs the originating route authorization chain', function () {
        [$endpoint, $fixture, $signer, , $log] = fragmentEndpoint(new DenyGate());

        expect(fn () => $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture))))
            ->toThrow(ForbiddenException::class)
            ->and($log->calls)->toBe([], 'and the subtree never rendered');
    });

    it('binds the route models before authorizing', function () {
        [$endpoint, $fixture, $signer, , $log] = fragmentEndpoint();

        $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture)));

        expect($log->calls[0]['models'])->toContain('rack')
            ->and($log->calls[0]['models'])->toContain('site');
    });

    // A model id in the token that does not resolve must 404, exactly as it
    // would on the page, rather than reaching a policy as a phantom subject.
    it('404s when a token parameter does not resolve', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();
        $token = fragmentToken($signer, $fixture, [
            'params' => ['site' => $fixture->ids['sites'][0], 'rack' => 'no-such-rack'],
        ]);

        try {
            $endpoint->handle(fragmentRequest($signer, $token));
            expect()->fail('an unresolvable parameter was accepted');
        } catch (HttpException $e) {
            expect($e->status)->toBe(404);
        }
    });

    /**
     * The chain is resolved by NAME, never by rebuilding a URL and matching
     * it back -- the round trip is not identity. With a static /sites/new
     * registered, a token for sites.show with site="new" re-matched onto
     * sites.new and ran ITS middleware and #[Authorize] chain, which in that
     * shape is an empty one.
     */
    it('runs the named route chain, not whatever now owns that URL', function () {
        [, $fixture, $signer] = fragmentEndpoint();

        $routes = new RouteRegistry();
        $routes->registerAll([
            Tinvar\Tests\Routing\Fixtures\RootLayout::class,
            Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
            Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
        ]);

        $chain = $routes->chainFor('racks.show', [
            'site' => $fixture->ids['sites'][0],
            'rack' => $fixture->ids['racks'][0],
        ]);

        expect($chain->page->name)->toBe('racks.show')
            ->and($chain->checks())->not->toBe([], 'the originating chain carries its own checks');
    });

    // Asserted as BOTH marks in order. It used to assert only that Trace was
    // non-empty, which either stack alone satisfied -- removing the route
    // middleware or removing the global stack each left it passing.
    it('runs the global stack and then the route stack', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint(
            globalMiddleware: [Tinvar\Tests\Routing\Fixtures\OuterMiddleware::class],
        );
        Tinvar\Tests\Routing\Fixtures\Trace::reset();

        $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture)));

        expect(Tinvar\Tests\Routing\Fixtures\Trace::$events)
            ->toBe(['middleware:outer', 'middleware:inner']);
    });

    /**
     * The global stack wraps the REFUSAL too.
     *
     * Verification used to run before the pipeline existed, so a junk token
     * skipped throttling, security headers, logging and tenant resolution --
     * free unlimited probing of exactly the path an attacker enumerates.
     */
    it('runs the global stack even for a token it refuses', function () {
        [$endpoint] = fragmentEndpoint(
            globalMiddleware: [Tinvar\Tests\Routing\Fixtures\OuterMiddleware::class],
        );
        Tinvar\Tests\Routing\Fixtures\Trace::reset();

        try {
            $endpoint->handle(new Request('GET', FragmentEndpoint::PREFIX . 'garbage'));
        } catch (HttpException) {
            // the refusal is the point; so is what ran before it
        }

        expect(Tinvar\Tests\Routing\Fixtures\Trace::$events)->toBe(['middleware:outer']);
    });
});

describe('tokens this server will not act on', function () {
    // Every rejection is the same rejection: a caller able to tell these
    // apart could use the difference to probe.
    it('refuses a token from another session', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();

        expect(fn () => $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture),
            ['x-session' => 'someone-else'],
        )))->toThrow(HttpException::class, 'Fragment unavailable');
    });

    it('refuses a token from a revoked build', function () {
        [$endpoint, $fixture, $signer, $builds] = fragmentEndpoint();
        $builds->revoke('build-1');

        expect(fn () => $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture, ['build' => 'build-1']),
        )))->toThrow(HttpException::class, 'Fragment unavailable');
    });

    it('accepts a retained older build, which is what skew is for', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();

        $response = $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture, ['build' => 'build-1']),
        ));

        expect($response->status)->toBe(200);
    });

    it('refuses a build it has never heard of', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();

        expect(fn () => $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture, ['build' => 'build-99']),
        )))->toThrow(HttpException::class, 'Fragment unavailable');
    });

    it('refuses an expired token', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();

        expect(fn () => $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture, ['expiresAt' => time() - 1]),
        )))->toThrow(HttpException::class, 'Fragment unavailable');
    });

    it('refuses a forged or malformed token', function (string $raw) {
        [$endpoint] = fragmentEndpoint();

        expect(fn () => $endpoint->handle(new Request('GET', FragmentEndpoint::PREFIX . $raw)))
            ->toThrow(HttpException::class, 'Fragment unavailable');
    })->with([
        'empty' => [''],
        'nonsense' => ['nonsense'],
        'unsigned' => ['v1.eyJjIjoiWCJ9.'],
    ]);

    // Traversal never reaches this endpoint: Request refuses an encoded path
    // separator when it is built, because it decodes per segment before
    // checking. Asserted here so the fragment path is known to be covered by
    // it, rather than assumed to be.
    it('never sees a traversal, because Request refuses one first', function () {
        expect(fn () => new Request('GET', FragmentEndpoint::PREFIX . '..%2F..%2Fetc%2Fpasswd'))
            ->toThrow(NotFoundException::class, 'single path segment');
    });

    // Built from a VALID, correctly signed token padded past the cap. The
    // previous version was 'v1.' + 4096 'A's, which failed signature
    // verification long before the size check mattered -- replacing the cap
    // with `if (false)` left the suite green.
    it('refuses a token over the size cap', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();
        $valid = fragmentToken($signer, $fixture, [
            'props' => ['pad' => str_repeat('p', FragmentEndpoint::MAX_TOKEN_BYTES)],
        ])->encode($signer);

        expect(\strlen($valid))->toBeGreaterThan(FragmentEndpoint::MAX_TOKEN_BYTES)
            ->and(fn () => $endpoint->handle(new Request('GET', FragmentEndpoint::PREFIX . $valid)))
            ->toThrow(HttpException::class, 'Fragment unavailable');
    });

    // A param that is not a scalar used to become the literal string "Array"
    // with a PHP warning, which any handler promoting warnings turns into a
    // 500 -- escaping the one-status rule this feature is built around.
    it('refuses a token whose params are not scalars', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();
        $token = fragmentToken($signer, $fixture);

        [$version, $payload, ] = explode('.', $token->encode($signer));
        $json = json_decode(Signer::decode($payload), true);
        $json['a'] = ['rack' => ['nested' => 1]];
        $forged = Signer::encode(json_encode($json));
        $raw = $version . '.' . $forged . '.' . Signer::encode($signer->sign($version . '.' . $forged));

        expect(fn () => $endpoint->handle(new Request('GET', FragmentEndpoint::PREFIX . $raw)))
            ->toThrow(HttpException::class, 'Fragment unavailable');
    });

    // hashSession('') is one constant, so every visitor without a session
    // would share one binding and their tokens would be interchangeable.
    it('refuses when there is no session to bind to', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();

        expect(fn () => $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture),
            ['x-session' => ''],
        )))->toThrow(HttpException::class, 'Fragment unavailable');
    });

    // 404, never 403: a token this server will not act on is, as far as the
    // caller may learn, a fragment that does not exist.
    it('answers 404, which does not confirm the fragment exists', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();

        try {
            $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture), ['x-session' => 'other']));
            expect()->fail('a foreign token was accepted');
        } catch (HttpException $e) {
            expect($e->status)->toBe(404)
                ->and($e->code_)->toBe('fragment_unavailable');
        }
    });
});

describe('the build registry', function () {
    // "X-Version is checked against an in-memory set of retained build IDs,
    // NEVER used as a path."
    it('refuses anything shaped like a path', function (string $bad) {
        expect((new BuildRegistry('build-1'))->accepts($bad))->toBeFalse();
    })->with(['../etc', 'a/b', 'a\\b', '..', 'a.b', '', 'build 1', "build\0"]);

    it('refuses a build id shaped like a path at construction', function () {
        expect(fn () => new BuildRegistry('../etc'))
            ->toThrow(InvalidArgumentException::class, 'path separators');
    });

    it('accepts the current build and retained ones', function () {
        $builds = new BuildRegistry('b3', ['b1', 'b2']);

        expect($builds->accepts('b3'))->toBeTrue()
            ->and($builds->accepts('b1'))->toBeTrue()
            ->and($builds->accepts('b4'))->toBeFalse();
    });

    // Revocation outranks retention: that is the point of it.
    it('refuses a revoked build even while retained', function () {
        $builds = (new BuildRegistry('b3', ['b1']))->revoke('b1');

        expect($builds->accepts('b1'))->toBeFalse();
    });

    // The floor is a security boundary, not tidiness: below it a fix landed.
    it('refuses anything below the floor', function () {
        $builds = (new BuildRegistry('b3', ['b1', 'b2']))->floor('b2');

        expect($builds->accepts('b1'))->toBeFalse()
            ->and($builds->accepts('b2'))->toBeTrue()
            ->and($builds->accepts('b3'))->toBeTrue();
    });

    /**
     * Order is RECORDED, never inferred from the string.
     *
     * This compared with strcmp, which puts 'build-10' below 'build-9'. With a
     * floor of 'build-9' that admitted the newer build and refused the older
     * one -- backwards, for a control whose whole job is keeping clients off
     * pre-fix code.
     */
    it('orders by position, not lexically', function () {
        $builds = (new BuildRegistry('build-10', ['build-8', 'build-9']))->floor('build-9');

        expect($builds->accepts('build-8'))->toBeFalse('older than the floor')
            ->and($builds->accepts('build-9'))->toBeTrue('the floor itself')
            ->and($builds->accepts('build-10'))->toBeTrue('newer than the floor, though it sorts below it');
    });

    // An ordering it cannot establish must not admit anything.
    it('refuses everything when the floor names a build it does not know', function () {
        $builds = (new BuildRegistry('b2', ['b1']))->floor('b-unknown');

        expect($builds->accepts('b1'))->toBeFalse()
            ->and($builds->accepts('b2'))->toBeFalse();
    });

    // retain() appended, so a build re-added after construction became the
    // NEWEST -- and a registry with a floor above it silently admitted it.
    // That is the one operation this method exists for: rolling an old build
    // back into rotation.
    it('does not lift a re-retained build over the floor', function () {
        $builds = (new BuildRegistry('b3', ['b2']))->floor('b3');

        expect($builds->accepts('b1'))->toBeFalse();

        $builds->retain('b1');

        expect($builds->accepts('b1'))->toBeFalse('re-adding it must not promote it')
            ->and($builds->retained())->toBe(['b1', 'b2', 'b3']);
    });

    it('places a re-retained build after the one it names', function () {
        $builds = (new BuildRegistry('b4', ['b1', 'b3']))->retain('b2', after: 'b1');

        expect($builds->retained())->toBe(['b1', 'b2', 'b3', 'b4']);
    });

    it('refuses to place a build after one it does not know', function () {
        expect(fn () => (new BuildRegistry('b2'))->retain('b1', after: 'nope'))
            ->toThrow(InvalidArgumentException::class, 'not retained');
    });

    it('keeps the current build newest, whatever order it was given in', function () {
        $builds = new BuildRegistry('b3', ['b1', 'b2']);

        expect($builds->retained())->toBe(['b1', 'b2', 'b3']);
    });

    it('refuses a missing version', function () {
        expect((new BuildRegistry('b1'))->accepts(null))->toBeFalse();
    });
});

/**
 * The token's panel was covered by the MAC and consulted by nothing. A
 * fragment rendering under a different panel than the one it was issued in
 * sees a different narrowing, and its query cache keys disagree with the
 * page's.
 */
describe('the panel a fragment runs in', function () {
    it('renders inside the panel the token was minted for', function () {
        [$endpoint, $fixture, $signer, , $log] = fragmentEndpoint();

        $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture, ['panel' => 'admin'])));

        expect($log->calls[0]['panel'])->toBe('admin');
    });

    it('leaves no panel set once the request is done', function () {
        [$endpoint, $fixture, $signer, , , $panels] = fragmentEndpoint();

        $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture, ['panel' => 'admin'])));

        expect($panels->isSet())->toBeFalse();
    });

    // A panel-less token runs with NO panel, exactly as the page render
    // does. Inventing a name for the no-panel state -- the endpoint used
    // 'none' -- re-introduced the conflation PanelContext refuses an empty
    // name to prevent, one name over: an application may legitimately have a
    // panel called 'none', and its fragments would have been run inside it.
    it('runs a panel-less token with no panel at all', function () {
        [$endpoint, $fixture, $signer, , $log] = fragmentEndpoint();

        $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture, ['panel' => ''])));

        expect($log->calls[0]['panel'])->toBeNull();
    });

    /**
     * within() is an unconditional set, so the token's panel REPLACED the
     * one the request had established. A token minted while a session was in
     * a wider panel then re-asserted it after the session narrowed, switched
     * panel, or lost a membership -- a client-held assertion surviving
     * exactly the event the spec purges client-held data on.
     */
    it('refuses a token minted in a different panel than the request is in', function () {
        [$endpoint, $fixture, $signer, , , $panels] = fragmentEndpoint();

        $refused = $panels->within('customer', static function () use ($endpoint, $signer, $fixture): bool {
            try {
                $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture, ['panel' => 'admin'])));

                return false;
            } catch (HttpException $e) {
                return $e->status === 404;
            }
        });

        expect($refused)->toBeTrue();
    });

    it('allows a token minted in the panel the request is in', function () {
        [$endpoint, $fixture, $signer, , $log, $panels] = fragmentEndpoint();

        $panels->within('customer', static function () use ($endpoint, $signer, $fixture): void {
            $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture, ['panel' => 'customer'])));
        });

        expect($log->calls[0]['panel'])->toBe('customer');
    });
});

/**
 * "Client-held data is bound to (user, tenant)." The panel was defended and
 * the tenant was not carried at all; when it was added, the check read
 * `$this->orm?->tenant()` from an Orm the Kernel never passed -- so it was
 * null on every request and the comparison never ran. The same unwired shape
 * as the mint closure, in the commit that fixed the mint closure.
 */
describe('the tenant a fragment runs in', function () {
    it('refuses a token minted for a different tenant', function () {
        [$endpoint, $fixture, $signer, , , , $orm] = fragmentEndpoint();
        $orm->switchTenant('acme');

        expect(fn () => $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture, ['tenant' => 'globex']),
        )))->toThrow(HttpException::class, 'Fragment unavailable');
    });

    it('allows a token minted for the tenant the request is in', function () {
        [$endpoint, $fixture, $signer, , $log, , $orm] = fragmentEndpoint();
        $orm->switchTenant('acme');

        $endpoint->handle(fragmentRequest($signer, fragmentToken($signer, $fixture, ['tenant' => 'acme'])));

        expect($log->calls)->toHaveCount(1);
    });

    // An application with no tenancy mints '' and matches '', so comparing
    // always is correct there too -- there is no state in which the check is
    // skipped.
    it('refuses a tenant-bearing token when the request has none', function () {
        [$endpoint, $fixture, $signer] = fragmentEndpoint();

        expect(fn () => $endpoint->handle(fragmentRequest(
            $signer,
            fragmentToken($signer, $fixture, ['tenant' => 'acme']),
        )))->toThrow(HttpException::class, 'Fragment unavailable');
    });
});
