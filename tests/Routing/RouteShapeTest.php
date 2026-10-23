<?php

declare(strict_types=1);

/**
 * `/racks/{rack}` and `/racks/{id}` match the same paths. Both registered,
 * and registration order picked which one answered.
 */

namespace Tinvar\Tests\Routing\RouteShape;

use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;

#[Page('/racks/{rack}', view: 'racks/show')]
final class ShowRack {}

#[Page('/racks/{id}', view: 'racks/legacy')]
final class LegacyRack {}

#[Page('/racks/{id}/units', view: 'racks/units')]
final class RackUnits {}

#[Page('/racks/new', view: 'racks/new')]
final class NewRack {}

#[Layout('/sites/{site}', view: 'sites/layout')]
final class SiteLayout {}

#[Layout('/sites/{siteId}', view: 'legacy-sites/layout')]
final class LegacySiteLayout {}

it('refuses two pages whose parameters sit in the same places, in either order', function (array $order) {
    expect(fn () => (new RouteRegistry())->registerAll($order))
        ->toThrow(RoutingException::class, "Two routes claim '/racks/{");
})->with([
    'rack first' => [[ShowRack::class, LegacyRack::class]],
    'id first' => [[LegacyRack::class, ShowRack::class]],
]);

it('refuses two layouts of the same shape', function () {
    expect(fn () => (new RouteRegistry())->registerAll([SiteLayout::class, LegacySiteLayout::class]))
        ->toThrow(RoutingException::class, 'Two routes claim');
});

it('still accepts routes that differ in depth or in a literal', function () {
    $registry = new RouteRegistry();
    $registry->registerAll([ShowRack::class, RackUnits::class, NewRack::class]);

    expect($registry->match('/racks/new')?->page->class)->toBe(NewRack::class)
        ->and($registry->match('/racks/42')?->page->class)->toBe(ShowRack::class)
        ->and($registry->match('/racks/42/units')?->page->class)->toBe(RackUnits::class);
});
