<?php

declare(strict_types=1);

/**
 * "Fragments inside static pages get their tokens from a small per-visitor
 * bootstrap request, never from the build."
 *
 * A build-time token has no session to bind to, so one token would be every
 * visitor's -- and the session binding is what makes a token lifted from page
 * source inert.
 */

use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

/** @return array{0: BootstrapEndpoint, 1: Fixture, 2: Signer} */
function bootstrapEndpoint(?FragmentManifest $manifest = null, string $session = 'session-abc'): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $signer = new Signer('test-app-key', FragmentToken::PURPOSE);

    $routes = new RouteRegistry();
    $routes->registerAll([
        Tinvar\Tests\Routing\Fixtures\RootLayout::class,
        Tinvar\Tests\Routing\Fixtures\SiteLayout::class,
        Tinvar\Tests\Routing\Fixtures\RackShowPage::class,
    ]);

    // Declared for the page the build actually emitted -- route AND params.
    $built = ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]];

    $manifest ??= (new FragmentManifest())
        ->declare('racks.show', 'history', 'UnitHistory', ['unit' => 'u-1'], $built)
        ->declare('racks.show', 'audit', 'AuditLog', [], $built);

    $support = new FragmentSupport(
        signer: $signer,
        builds: new BuildRegistry('build-1'),
        render: static fn (): mixed => null,
        sessionId: static fn (Request $r): string => $r->header('x-session') ?? $session,
    );

    return [new BootstrapEndpoint($routes, $manifest, $support), $fixture, $signer];
}

function bootstrapBody(Fixture $fixture): string
{
    return json_encode([
        'route' => 'racks.show',
        'params' => ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
    ]);
}

describe('bootstrapping a static page', function () {
    it('mints a token for every boundary the build recorded', function () {
        [$endpoint, $fixture, $signer] = bootstrapEndpoint();

        $response = $endpoint->handle(Request::post(BootstrapEndpoint::PATH), bootstrapBody($fixture));
        $tokens = json_decode($response->body(), true)['tokens'];

        expect(array_keys($tokens))->toBe(['history', 'audit'])
            ->and(FragmentToken::decode($tokens['history'], $signer)->component)->toBe('UnitHistory')
            ->and(FragmentToken::decode($tokens['history'], $signer)->props)->toBe(['unit' => 'u-1']);
    });

    it('binds them to the visitor making the request', function () {
        [$endpoint, $fixture, $signer] = bootstrapEndpoint();

        $response = $endpoint->handle(
            new Request('POST', BootstrapEndpoint::PATH, ['x-session' => 'visitor-1']),
            bootstrapBody($fixture),
        );
        $token = FragmentToken::decode(json_decode($response->body(), true)['tokens']['history'], $signer);

        expect($token->belongsTo($signer, 'visitor-1'))->toBeTrue()
            ->and($token->belongsTo($signer, 'visitor-2'))->toBeFalse();
    });

    it('binds the route and params the page was built for', function () {
        [$endpoint, $fixture, $signer] = bootstrapEndpoint();

        $response = $endpoint->handle(Request::post(BootstrapEndpoint::PATH), bootstrapBody($fixture));
        $token = FragmentToken::decode(json_decode($response->body(), true)['tokens']['audit'], $signer);

        expect($token->route)->toBe('racks.show')
            ->and($token->params['rack'])->toBe($fixture->ids['racks'][0]);
    });

    // Two visitors must never be handed the same token, which is the whole
    // reason the build does not mint them.
    it('gives two visitors different tokens', function () {
        [$endpoint, $fixture] = bootstrapEndpoint();

        $first = $endpoint->handle(
            new Request('POST', BootstrapEndpoint::PATH, ['x-session' => 'visitor-1']),
            bootstrapBody($fixture),
        );
        $second = $endpoint->handle(
            new Request('POST', BootstrapEndpoint::PATH, ['x-session' => 'visitor-2']),
            bootstrapBody($fixture),
        );

        expect(json_decode($first->body(), true)['tokens']['history'])
            ->not->toBe(json_decode($second->body(), true)['tokens']['history']);
    });

    it('is never cached across visitors', function () {
        [$endpoint, $fixture] = bootstrapEndpoint();

        $response = $endpoint->handle(Request::post(BootstrapEndpoint::PATH), bootstrapBody($fixture));

        expect($response->header('cache-control'))->toBe('private, no-store')
            ->and($response->header('vary'))->toBe('Cookie');
    });
});

/**
 * The caller names a route and nothing else. Signing whatever component and
 * props the page source claimed would hand the signing key to anyone who can
 * edit a copy of the HTML.
 */
