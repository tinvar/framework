<?php

declare(strict_types=1);

/**
 * A page never answers under /_ (ADR 0075). Every path there is the
 * framework's -- /_action, /_export, /_test and the rest -- and one it has
 * not mounted, because the application gave it no transfers or is not a
 * test build, fell through to the pages: a catch-all `/{a}/{b}` rendered
 * itself for `/_export/orders`.
 */

namespace Tinvar\Tests\Routing\FrameworkPaths;

use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Auth\Violation;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;

#[Page('/_admin/reports', view: 'admin/reports')]
final class UnderscorePage {}

#[Page('/_reports', view: 'reports')]
final class UnderscoreTopPage {}

#[Layout('/_internal', view: 'internal/layout')]
final class UnderscoreLayout {}

#[Page('/{section}/{slug}', view: 'section/show')]
final class CatchAll {}

#[Page('/{slug}', view: 'slug/show')]
final class TopCatchAll {}

#[Page('/reports/_draft', view: 'reports/draft')]
final class DeeperUnderscore {}

it('refuses a page or a layout whose path starts under /_', function (string $class) {
    expect(fn () => (new RouteRegistry())->register($class))
        ->toThrow(RoutingException::class, "is under /_, which is the framework's own");
})->with([
    'a page' => [UnderscorePage::class],
    'a one-segment page' => [UnderscoreTopPage::class],
    'a layout' => [UnderscoreLayout::class],
]);

it('takes an underscore anywhere past the first segment', function () {
    $routes = new RouteRegistry();
    $routes->register(DeeperUnderscore::class);

    expect($routes->match('/reports/_draft')?->page->class)->toBe(DeeperUnderscore::class);
});

it('matches no page to a path under /_, whatever its parameters would take', function (string $path) {
    $routes = new RouteRegistry();
    $routes->registerAll([CatchAll::class, TopCatchAll::class]);

    expect($routes->match($path))->toBeNull();
})->with([
    'an unmounted export' => ['/_export/orders'],
    'the test endpoint, outside a test build' => ['/_test/seed'],
    'one segment' => ['/_health'],
    'doubled slashes' => ['//_export//orders'],
]);

it('still matches the same catch-all anywhere else', function () {
    $routes = new RouteRegistry();
    $routes->registerAll([CatchAll::class, TopCatchAll::class]);

    expect($routes->match('/export/orders')?->page->class)->toBe(CatchAll::class)
        ->and($routes->match('/about')?->page->class)->toBe(TopCatchAll::class);
});

// `tinvar check` reports it as a violation, as it does an endpoint's.
it('reports one to tinvar check, and registers the rest', function () {
    $routes = new RouteRegistry();
    $violations = RouteRegistry::check([UnderscorePage::class, CatchAll::class], $routes);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toBeInstanceOf(Violation::class)
        ->and($violations[0]->rule)->toBe(RouteRegistry::ROUTES_MUST_COMPILE)
        ->and($violations[0]->where)->toBe(UnderscorePage::class)
        ->and($routes->match('/a/b')?->page->class)->toBe(CatchAll::class);
});
