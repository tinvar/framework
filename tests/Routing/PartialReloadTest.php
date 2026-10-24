<?php

declare(strict_types=1);

/**
 * Partial reloads: `X-Only` / `X-Except` "re-run only named loader fields
 * and queries", and `X-Page` makes them "apply only to the same page".
 *
 * ADR 0015: the unit is the segment -- each layout and the page -- because a
 * segment re-rendered alone needs all of its data, and one loader produces
 * it. What these pin down: which loaders run, which data is handed on, that
 * every check in the chain runs whatever is named, and that anything in
 * doubt renders the page whole.
 */

namespace Tinvar\Tests\Routing\PartialReload;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\PartialReload;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures\RackShowPage;
use Tinvar\Tests\Routing\Fixtures\RootLayout;
use Tinvar\Tests\Routing\Fixtures\SiteLayout;
use Tinvar\Tests\Routing\Fixtures\Trace;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/routes.php';

final readonly class BannerData extends Data
{
    public function __construct(public string $text) {}
}

final readonly class NoticePageData extends Data
{
    public function __construct(public string $text) {}
}

/** Declares no return type, so nothing says what it returns until it runs. */
#[Layout('/notices', view: 'notices/layout')]
final class UntypedLayout
{
    /** @phpstan-ignore missingType.return */
    public function __invoke()
    {
        Trace::note('layout:untyped');

        return new BannerData('banner');
    }
}

#[Page('/notices/latest', view: 'notices/latest', name: 'notices.latest')]
final class NoticePage
{
    public function __invoke(BannerData $banner): NoticePageData
    {
        Trace::note('page:notice');

        return new NoticePageData("notice under {$banner->text}");
    }
}

/** A layout whose key would be read as the page's segment. */
#[Layout('/ambiguous', view: 'page/layout')]
final class PageKeyedLayout
{
    public function __invoke(): BannerData
    {
        return new BannerData('x');
    }
}

#[Page('/ambiguous/here', view: 'ambiguous/here', name: 'ambiguous.here')]
final class AmbiguousPage
{
    public function __invoke(): NoticePageData
    {
        return new NoticePageData('x');
    }
}

/** Allows or denies everything, and remembers what it was asked. */
final class RecordingGate implements Gate
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly bool $allow = true) {}

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->asked[] = $check->ability . ':' . ($check->argument ?? '');

        return $this->allow;
    }
}

/** @return array{Router, Fixture, RouteRegistry} */
function router(Gate $gate, bool $partialReloads = true): array
{
    Trace::reset();
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $routes = new RouteRegistry();
    $routes->registerAll([
        RootLayout::class, SiteLayout::class, RackShowPage::class,
        UntypedLayout::class, NoticePage::class,
        PageKeyedLayout::class, AmbiguousPage::class,
    ]);

    $router = new Router(
        $routes,
        new LoaderRunner($orm, static fn (string $class): object => new $class(), $gate),
        static fn (string $class): Middleware => new $class(),
        partialReloads: $partialReloads,
    );

    return [$router, $fixture, $routes];
}

function rackPath(Fixture $fixture): string
{
    return "/sites/{$fixture->ids['sites'][0]}/racks/{$fixture->ids['racks'][0]}";
}

/**
 * Asks for part of the rack page.
 *
 * @param array<string, string> $headers
 * @return array{Response, LoadedRoute|null}
 */
function ask(Router $router, string $path, array $headers): array
{
    $loaded = null;
    $response = $router->handle(
        Request::get($path, ['RSC' => '1', ...$headers]),
        static function (LoadedRoute $route) use (&$loaded): Response {
            $loaded = $route;

            return Response::make(200, 'payload');
        },
    );

    return [$response, $loaded];
}

const RACK = ['X-Page' => 'racks/show'];

describe('what is recomputed', function () {
    it('is the segment a path names, and nothing outside it', function (string $only, array $segments) {
        [$router, $fixture] = router(new RecordingGate());

        [, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => $only]);

        expect($loaded?->segments)->toBe($segments);
    })->with([
        'the page' => ['page', ['page']],
        'a field of the page' => ['page.rack', ['page']],
        'a nested field' => ['page.rack.name', ['page']],
        'a layout' => ['sites', ['sites']],
        'a layout\'s field' => ['root.tenant', ['root']],
        'two' => ['root, page.siteCode', ['root', 'page']],
        'nothing on this route' => ['elsewhere', []],
    ]);

    // Recomputing more than was asked is always correct; less never is. One
    // loader makes all of its segment's fields, so a field cannot be left
    // out of a segment that is recomputed.
    it('leaves out only a segment X-Except names whole', function (string $except, array $segments) {
        [$router, $fixture] = router(new RecordingGate());

        [, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Except' => $except]);

        expect($loaded?->segments)->toBe($segments);
    })->with([
        'the page' => ['page', ['root', 'sites']],
        'a field of the page' => ['page.rack', ['root', 'sites', 'page']],
        'a layout' => ['root', ['sites', 'page']],
    ]);

    it('takes X-Except away from what X-Only names', function () {
        [$router, $fixture] = router(new RecordingGate());

        [, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'root,sites,page', 'X-Except' => 'sites']);

        expect($loaded?->segments)->toBe(['root', 'page']);
    });
});

