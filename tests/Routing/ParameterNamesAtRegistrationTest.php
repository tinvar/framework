<?php

declare(strict_types=1);

/**
 * A page naming a parameter differently from the layout that wraps it was
 * caught on the first request under the pair, not at registration -- and the
 * comment saying otherwise named a method that did not exist.
 */

namespace Tinvar\Tests\Routing\ParameterNamesAtRegistration;

use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;

#[Layout('/sites/{site}', view: 'sites/layout')]
final class SiteLayout {}

#[Page('/sites/{siteId}/edit', view: 'sites/edit')]
final class EditSite {}

#[Page('/sites/{site}/racks', view: 'sites/racks')]
final class SiteRacks {}

#[Page('/sites', view: 'sites/index')]
final class SiteIndex {}

#[Page('/units/{unit}', view: 'units/show')]
final class ShowUnit {}

it('refuses the pair at registration, whichever registers first', function (array $order) {
    expect(fn () => (new RouteRegistry())->registerAll($order))
        ->toThrow(RoutingException::class, 'names the parameter at position 1 differently');
})->with([
    'layout first' => [[SiteLayout::class, EditSite::class]],
    'page first' => [[EditSite::class, SiteLayout::class]],
    'among others' => [[SiteIndex::class, EditSite::class, SiteRacks::class, SiteLayout::class]],
]);

it('accepts pages that agree with their layouts, and pages no layout wraps', function () {
    $registry = new RouteRegistry();
    $registry->registerAll([SiteRacks::class, SiteLayout::class, SiteIndex::class, ShowUnit::class]);

    expect(array_map(static fn ($l) => $l->class, $registry->match('/sites/x/racks')?->layouts ?? []))
        ->toBe([SiteLayout::class])
        ->and($registry->match('/units/1')?->layouts)->toBe([]);
});
