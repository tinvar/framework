<?php

declare(strict_types=1);

/**
 * #[Authorize] and #[Middleware] target methods, and the build rules counted a
 * check written on a page's, layout's or query's entry method. The registries
 * read only the class, so such a check passed the build and never ran.
 */

namespace Tinvar\Tests\Auth\MethodLevel;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\BatchQuery;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

final class RequireMfa implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }
}

final readonly class NameData extends Data
{
    public function __construct(public string $name) {}
}

#[Page('/method-level/{script}', view: 'method-level/show')]
final class MethodCheckedPage
{
    #[Authorize('view', 'script')]
    public function __invoke(Script $script): NameData
    {
        return new NameData($script->name);
    }
}

#[Layout('/method-layout/{script}', view: 'method-layout/layout')]
final class MethodCheckedLayout
{
    #[Authorize('manage', 'script')]
    public function __invoke(Script $script): NameData
    {
        return new NameData('layout:' . $script->name);
    }
}

#[Page('/method-layout/{script}/page', view: 'method-layout/page'), Authorize('view', 'script')]
final class UnderMethodCheckedLayout
{
    public function __invoke(Script $script): ?NameData
    {
        return null;
    }
}

#[Page('/method-middleware/{script}', view: 'method-middleware/show'), Authorize('viewAny', Script::class)]
final class MethodMiddlewarePage
{
    #[UseMiddleware(RequireMfa::class), Authorize('update', 'script')]
    public function __invoke(Script $script): ?NameData
    {
        return null;
    }
}

#[Query(name: 'methodLevel.secret')]
final class MethodCheckedQuery
{
    #[Authorize('view', 'script')]
    public function __invoke(Script $script): NameData
    {
        return new NameData('secret of ' . $script->name);
    }
}

#[Query(name: 'methodLevel.batch', args: ['script' => Script::class])]
final class MethodCheckedBatch implements BatchQuery
{
    #[Authorize('view', 'script')]
    public function loadMany(array $calls): array
    {
        return array_map(static fn (array $call): string => 'batch:' . $call['script']->name, $calls);
    }
}

/**
 * A BatchQuery with an __invoke the runner never calls. Its loadMany() is
 * what runs, so loadMany() is what the build rules have to examine.
 */
#[Query(name: 'methodLevel.twoBodies', args: ['script' => Script::class])]
final class BatchWithInvoke implements BatchQuery
{
    public function __invoke(): string
    {
        return 'never called';
    }

    public function loadMany(array $calls): array
    {
        return array_map(static fn (array $call): string => $call['script']->name, $calls);
    }
}

function scoped(\Closure $work): mixed
{
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('tenant-test'), tenants: Tenancy::flat());

    return $orm->actAs('acme', static fn (): mixed => $work($orm, $fixture->scripts['acme'][0]));
}

function load(RouteRegistry $routes, string $path, Orm $orm, Gate $gate): LoadedRoute
{
    $chain = $routes->match($path) ?? throw new \LogicException("no route for {$path}");

    return (new LoaderRunner($orm, static fn (string $class): object => new $class(), $gate))
        ->run($chain, new Request('GET', $path));
}

describe('a check on a page or layout loader runs', function () {
    it('refuses a page whose only check sits on __invoke', function () {
        $routes = new RouteRegistry();
        $routes->register(MethodCheckedPage::class);

        scoped(function (Orm $orm, string $id) use ($routes): void {
            expect(fn () => load($routes, "/method-level/{$id}", $orm, new DenyGate()))
                ->toThrow(ForbiddenException::class);
        });
    });

    it('refuses the pages under a layout whose only check sits on __invoke', function () {
        $routes = new RouteRegistry();
        $routes->registerAll([MethodCheckedLayout::class, UnderMethodCheckedLayout::class]);

        scoped(function (Orm $orm, string $id) use ($routes): void {
            $gate = new AllowAllGate();
            load($routes, "/method-layout/{$id}/page", $orm, $gate);

            expect(array_column($gate->checks, 'ability'))->toBe(['manage', 'view']);
        });
    });

    it('runs the class check first, then the method check', function () {
        $route = (new RouteRegistry())->register(MethodMiddlewarePage::class);

        expect(array_map(static fn (Authorize $c): string => $c->ability, $route->checks))
            ->toBe(['viewAny', 'update']);
    });

    it('puts #[Middleware] written on __invoke into the chain', function () {
        $routes = new RouteRegistry();
        $routes->register(MethodMiddlewarePage::class);

        expect($routes->match('/method-middleware/x')?->middleware())->toBe([RequireMfa::class]);
    });
});

describe('a check on a query entry method runs', function () {
    it('refuses a query whose only check sits on __invoke', function () {
        $registry = new QueryRegistry();
        $registry->register(MethodCheckedQuery::class);

        scoped(function (Orm $orm, string $id) use ($registry): void {
            $out = (new QueryRunner($registry, $orm, new DenyGate()))
                ->run([['id' => 1, 'name' => 'methodLevel.secret', 'args' => ['script' => $id]]], ['methodLevel.secret']);

            expect($out[0])->not->toHaveKey('value')
                ->and($out[0]['error']['code'] ?? null)->toBe('forbidden');
        });
    });

    it('refuses a batch query whose only check sits on loadMany', function () {
        $registry = new QueryRegistry();
        $registry->register(MethodCheckedBatch::class);

        scoped(function (Orm $orm, string $id) use ($registry): void {
            $out = (new QueryRunner($registry, $orm, new DenyGate()))
                ->run([['id' => 1, 'name' => 'methodLevel.batch', 'args' => ['script' => $id]]], ['methodLevel.batch']);

            expect($out[0])->not->toHaveKey('value')
                ->and($out[0]['error']['code'] ?? null)->toBe('forbidden');
        });
    });

    it('examines loadMany, not __invoke, on a batch query that has both', function () {
        $violations = (new BuildRules())->check([BatchWithInvoke::class]);

        expect(array_column($violations, 'rule'))->toContain(BuildRules::AUTHORIZE_EVERY_MODEL_INPUT);
        expect(fn () => (new QueryRegistry())->register(BatchWithInvoke::class))
            ->toThrow(\InvalidArgumentException::class, 'build rules');
    });
});
