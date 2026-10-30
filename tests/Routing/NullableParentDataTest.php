<?php

declare(strict_types=1);

/**
 * A layout loader may return null. A page asking for that layout's data as
 * `?BannerData $banner = null` said it could do without, and still got a
 * RoutingException -- a 500 -- whenever the layout had nothing to say.
 */

namespace Tinvar\Tests\Routing\NullableParentData;

use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final readonly class BannerData extends Data
{
    public function __construct(public string $text) {}
}

final readonly class HomeData extends Data
{
    public function __construct(public string $banner) {}
}

/** A banner only while one is scheduled. */
#[Layout('/', view: 'layout')]
final class RootLayout
{
    public static ?string $banner = null;

    public function __invoke(): ?BannerData
    {
        return self::$banner === null ? null : new BannerData(self::$banner);
    }
}

#[Page('/', view: 'home', name: 'home')]
final class Home
{
    public function __invoke(?BannerData $banner = null): HomeData
    {
        return new HomeData($banner?->text ?? '(none)');
    }
}

#[Page('/nullable', view: 'nullable', name: 'nullable')]
final class NullableWithoutDefault
{
    public function __invoke(?BannerData $banner): HomeData
    {
        return new HomeData($banner?->text ?? '(none)');
    }
}

#[Page('/defaulted', view: 'defaulted', name: 'defaulted')]
final class Defaulted
{
    public function __invoke(BannerData $banner = new BannerData('fallback')): HomeData
    {
        return new HomeData($banner->text);
    }
}

#[Page('/required', view: 'required', name: 'required')]
final class Required
{
    public function __invoke(BannerData $banner): HomeData
    {
        return new HomeData($banner->text);
    }
}

function banner(string $path): string
{
    $registry = new RouteRegistry();
    $registry->registerAll([RootLayout::class, Home::class, NullableWithoutDefault::class, Defaulted::class, Required::class]);

    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $runner = new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate());

    $page = $runner->run($registry->match($path) ?? throw new \LogicException('no match'), Request::get($path))->page;

    return $page instanceof HomeData ? $page->banner : throw new \LogicException('no page data');
}

afterEach(function () {
    RootLayout::$banner = null;
});

it('passes the parent data when the layout returned it', function () {
    RootLayout::$banner = 'Maintenance at 9';

    expect(banner('/'))->toBe('Maintenance at 9')
        ->and(banner('/required'))->toBe('Maintenance at 9');
});

it('passes null or the default when the layout returned nothing and the parameter allows it', function () {
    expect(banner('/'))->toBe('(none)')
        ->and(banner('/nullable'))->toBe('(none)')
        ->and(banner('/defaulted'))->toBe('fallback');
});

it('still refuses a required parameter the layout did not fill', function () {
    expect(fn () => banner('/required'))->toThrow(RoutingException::class, 'which no layout above it returned');
});
