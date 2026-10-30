<?php

declare(strict_types=1);

/**
 * Binder::assertBindable accepted a required loader parameter of a bindable
 * type that only the path could fill, on a template with no segment for it:
 * `/racks` taking `Rack $rack`, `/units` taking `int $limit`. Every request
 * to the route then failed.
 */

namespace Tinvar\Tests\Routing\UnfillableParameters;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Data\Data;
use Tinvar\Http\Request;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';

enum Shade: string
{
    case Light = 'light';
}

final readonly class ListData extends Data
{
    public function __construct(public string $value) {}
}

#[Page('/racks', view: 'racks/index'), Authorize('view', 'rack')]
final class RackIndex
{
    public function __invoke(Rack $rack): ListData
    {
        return new ListData($rack->name);
    }
}

#[Page('/units', view: 'units/index')]
final class UnitIndex
{
    public function __invoke(int $limit): ListData
    {
        return new ListData((string) $limit);
    }
}

#[Page('/shades', view: 'shades/index')]
final class ShadeIndex
{
    public function __invoke(Shade $shade): ListData
    {
        return new ListData($shade->value);
    }
}

#[Page('/notes', view: 'notes/index')]
final class NullableScalar
{
    public function __invoke(?string $filter): ListData
    {
        return new ListData($filter ?? '');
    }
}

/** A layout runs under every page below it, so a required model must be its own. */
#[Layout('/fleet', view: 'fleet/layout'), Authorize('view', 'rack')]
final class FleetLayout
{
    public function __invoke(Rack $rack): ListData
    {
        return new ListData($rack->name);
    }
}

/** Everything here the binder can fill without the template. */
#[Page('/fine/{rack}', view: 'fine/show'), Authorize('view', 'rack'), Authorize('view', 'other')]
final class Fillable
{
    public function __invoke(
        Rack $rack,
        Request $request,
        ?ListData $parent = null,
        ?Rack $other = null,
        int $page = 1,
    ): ListData {
        return new ListData($rack->name);
    }
}

it('refuses a required parameter only the path could fill, when the template has no segment for it', function (string $class, string $says) {
    expect(fn () => (new RouteRegistry())->register($class))
        ->toThrow(RoutingException::class, $says);
})->with([
    'a model' => [RackIndex::class, "requires Tinvar\\Tests\\Orm\\Fixtures\\Rack \$rack, which only the path could fill, and '/racks' has no {rack} segment"],
    'an int' => [UnitIndex::class, "'/units' has no {limit} segment"],
    'an enum' => [ShadeIndex::class, "'/shades' has no {shade} segment"],
    'a nullable scalar, which the binder does not default' => [NullableScalar::class, "'/notes' has no {filter} segment"],
    'a layout' => [FleetLayout::class, "'/fleet' has no {rack} segment"],
]);

it('accepts what the binder can fill without the template', function () {
    expect((new RouteRegistry())->register(Fillable::class)->template)->toBe('/fine/{rack}');
});