describe('what a caller may ask to have signed', function () {
    it('ignores a component and props the caller supplied', function () {
        [$endpoint, $fixture, $signer] = bootstrapEndpoint();

        $response = $endpoint->handle(Request::post(BootstrapEndpoint::PATH), json_encode([
            'route' => 'racks.show',
            'params' => ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]],
            'boundaries' => [['id' => 'evil', 'component' => 'AdminPanel', 'props' => ['all' => true]]],
        ]));
        $tokens = json_decode($response->body(), true)['tokens'];

        expect(array_keys($tokens))->toBe(['history', 'audit'], 'only what the build recorded')
            ->and(FragmentToken::decode($tokens['history'], $signer)->component)->toBe('UnitHistory');
    });

    // A page built for one entity must not hand out tokens for another's.
    it('answers empty for params the page was not built with', function () {
        [$endpoint, $fixture] = bootstrapEndpoint();

        $response = $endpoint->handle(Request::post(BootstrapEndpoint::PATH), json_encode([
            'route' => 'racks.show',
            'params' => ['site' => $fixture->ids['sites'][0], 'rack' => 'some-other-rack'],
        ]));

        expect(json_decode($response->body(), true)['tokens'])->toBe([]);
    });

    // "No such route" and "no boundaries" are the same answer, or the
    // response says which routes exist.
    it('answers empty for a route the build never recorded', function () {
        [$endpoint, $fixture] = bootstrapEndpoint();

        $response = $endpoint->handle(Request::post(BootstrapEndpoint::PATH), json_encode([
            'route' => 'not.a.route',
            'params' => [],
        ]));

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['tokens'])->toBe([]);
    });

    /**
     * The SAME answer as an unbuilt page. Distinguishing "in the manifest but
     * the params do not form" from "not in the manifest" confirmed which
     * pages were built statically -- reintroducing, in the caller, the oracle
     * the manifest returns empty specifically to avoid.
     */
    it('answers empty, not 400, for params the route cannot form', function () {
        $manifest = (new FragmentManifest())
            ->declare('racks.show', 'history', 'UnitHistory', [], ['rack' => 'r-1']);
        [$endpoint] = bootstrapEndpoint($manifest);

        $response = $endpoint->handle(
            Request::post(BootstrapEndpoint::PATH),
            json_encode(['route' => 'racks.show', 'params' => ['rack' => 'r-1']]),
        );

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['tokens'])->toBe([]);
    });

    // The strval bug FragmentToken::params() documents as fixed, still here.
    it('refuses params that are not scalars', function () {
        [$endpoint] = bootstrapEndpoint();

        expect(fn () => $endpoint->handle(
            Request::post(BootstrapEndpoint::PATH),
            json_encode(['route' => 'racks.show', 'params' => ['rack' => ['nested']]]),
        ))->toThrow(HttpException::class, 'must be scalars');
    });

    /**
     * A visitor arriving at a statically-served page may have no session
     * yet. That is the ordinary case, not an error -- but it cannot be a
     * 500, which is what letting the minter's exception out produced:
     * remotely triggerable, unauthenticated.
     */
    it('refuses cleanly when the visitor has no session yet', function () {
        [$endpoint, $fixture] = bootstrapEndpoint(session: '');

        $caught = null;

        try {
            $endpoint->handle(Request::post(BootstrapEndpoint::PATH), bootstrapBody($fixture));
        } catch (HttpException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull('a session-less bootstrap was accepted')
            ->and($caught->status)->toBe(409)
            ->and($caught->code_)->toBe('no_session');
    });

    it('refuses anything but POST', function () {
        [$endpoint] = bootstrapEndpoint();

        expect(fn () => $endpoint->handle(new Request('GET', BootstrapEndpoint::PATH)))
            ->toThrow(HttpException::class, 'POST only');
    });

    it('refuses a body with no route', function () {
        [$endpoint] = bootstrapEndpoint();

        expect(fn () => $endpoint->handle(Request::post(BootstrapEndpoint::PATH), '{"params":{}}'))
            ->toThrow(HttpException::class, 'needs the route');
    });

    it('refuses an oversized body', function () {
        [$endpoint] = bootstrapEndpoint();
        $huge = json_encode(['route' => 'racks.show', 'pad' => str_repeat('p', BootstrapEndpoint::MAX_BODY_BYTES)]);

        expect(fn () => $endpoint->handle(Request::post(BootstrapEndpoint::PATH), $huge))
            ->toThrow(HttpException::class);
    });
});