describe('which loaders run', function () {
    it('runs the recomputed segment\'s loader and those it takes data from', function () {
        [$router, $fixture] = router(new RecordingGate());

        // The page takes SiteLayoutData, which takes RootLayoutData.
        [, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'page']);

        expect(Trace::$events)->toBe([
            'middleware:outer',
            'middleware:inner',
            'layout:root',
            'layout:site',
            'page:rack-show',
        ]);
        // Run for their data, not rendered: none of it is handed on.
        expect($loaded?->layouts)->toBe([])
            ->and($loaded?->page)->not->toBeNull();
    });

    it('does not run a page that is not recomputed', function () {
        [$router, $fixture] = router(new RecordingGate());

        [, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'sites']);

        expect(Trace::$events)->not->toContain('page:rack-show')
            ->and(Trace::$events)->toContain('layout:root')
            ->and($loaded?->page)->toBeNull()
            ->and(array_keys($loaded->layouts ?? []))->toBe(['sites']);
    });

    it('runs nothing a recomputed loader does not need', function () {
        [$router, $fixture] = router(new RecordingGate());

        [, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'root']);

        expect(array_values(array_filter(Trace::$events, static fn (string $e): bool => !str_starts_with($e, 'middleware:'))))
            ->toBe(['layout:root'])
            ->and(array_keys($loaded->layouts ?? []))->toBe(['root']);
    });

    // Skipping a loader that was needed would fail the request; running one
    // that was not only costs time.
    it('runs a layout whose return type says nothing, when something below takes data', function () {
        [$router] = router(new RecordingGate());

        [, $loaded] = ask($router, '/notices/latest', ['X-Page' => 'notices/latest', 'X-Only' => 'page']);

        // The root layout returns a type nothing below takes, so it is the
        // one left out.
        expect(Trace::$events)->toBe(['middleware:outer', 'layout:untyped', 'page:notice'])
            ->and($loaded?->page)->toEqual(new NoticePageData('notice under banner'));
    });

    // "Authorization always runs": a refresh of one segment is a refresh of a
    // page the session must be allowed to see.
    it('runs every check in the chain, whatever is recomputed', function () {
        $gate = new RecordingGate();
        [$router, $fixture] = router($gate);

        ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'root']);

        expect($gate->asked)->toBe(['view:site', 'view:rack']);
    });

    it('is refused as the whole page is, before any loader runs', function () {
        [$router, $fixture] = router(new RecordingGate(allow: false));

        expect(static fn () => ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'root']))
            ->toThrow(ForbiddenException::class);
        expect(Trace::$events)->not->toContain('layout:root');
    });
});

describe('a whole page instead', function () {
    it('without X-Page, or with another page\'s', function (array $headers) {
        [$router, $fixture] = router(new RecordingGate());

        [$response, $loaded] = ask($router, rackPath($fixture), ['X-Only' => 'page', ...$headers]);

        expect($loaded?->segments)->toBeNull()
            ->and(array_keys($loaded->layouts ?? []))->toBe(['root', 'sites'])
            ->and($response->header('x-partial'))->toBeNull();
    })->with([
        'no X-Page' => [[]],
        'another page' => [['X-Page' => 'racks/index']],
    ]);

    it('for a list it cannot read', function (string $only) {
        [$router, $fixture] = router(new RecordingGate());

        [, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => $only]);

        expect($loaded?->segments)->toBeNull();
    })->with([
        'a space inside a path' => ['page order'],
        'a stray dot' => ['page..rack'],
        'markup' => ['<script>'],
        'too many paths' => [implode(',', array_fill(0, PartialReload::MAX_PATHS + 1, 'page'))],
        'too long' => [str_repeat('a', PartialReload::MAX_HEADER_BYTES + 1)],
    ]);

    it('for a document load', function () {
        [$router, $fixture] = router(new RecordingGate());
        $loaded = null;

        $router->handle(
            Request::get(rackPath($fixture), [...RACK, 'X-Only' => 'page']),
            static function (LoadedRoute $route) use (&$loaded): Response {
                $loaded = $route;

                return Response::make(200, '<html>');
            },
        );

        expect($loaded?->segments)->toBeNull();
    });

    it('where a layout\'s key would be read as the page', function () {
        [$router] = router(new RecordingGate());

        [, $loaded] = ask($router, '/ambiguous/here', ['X-Page' => 'ambiguous/here', 'X-Only' => 'page']);

        expect($loaded?->segments)->toBeNull();
    });

    // An application whose renderer would be handed a partial frame it does
    // not know how to render has not turned them on.
    it('where the application has not turned partial reloads on', function () {
        [$router, $fixture] = router(new RecordingGate(), partialReloads: false);

        [$response, $loaded] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'page']);

        expect($loaded?->segments)->toBeNull()
            ->and($response->header('x-page'))->toBeNull()
            ->and($response->header('vary'))->toBe('RSC, X-Version');
    });
});

describe('the response', function () {
    it('names its page on every payload, and varies on what selects a part', function () {
        [$router, $fixture] = router(new RecordingGate());

        [$response] = ask($router, rackPath($fixture), []);

        expect($response->header('x-page'))->toBe('racks/show')
            ->and($response->header('x-partial'))->toBeNull()
            ->and($response->header('vary'))->toBe('RSC, X-Version, X-Page, X-Only, X-Except');
    });

    // Part of one client's page as it holds it now.
    it('marks a partial payload, and lets nothing keep it', function () {
        [$router, $fixture] = router(new RecordingGate());

        [$response] = ask($router, rackPath($fixture), [...RACK, 'X-Only' => 'page']);

        expect($response->header('x-partial'))->toBe('1')
            ->and($response->header('cache-control'))->toBe('private, no-store');
    });

    it('says nothing of pages to a document load', function () {
        [$router, $fixture] = router(new RecordingGate());

        $response = $router->handle(Request::get(rackPath($fixture)), static fn (): Response => Response::make(200, '<html>'));

        expect($response->header('x-page'))->toBeNull();
    });
});
