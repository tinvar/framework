<?php

declare(strict_types=1);

/**
 * "Loaders are optional." A page or layout with none still carries its
 * #[Authorize], and the build rules -- which walk entry methods -- never
 * looked at it. A check with no subject, or naming a model nothing resolves,
 * registered and then failed every request with a 500.
 */

namespace Tinvar\Tests\Routing\LoaderlessChecks;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\BuildRules;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

#[Page('/sites/{site}/about', view: 'sites/about'), Authorize('view', 'site')]
final class SiteAbout {}

#[Page('/status', view: 'status'), Authorize('view')]
final class Status {}

#[Layout('/admin', view: 'admin/layout'), Authorize('view', 'site')]
final class AdminLayout {}

#[Page('/racks', view: 'racks/index'), Authorize('viewAny', Rack::class)]
final class RackIndex {}

it('reports a loader-less check that names no subject, or a model nothing resolves', function () {
    $violations = (new BuildRules())->check([SiteAbout::class, Status::class, AdminLayout::class]);

    expect(array_column($violations, 'rule'))->toBe([
        BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT,
        BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT,
        BuildRules::AUTHORIZE_MUST_NAME_A_SUBJECT,
    ])
        ->and(array_column($violations, 'where'))->toBe([SiteAbout::class, Status::class, AdminLayout::class]);
});

it('refuses to register them', function (string $class) {
    expect(fn () => (new RouteRegistry())->register($class))
        ->toThrow(RoutingException::class, 'has no loader');
})->with([
    'a model nothing resolves' => [SiteAbout::class],
    'no subject' => [Status::class],
    'a layout' => [AdminLayout::class],
]);

it('still serves a loader-less route whose check names a class', function () {
    $registry = new RouteRegistry();
    $registry->register(RackIndex::class);

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $runner = new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate());

    $chain = $registry->match('/racks') ?? throw new \LogicException('no match');

    expect($runner->run($chain, Request::get('/racks'))->page)->toBeNull();
});
