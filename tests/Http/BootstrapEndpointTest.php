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

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
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

    $manifest ??= (new FragmentManifest())
        ->declare('racks.show', 'history', 'UnitHistory', ['unit' => 'u-1'])
        ->declare('racks.show', 'audit', 'AuditLog');

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

    it('refuses params that do not fit the route', function () {
        [$endpoint] = bootstrapEndpoint();

        expect(fn () => $endpoint->handle(
            Request::post(BootstrapEndpoint::PATH),
            json_encode(['route' => 'racks.show', 'params' => ['rack' => 'r-1']]),
        ))->toThrow(HttpException::class, 'do not go together');
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
    it('refuses two boundaries under one id', function () {
        expect(fn () => (new FragmentManifest())
            ->declare('racks.show', 'history', 'A')
            ->declare('racks.show', 'history', 'B'))
            ->toThrow(InvalidArgumentException::class, 'two deferred boundaries');
    });

    it('refuses a boundary with no id', function () {
        expect(fn () => (new FragmentManifest())->declare('racks.show', '', 'A'))
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
            'racks.show' => [['id' => 'history', 'component' => 'UnitHistory', 'props' => ['unit' => 'u-1']]],
        ]));

        try {
            $manifest = FragmentManifest::fromFile($path);

            expect($manifest->routes())->toBe(['racks.show'])
                ->and($manifest->boundariesFor('racks.show')[0]['component'])->toBe('UnitHistory');
        } finally {
            unlink($path);
        }
    });
});