describe('the manifest', function () {
    it('refuses two boundaries under one id on one page', function () {
        expect(fn () => (new FragmentManifest())
            ->declare('racks.show', 'history', 'A', [], ['rack' => 'r-1'])
            ->declare('racks.show', 'history', 'B', [], ['rack' => 'r-1']))
            ->toThrow(InvalidArgumentException::class, 'two deferred boundaries');
    });

    // Two built pages of one route are two pages, and an id is only unique
    // within a page.
    it('allows one id on two different built pages', function () {
        $manifest = (new FragmentManifest())
            ->declare('racks.show', 'history', 'A', [], ['rack' => 'r-1'])
            ->declare('racks.show', 'history', 'B', [], ['rack' => 'r-2']);

        expect($manifest->boundariesFor('racks.show', ['rack' => 'r-1'])[0]['component'])->toBe('A')
            ->and($manifest->boundariesFor('racks.show', ['rack' => 'r-2'])[0]['component'])->toBe('B');
    });

    /**
     * Keying by route alone let a caller pair ANY params with the fixed
     * props the build recorded, minting a token for a combination that was
     * never rendered -- and the fragment endpoint authorizes the route chain
     * against the params, not the props, so nothing downstream caught it.
     */
    it('does not pair one page props with another page params', function () {
        $manifest = (new FragmentManifest())
            ->declare('racks.show', 'history', 'UnitHistory', ['unit' => 'someone-elses'], ['rack' => 'r-1']);

        expect($manifest->boundariesFor('racks.show', ['rack' => 'r-2']))
            ->toBe([], 'a different page is a different page');
    });

    it('does not care what order params arrive in', function () {
        $manifest = (new FragmentManifest())
            ->declare('racks.show', 'history', 'A', [], ['site' => 's-1', 'rack' => 'r-1']);

        expect($manifest->boundariesFor('racks.show', ['rack' => 'r-1', 'site' => 's-1']))
            ->toHaveCount(1);
    });

    it('refuses a boundary with no id', function () {
        expect(fn () => (new FragmentManifest())->declare('racks.show', '', 'A', [], ['rack' => 'r-1']))
            ->toThrow(InvalidArgumentException::class, 'needs an id');
    });

    // An application with no static pages has nothing to declare, and failing
    // to boot over it would be the wrong trade.
    it('treats a missing file as an empty manifest', function () {
        expect(FragmentManifest::fromFile('/no/such/manifest.json')->hasAny())->toBeFalse();
    });

    // A file that exists and is malformed is a broken build, not an absent one.
    it('refuses a manifest file that is not valid JSON', function () {
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, '{not json');

        try {
            expect(fn () => FragmentManifest::fromFile($path))
                ->toThrow(RuntimeException::class, 'not valid JSON');
        } finally {
            unlink($path);
        }
    });

    it('reads what a build wrote', function () {
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, json_encode([
            'racks.show' => [[
                'id' => 'history',
                'component' => 'UnitHistory',
                'props' => ['unit' => 'u-1'],
                'params' => ['rack' => 'r-1'],
            ]],
        ]));

        try {
            $manifest = FragmentManifest::fromFile($path);

            expect($manifest->pages())->toBe(['racks.show'])
                ->and($manifest->boundariesFor('racks.show', ['rack' => 'r-1'])[0]['component'])
                ->toBe('UnitHistory');
        } finally {
            unlink($path);
        }
    });
});

// A page revalidated in place draws its boundaries again, and the manifest's
// file is rewritten while the workers run (StaticBuilder::rebuild()): the
// endpoint mints what the file holds now, not what it booted with.
it('mints from the manifest as its file holds it now', function () {
    $directory = sys_get_temp_dir() . '/tinvar-bootstrap-refresh-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $path = "{$directory}/fragments.json";
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $built = ['site' => $fixture->ids['sites'][0], 'rack' => $fixture->ids['racks'][0]];
    new FragmentManifest()->declare('racks.show', 'old', 'UnitHistory', ['unit' => 'u-1'], $built)->write($path);
    [$endpoint, , $signer] = bootstrapEndpoint(FragmentManifest::fromFile($path));
    $body = json_encode(['route' => 'racks.show', 'params' => $built], JSON_THROW_ON_ERROR);

    try {
        $before = json_decode($endpoint->handle(Request::post(BootstrapEndpoint::PATH), $body)->body(), true)['tokens'];
        new FragmentManifest()->declare('racks.show', 'new', 'UnitHistory', ['unit' => 'u-2'], $built)->write($path);
        $after = json_decode($endpoint->handle(Request::post(BootstrapEndpoint::PATH), $body)->body(), true)['tokens'];
    } finally {
        exec('rm -rf ' . escapeshellarg($directory));
    }

    expect(array_keys($before))->toBe(['old'])
        ->and(array_keys($after))->toBe(['new'])
        ->and(FragmentToken::decode($after['new'], $signer)->props)->toBe(['unit' => 'u-2']);
});
