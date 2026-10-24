<?php

declare(strict_types=1);

/**
 * The fragment manifest, produced from the build rather than declared by
 * hand: the renderer build says which views reach a <Deferred> boundary, the
 * route registry which views each route renders -- its page and every layout
 * around it -- and a route defers when any of them does.
 */

namespace Tinvar\Tests\Render\FragmentManifestProducer;

use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentManifestProducer;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Routing\Fixtures\HealthPage;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;
use Tinvar\Tests\Routing\Fixtures\SiteNewPage;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Routing/Fixtures/routes.php';

/** A file removed when the test process ends. */
function file(string $contents = ''): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tinvar-manifest-');
    file_put_contents($path, $contents);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        if (getmypid() === $owner) {
            @unlink($path);
        }
    });

    return $path;
}

/** @param array<string, list<string>> $views what the Vite plugin wrote */
function build(array $views): string
{
    return file(json_encode(['format' => 1, 'views' => $views], JSON_THROW_ON_ERROR));
}

function routes(): RouteRegistry
{
    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, SiteLayout::class, RackShowPage::class, SiteNewPage::class, HealthPage::class]);

    return $routes;
}

/** Every view the fixture routes render, with no boundaries. */
const QUIET = ['root' => [], 'sites/layout' => [], 'racks/show' => [], 'sites/new' => [], 'health' => []];

describe('producing the manifest', function () {
    it('declares a route whose page reaches a boundary, with its components', function () {
        $manifest = FragmentManifestProducer::produce(routes(), build([...QUIET, 'racks/show' => ['UnitHistory']]));

        expect($manifest->routes())->toBe(['racks.show'])
            ->and($manifest->componentsFor('racks.show'))->toBe(['UnitHistory'])
            ->and($manifest->defersOn('health'))->toBeFalse();
    });

    // A layout's boundary is in every page under it.
    it('declares every route under a layout that reaches one', function () {
        $manifest = FragmentManifestProducer::produce(routes(), build([...QUIET, 'sites/layout' => ['SiteBanner']]));

        expect($manifest->routes())->toBe(['racks.show'])
            ->and($manifest->componentsFor('racks.show'))->toBe(['SiteBanner']);
    });

    it('joins the components of the page and its layouts', function () {
        $manifest = FragmentManifestProducer::produce(routes(), build([
            ...QUIET,
            'root' => ['Notices'],
            'racks/show' => ['UnitHistory', 'AuditLog'],
        ]));

        expect($manifest->componentsFor('racks.show'))->toBe(['AuditLog', 'Notices', 'UnitHistory'])
            ->and($manifest->componentsFor('health'))->toBe(['Notices'])
            ->and($manifest->routes())->toEqualCanonicalizing(['racks.show', 'sites.new', 'health']);
    });

    // A name the build could not read widens: left out, the route's
    // boundaries would have no minter and fail to render.
    it('allows any component where the build could not read one', function () {
        $manifest = FragmentManifestProducer::produce(routes(), build([...QUIET, 'racks/show' => ['*']]));

        expect($manifest->defersOn('racks.show'))->toBeTrue()
            ->and($manifest->componentsFor('racks.show'))->toBeNull();
    });

    it('declares nothing for a build with no boundaries', function () {
        expect(FragmentManifestProducer::produce(routes(), build(QUIET))->routes())->toBe([]);
    });

    // Guessing "no boundaries" would leave its renders without a minter.
    it('refuses a route whose view the build has no module for', function () {
        $views = QUIET;
        unset($views['sites/new']);

        expect(fn () => FragmentManifestProducer::produce(routes(), build($views)))
            ->toThrow(\RuntimeException::class, "no module for view 'sites/new'");
    });

    it('refuses a build that did not list its boundaries', function (?string $contents) {
        $path = $contents === null ? sys_get_temp_dir() . '/no-such-deferred.json' : file($contents);

        expect(fn () => FragmentManifestProducer::produce(routes(), $path))->toThrow(\RuntimeException::class);
    })->with([
        'no file' => [null],
        'not JSON' => ['{'],
        'another format' => ['{"format": 2, "views": {}}'],
        'a view with no list' => ['{"format": 1, "views": {"health": "x"}}'],
        'a component that is not a string' => ['{"format": 1, "views": {"health": [1]}}'],
    ]);
});

describe('the manifest file', function () {
    it('reads back what it wrote: routes, components and built pages', function () {
        $manifest = new FragmentManifest()
            ->declareRoute('racks.show', ['UnitHistory'])
            ->declareRoute('health')
            ->declare('docs.page', 'toc', 'Toc', ['depth' => 2], ['page' => 2]);
        $path = file();
        $manifest->write($path);

        $read = FragmentManifest::fromFile($path);

        expect($read->toArray())->toBe($manifest->toArray())
            ->and($read->componentsFor('racks.show'))->toBe(['UnitHistory'])
            ->and($read->componentsFor('health'))->toBeNull()
            ->and($read->boundariesFor('docs.page', ['page' => '2']))->toBe([
                ['id' => 'toc', 'component' => 'Toc', 'props' => ['depth' => 2]],
            ])
            ->and($read->hasAny())->toBeTrue();
    });

    // Written before the producer existed, by hand or by a test.
    it('still reads the bare map of route to built boundaries', function () {
        $path = file(json_encode(['racks.show' => [['id' => 'history', 'component' => 'UnitHistory', 'params' => ['rack' => 'r-1']]]]));

        expect(FragmentManifest::fromFile($path)->boundariesFor('racks.show', ['rack' => 'r-1']))->toHaveCount(1);
    });

    it('refuses a written manifest it cannot read strictly', function (string $contents) {
        expect(fn () => FragmentManifest::fromFile(file($contents)))->toThrow(\RuntimeException::class);
    })->with([
        'another format' => ['{"format": 2, "routes": {}, "pages": {}}'],
        'components as an object' => ['{"format": 1, "routes": {"a": {"x": "Toc"}}, "pages": {}}'],
        'a component that is not a string' => ['{"format": 1, "routes": {"a": [1]}, "pages": {}}'],
        'a boundary without an id' => ['{"format": 1, "routes": {}, "pages": {"a": [{"component": "Toc"}]}}'],
    ]);
});

describe('what a route may mint for', function () {
    it('allows what either declaration does', function () {
        $manifest = new FragmentManifest()->declareRoute('a', ['X'])->declareRoute('a', ['Y']);

        expect($manifest->componentsFor('a'))->toBe(['X', 'Y']);
    });

    it('allows any once one declaration allows any', function () {
        expect(new FragmentManifest()->declareRoute('a', ['X'])->declareRoute('a')->componentsFor('a'))->toBeNull()
            ->and(new FragmentManifest()->declareRoute('a')->declareRoute('a', ['X'])->componentsFor('a'))->toBeNull();
    });
});
