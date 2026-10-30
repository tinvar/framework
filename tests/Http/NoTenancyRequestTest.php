<?php

declare(strict_types=1);

/**
 * A #[TenantScoped] model read through a request, in an application that
 * never installed tinvar/tenancy, and in one that did (ADR 0056).
 *
 * With no tenancy registered no tenant's rows are read, whatever tenant the
 * application's own middleware puts in force; with tenancy and no tenant,
 * none are either. Each refusal is the one a request reading with no
 * tenant always got: a 404, `no_tenant`, never the rows of every tenant.
 */

namespace Tinvar\Tests\Http\NoTenancyRequest;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Support\Fixtures\Sources;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once __DIR__ . '/../Support/Fixtures/tenants.php';

final readonly class NamesData extends Data
{
    /** @param list<string> $names */
    public function __construct(public array $names) {}
}

/** Every script it can read. */
#[Page('/scripts', view: 'scripts', name: 'scripts'), PublicAccess(reason: 'a fixture page')]
final class ScriptsPage
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(): NamesData
    {
        return new NamesData(array_map(static fn (Script $s): string => $s->name, $this->orm->all(Script::class, 'name')));
    }
}

/** The tenant a request names in a header: a stand-in source. */
final class FromHeader
{
    public function tenantFor(Request $request): ?string
    {
        return $request->header('x-tenant');
    }
}

#[Page('/placed/scripts', view: 'scripts', name: 'placed'), PublicAccess(reason: 'a fixture page'), TenantFrom(FromHeader::class)]
final class PlacedScriptsPage
{
    public function __construct(private readonly Orm $orm) {}

    public function __invoke(): NamesData
    {
        return new NamesData(array_map(static fn (Script $s): string => $s->name, $this->orm->all(Script::class, 'name')));
    }
}

/** What an application's own session middleware does: puts a tenant in force. */
final class SessionTenant implements Middleware
{
    public static ?Orm $orm = null;

    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = $request->header('x-session-tenant');

        return $tenant === null || self::$orm === null ? $next($request) : self::$orm->actAs($tenant, static fn (): Response => $next($request));
    }
}

/** @param list<class-string> $pages */
function kernel(bool $tenancy, array $pages = [ScriptsPage::class]): Kernel
{
    $orm = new Orm(new TenantFixture()->connection, new IdentityMap('no-tenancy'), $tenancy ? Tenancy::flat() : null);
    SessionTenant::$orm = $orm;
    $routes = new RouteRegistry();
    $routes->registerAll($pages);
    $resolver = static fn (string $class): object => match ($class) {
        ScriptsPage::class => new ScriptsPage($orm),
        PlacedScriptsPage::class => new PlacedScriptsPage($orm),
        default => new $class(),
    };

    return new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: $resolver,
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [SessionTenant::class],
        tenants: $tenancy ? new Sources($resolver) : null,
    );
}

/** @return array{int, mixed} the status, and the names the page read or the error code */
function get(Kernel $kernel, string $path, array $headers = []): array
{
    $response = $kernel->handle(new Request('GET', $path, ['accept' => 'application/json', ...$headers]), static function (LoadedRoute $loaded): Response {
        $page = $loaded->page;

        return Response::make(200, json_encode($page instanceof NamesData ? $page->names : null, JSON_THROW_ON_ERROR));
    });
    $body = json_decode($response->body(), true);

    return [$response->status, $response->status === 200 ? $body : ($body['error']['code'] ?? null)];
}

describe('in an application that never installed tinvar/tenancy', function () {
    it('reads no tenant-scoped row with no tenant', function () {
        expect(get(kernel(false), '/scripts'))->toBe([404, 'no_tenant']);
    });

    // Its own middleware can still put a tenant in force: there is no
    // tenancy to vouch for it, so nothing is read as that tenant either.
    it('reads none as a tenant its own middleware put in force', function () {
        expect(get(kernel(false), '/scripts', ['x-session-tenant' => 'acme']))->toBe([404, 'no_tenant']);
    });

    it('refuses to be built with a page declaring a tenant source', function () {
        expect(fn () => kernel(false, [PlacedScriptsPage::class]))->toThrow(\InvalidArgumentException::class, 'no tenancy is registered');
    });
});

describe('with tenancy registered', function () {
    it('reads no tenant-scoped row with no tenant in force', function () {
        expect(get(kernel(true), '/scripts'))->toBe([404, 'no_tenant']);
    });

    it('reads the tenant in force alone', function () {
        expect(get(kernel(true), '/scripts', ['x-session-tenant' => 'acme']))->toBe([200, ['acme-script-1', 'acme-script-2']])
            ->and(get(kernel(true, [PlacedScriptsPage::class]), '/placed/scripts', ['x-tenant' => 'globex']))->toBe([200, ['globex-script-1', 'globex-script-2']]);
    });
});
