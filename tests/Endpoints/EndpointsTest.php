<?php

declare(strict_types=1);

/**
 * #[Get], #[Post] and #[Endpoint] routes (ADR 0067): held to the build rules
 * when they register, and run through an action's pipeline -- the global
 * stack, CSRF on a method that changes something, authentication, the
 * tenant, model binding, every #[Authorize], validation -- before the body.
 */

namespace Tinvar\Tests\Endpoints;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Endpoint;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Post;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Orm\Fixtures\Unit;
use Tinvar\Attributes\Webhook;
use Tinvar\Support\Signer;
use Tinvar\Validation\FormRequest;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Webhooks\DerivedSecrets;
use Tinvar\Webhooks\WebhookCall;
use Tinvar\Webhooks\WebhookEndpoint;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

final class Seen
{
    /** @var list<string> */
    public static array $bodies = [];

    public static int $sessions = 0;

    /** @var list<class-string<\Throwable>> */
    public static array $failures = [];
}

final class RackPolicy
{
    public function view(?Authenticatable $user, Rack $rack): bool
    {
        return $user instanceof User && \in_array($rack->name, $user->ownedRackNames, true);
    }

    public function update(?Authenticatable $user, Rack $rack): bool
    {
        return $this->view($user, $rack);
    }
}

final class OpenPolicy
{
    public function view(?Authenticatable $user, object $subject): bool
    {
        return true;
    }
}

/** Denies a unit whose name ends in -02: the third model of a path. */
final class UnitPolicy
{
    public function view(?Authenticatable $user, Unit $unit): bool
    {
        return !str_ends_with($unit->name, '-02');
    }
}

final readonly class RackData extends Data
{
    public function __construct(public string $id, public string $name, public int $heightU) {}
}

final readonly class LeakyData extends Data
{
    /** @param array<string, mixed> $extra */
    public function __construct(public array $extra) {}
}

final readonly class ResizeRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(48)]
        public int $heightU,
    ) {}
}

final readonly class MoveRequest extends FormRequest
{
    public function __construct(public Rack $destination) {}
}

final class RackEndpoints
{
    #[Get('/api/racks/{rack}', name: 'racks.show'), Authorize('view', 'rack')]
    public function show(Rack $rack): RackData
    {
        Seen::$bodies[] = 'show';

        return new RackData($rack->id, $rack->name, $rack->heightU);
    }

    #[Post('/api/racks/{rack}/resize', name: 'racks.resize'), Authorize('update', 'rack')]
    public function resize(Rack $rack, ResizeRequest $request): RackData
    {
        Seen::$bodies[] = 'resize';

        return new RackData($rack->id, $rack->name, $request->heightU);
    }

    #[Endpoint('/api/racks/{rack}/units/{unit}', methods: ['PUT', 'DELETE'], name: 'racks.units.update'),
        Authorize('update', 'rack'), Authorize('view', 'unit'), Authorize('update', 'destination')]
    public function moveUnit(Rack $rack, Unit $unit, MoveRequest $request): void
    {
        Seen::$bodies[] = "move {$unit->name} to {$request->destination->name}";
    }

    #[Get('/api/racks/{rack}/label.txt', name: 'racks.label'), Authorize('view', 'rack')]
    public function label(Rack $rack): Response
    {
        return Response::make(200, $rack->label, ['content-type' => 'text/plain']);
    }

    #[Get('/api/racks/{rack}/leak', name: 'racks.leak'), Authorize('view', 'rack')]
    public function leak(Rack $rack): LeakyData
    {
        return new LeakyData(['rack' => $rack]);
    }
}

final class WritingEndpoints
{
    public function __construct(private readonly Orm $orm) {}

    #[Get('/api/racks/{rack}/write', name: 'racks.write'), Authorize('view', 'rack')]
    public function writeOnGet(Rack $rack, Request $request): void
    {
        $rack->name = 'renamed';
        $this->orm->save($rack);
    }

    #[Get('/api/racks/{rack}/export', name: 'racks.export'), Authorize('view', 'rack')]
    public function writeWhileStreaming(Rack $rack): Response
    {
        $orm = $this->orm;

        return Response::stream(static function (\Closure $sink) use ($orm, $rack): void {
            $sink($rack->name);
            $rack->name = 'renamed';
            $orm->save($rack);
        }, 200, ['content-type' => 'text/csv', 'cache-control' => 'public, max-age=600']);
    }
}

/** GET and POST at one path: each request is judged by its own method, never the declaration's. */
final class MixedEndpoints
{
    public function __construct(private readonly Orm $orm) {}

    #[Endpoint('/api/racks/{rack}/rename', methods: ['GET', 'POST'], name: 'racks.rename'), Authorize('update', 'rack')]
    public function rename(Rack $rack): void
    {
        Seen::$bodies[] = 'rename';
        $rack->name = 'renamed';
        $this->orm->save($rack);
    }

    #[Endpoint('/api/racks/{rack}/sheet', methods: ['GET', 'POST'], name: 'racks.sheet'), Authorize('update', 'rack')]
    public function sheet(Rack $rack): Response
    {
        $orm = $this->orm;

        return Response::stream(static function (\Closure $sink) use ($orm, $rack): void {
            $rack->name = 'renamed';
            $orm->save($rack);
            $sink('done');
        });
    }
}

final class CachedEndpoints
{
    #[Get('/api/catalog.json', name: 'catalog'), PublicAccess, NoAuthorization(reason: 'the catalog is public')]
    public function catalog(): Response
    {
        return Response::make(200, '{}', ['content-type' => 'application/json', 'cache-control' => 'public, max-age=600']);
    }
}

#[Webhook('/hooks/carrier')]
final class CarrierHook
{
    public function __invoke(WebhookCall $call): void {}
}

final class AtTheWebhook
{
    #[Post('/hooks/{name}'), PublicAccess(reason: 'test'), NoAuthorization(reason: 'test')]
    public function hook(string $name): void {}
}

final class PublicEndpoints
{
    #[Get('/api/status', name: 'status'), PublicAccess]
    public function status(): RackData
    {
        return new RackData('-', 'ok', 0);
    }

    #[Post('/api/ping', name: 'ping'), PublicAccess(reason: 'an uptime checker pings it, and it writes nothing of anyone\'s'), NoAuthorization(reason: 'it reads nothing')]
    public function ping(): void {}
}

/** Signs in whoever a cookie names: a session, as the application's global stack opens one. */
final class CookieSession implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        Seen::$sessions++;
        if ($request->cookie('sid') === 'owner') {
            $this->auth->login(new User('owner', ownedRackNames: ['R-01']));
        }

        return $next($request);
    }
}

/** Runs the request as one tenant, as an application's tenant resolution would. */
final class InTenant implements Middleware
{
    public function __construct(private readonly Orm $orm, private readonly string $tenant) {}

    public function handle(Request $request, \Closure $next): Response
    {
        return $this->orm->actAs($this->tenant, static fn (): Response => $next($request));
    }
}

/** Signs in whoever a bearer token names: an API's own authentication. */
final class BearerToken implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->header('authorization') === 'Bearer scanner-token') {
            $this->auth->login(new User('scanner', ownedRackNames: ['R-01']));
        }

        return $next($request);
    }
}

#[WithoutSession(reason: 'the warehouse scanner sends a bearer token')]
final class ScannerEndpoints
{
    #[Post('/api/scanner/racks/{rack}', name: 'scanner.scan'), UseMiddleware(BearerToken::class), Authorize('update', 'rack')]
    public function scan(Rack $rack): RackData
    {
        Seen::$bodies[] = 'scan';

        return new RackData($rack->id, $rack->name, $rack->heightU);
    }
}

/**
 * A streamed body that reads what its request put in place: the caller,
 * the tenant, the read-only guard, a model by its id.
 */
final class StreamProbe
{
    /** @var array<string, mixed> */
    public static array $seen = [];

    public static ?AuthContext $auth = null;

    public static ?Orm $orm = null;

    #[Get('/api/scripts/{script}/stream', name: 'scripts.stream'), Authorize('view', 'script')]
    public function stream(Script $script): Response
    {
        $auth = self::$auth;
        $orm = self::$orm;
        $id = $script->id;

        return Response::stream(static function (\Closure $sink) use ($auth, $orm, $id): void {
            StreamProbe::$seen = [
                'user' => $auth?->id(),
                'tenant' => $orm?->tenant(),
                'readOnly' => $orm?->isReadOnly(),
                'found' => $orm?->find(Script::class, $id)?->id,
            ];
            $sink('streamed');
        });
    }
}

final class ScriptEndpoints
{
    #[Get('/api/scripts/{script}', name: 'scripts.show'), Authorize('view', 'script')]
    public function show(Script $script): RackData
    {
        return new RackData($script->id, $script->name, 0);
    }
}

#[Page('/racks/{rack}', view: 'racks/show'), Authorize('view', 'rack')]
final class RackPage
{
    public function __invoke(Rack $rack): RackData
    {
        return new RackData($rack->id, $rack->name, $rack->heightU);
    }
}

// --- refused at registration -------------------------------------------------

final class UnguardedThirdModel
{
    #[Get('/sites/{site}/racks/{rack}/units/{unit}'), Authorize('view', 'site'), Authorize('view', 'rack')]
    public function show(Site $site, Rack $rack, Unit $unit): RackData
    {
        return new RackData('', '', 0);
    }
}

#[Authorize('view', Rack::class)]
final class ClassLevelOnly
{
    #[Get('/class-level/{rack}')]
    public function show(Rack $rack): RackData
    {
        return new RackData('', '', 0);
    }
}

final class Reserved
{
    #[Get('/_admin/things'), NoAuthorization(reason: 'test')]
    public function show(): RackData
    {
        return new RackData('', '', 0);
    }
}

final class UntakenParameter
{
    #[Get('/things/{thing}'), NoAuthorization(reason: 'test')]
    public function show(): RackData
    {
        return new RackData('', '', 0);
    }
}

final class ModelNotInPath
{
    #[Get('/racks-by-query'), Authorize('view', 'rack')]
    public function show(Rack $rack): RackData
    {
        return new RackData('', '', 0);
    }
}

final class ReturnsAModel
{
    #[Get('/racks-raw/{rack}'), Authorize('view', 'rack')]
    public function show(Rack $rack): Rack
    {
        return $rack;
    }
}

final class ReturnsAnArray
{
    /** @return array<string, string> */
    #[Get('/arrays'), NoAuthorization(reason: 'test')]
    public function show(): array
    {
        return [];
    }
}

final class PublicPostWithoutReason
{
    #[Post('/open-door'), PublicAccess, NoAuthorization(reason: 'test')]
    public function open(): void {}
}

final class TwoAtOnePath
{
    #[Get('/twice/{a}'), NoAuthorization(reason: 'test')]
    public function first(string $a): void {}

    #[Endpoint('/twice/{b}', methods: ['GET', 'POST']), NoAuthorization(reason: 'test')]
    public function second(string $b): void {}
}

final class ShadowsAPage
{
    #[Get('/racks/{id}/'), NoAuthorization(reason: 'test')]
    public function show(string $id): void {}
}

/** @param list<class-string> $endpoints */
function kernel(array $endpoints, ?Orm $orm = null, ?string $tenant = null, ?WebhookEndpoint $webhooks = null, ?AuthContext $auth = null): Kernel
{
    $orm ??= new Orm(new Fixture(racks: 2, unitsPerRack: 2)->connection, new IdentityMap('test'));
    $auth ??= new AuthContext();
    $routes = new RouteRegistry();
    $routes->register(RackPage::class);
    $registry = new EndpointRegistry();
    $registry->registerAll($endpoints);

    return new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: (new PolicyRegistry())
            ->register(Rack::class, RackPolicy::class)
            ->register(Unit::class, UnitPolicy::class)
            ->register(Script::class, OpenPolicy::class),
        resolver: static fn (string $class): object => match ($class) {
            CookieSession::class => new CookieSession($auth),
            BearerToken::class => new BearerToken($auth),
            InTenant::class => new InTenant($orm, (string) $tenant),
            WritingEndpoints::class => new WritingEndpoints($orm),
            MixedEndpoints::class => new MixedEndpoints($orm),
            default => new $class(),
        },
        allowedQueries: static fn (Request $r): array => [],
        log: static function (\Throwable $e): void {
            Seen::$failures[] = $e::class;
        },
        globalMiddleware: $tenant === null ? [CookieSession::class] : [CookieSession::class, InTenant::class],
        tenants: null,
        webhooks: $webhooks,
        endpoints: $registry,
    );
}

/**
 * @param array<string, string> $headers
 * @param array<string, string> $query
 */
function call(Kernel $kernel, string $method, string $path, array $headers = [], string $body = '', bool $signedIn = true, bool $csrf = true, array $query = []): Response
{
    $cookies = [];
    if ($signedIn) {
        $cookies['sid'] = 'owner';
    }
    if ($csrf) {
        $cookies[VerifyCsrfToken::COOKIE] = 't';
        $headers['x-xsrf-token'] = 't';
    }
    if ($body !== '') {
        $headers['content-type'] = 'application/json';
    }

    return $kernel->handle(
        new Request($method, $path, $headers, $query, cookies: $cookies),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
        $body,
    );
}

/** @return array{Fixture, Kernel} */
function shop(): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 2);

    return [$fixture, kernel([RackEndpoints::class, PublicEndpoints::class, ScannerEndpoints::class, WritingEndpoints::class, CachedEndpoints::class, MixedEndpoints::class], new Orm($fixture->connection, new IdentityMap('test')))];
}

beforeEach(function () {
    Seen::$bodies = [];
    Seen::$sessions = 0;
    Seen::$failures = [];
});

describe('registration holds an endpoint to the build rules', function () {
    it('refuses a model input no #[Authorize] names, the third of three included', function () {
        expect(fn () => new EndpointRegistry()->register(UnguardedThirdModel::class))
            ->toThrow(EndpointException::class, '$unit takes');

        $violations = (new BuildRules())->check([UnguardedThirdModel::class]);
        expect(array_column($violations, 'rule'))->toBe([BuildRules::AUTHORIZE_EVERY_MODEL_INPUT])
            ->and($violations[0]->where)->toContain('$unit');
    });

    it('counts no class-level #[Authorize] as covering an endpoint', function () {
        expect(fn () => new EndpointRegistry()->register(ClassLevelOnly::class))
            ->toThrow(EndpointException::class, 'class-level');
        expect(array_column((new BuildRules())->check([ClassLevelOnly::class]), 'rule'))
            ->toContain(BuildRules::AUTHORIZE_EVERY_MODEL_INPUT);
    });

    it('refuses what it could not hold', function (string $class, string $message) {
        expect(fn () => new EndpointRegistry()->register($class))->toThrow(EndpointException::class, $message);
    })->with([
        'a path under /_' => [Reserved::class, "framework's own"],
        'a path parameter nothing takes' => [UntakenParameter::class, 'takes no $thing'],
        'a model not in the path' => [ModelNotInPath::class, 'the path has no {rack}'],
        'a model returned' => [ReturnsAModel::class, 'returns ' . Rack::class],
        'an array returned' => [ReturnsAnArray::class, 'returns array'],
        'a public POST with no reason' => [PublicPostWithoutReason::class, 'gives no reason'],
        'two endpoints answering one method at one shape' => [TwoAtOnePath::class, 'answer GET'],
    ]);

    it('refuses HEAD, OPTIONS and anything but the five methods in a declaration', function (string $method) {
        expect(fn () => new Endpoint('/x', methods: [$method]))->toThrow(\InvalidArgumentException::class);
    })->with(['HEAD', 'OPTIONS', 'TRACE', 'CONNECT']);

    it('refuses an endpoint at a webhook\'s path, which the webhook would answer first', function () {
        $orm = new Orm(new Fixture(racks: 1, unitsPerRack: 1)->connection, new IdentityMap('test'));
        $webhooks = new WebhookEndpoint(
            [CarrierHook::class],
            $orm,
            static fn (string $class): object => new $class(),
            new DerivedSecrets(new Signer('webhook-key-of-32-bytes-at-least!', 'webhook')),
            new ArrayNonceStore(),
        );

        expect(fn () => kernel([AtTheWebhook::class], $orm, webhooks: $webhooks))->toThrow(\InvalidArgumentException::class, "which is a webhook's");
    });

    it('says a webhook\'s delivery opens no session, so an application issues its sender no cookie', function () {
        $orm = new Orm(new Fixture(racks: 1, unitsPerRack: 1)->connection, new IdentityMap('test'));
        $webhooks = new WebhookEndpoint(
            [CarrierHook::class],
            $orm,
            static fn (string $class): object => new $class(),
            new DerivedSecrets(new Signer('webhook-key-of-32-bytes-at-least!', 'webhook')),
            new ArrayNonceStore(),
        );
        $kernel = kernel([], $orm, webhooks: $webhooks);

        expect($kernel->opensNoSession(new Request('POST', '/hooks/carrier')))->toBeTrue()
            ->and($kernel->opensNoSession(new Request('GET', '/racks/r-1')))->toBeFalse();
    });

    it('refuses an endpoint whose path a page also matches, in the Kernel and in `tinvar check`', function () {
        expect(fn () => kernel([ShadowsAPage::class]))->toThrow(EndpointException::class, 'some path matches both');

        $routes = new RouteRegistry();
        $routes->register(RackPage::class);
        expect(array_column(EndpointRegistry::check([ShadowsAPage::class, Reserved::class], $routes), 'rule'))
            ->toBe([EndpointRegistry::ENDPOINTS_MUST_COMPILE, EndpointRegistry::PATHS_MUST_NOT_OVERLAP_PAGES]);
    });
});

describe('a GET endpoint', function () {
    it('answers its Data as JSON, for a model the caller may see', function () {
        [$fixture, $kernel] = shop();
        $response = call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}", csrf: false);

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true))->toBe(['id' => $fixture->ids['racks'][0], 'name' => 'R-01', 'heightU' => 42])
            ->and($response->header('content-type'))->toBe('application/json')
            ->and($response->header('cache-control'))->toBe('private, no-store')
            ->and($response->header('x-content-type-options'))->toBe('nosniff')
            ->and($response->header('vary'))->toContain('Cookie');
    });

    it('is a 403 for a model the caller may not see, and a 404 for one that is not there', function () {
        [$fixture, $kernel] = shop();

        expect(call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][1]}", csrf: false)->status)->toBe(403)
            ->and(call($kernel, 'GET', '/api/racks/no-such-rack', csrf: false)->status)->toBe(404)
            ->and(Seen::$bodies)->toBe([]);
    });

    it('is a 401 for nobody signed in, and answers a #[PublicAccess] one', function () {
        [$fixture, $kernel] = shop();

        expect(call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}", signedIn: false, csrf: false)->status)->toBe(401)
            ->and(call($kernel, 'GET', '/api/status', signedIn: false, csrf: false)->status)->toBe(200);
    });

    it('answers HEAD with its headers and no body', function () {
        [$fixture, $kernel] = shop();
        $response = call($kernel, 'HEAD', "/api/racks/{$fixture->ids['racks'][0]}/label.txt", csrf: false);

        expect($response->status)->toBe(200)
            ->and($response->body())->toBe('')
            ->and($response->header('content-type'))->toBe('text/plain');
    });

    it('is a 405 naming GET and HEAD for any other method, OPTIONS and an override included', function (string $method, array $headers) {
        [$fixture, $kernel] = shop();
        $response = call($kernel, $method, "/api/racks/{$fixture->ids['racks'][0]}", $headers);

        expect($response->status)->toBe(405)
            ->and($response->header('allow'))->toBe('GET, HEAD')
            ->and(Seen::$bodies)->toBe([]);
    })->with([
        'OPTIONS' => ['OPTIONS', []],
        'DELETE' => ['DELETE', []],
        'POST saying it is a GET' => ['POST', ['x-http-method-override' => 'GET']],
    ]);

    it('runs its body read-only, so a GET writes nothing', function () {
        [$fixture, $kernel] = shop();

        expect(call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}/write", csrf: false)->status)->toBe(500)
            ->and(Seen::$failures)->toBe([ReadOnlyViolation::class]);
    });

    it('streams read-only too: a producer that writes is refused', function () {
        [$fixture, $kernel] = shop();
        $response = call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}/export", csrf: false);

        expect($response->status)->toBe(200)
            ->and(fn () => $response->body())->toThrow(ReadOnlyViolation::class);
    });

    it('keeps an authenticated answer out of shared caches, whatever it says; a public one says for itself', function () {
        [$fixture, $kernel] = shop();

        expect(call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}/export", csrf: false)->header('cache-control'))->toBe('private, no-store')
            ->and(call($kernel, 'GET', '/api/catalog.json', signedIn: false, csrf: false)->header('cache-control'))->toBe('public, max-age=600');
    });

    it('sends nothing but Data through the encoder: a model inside one is refused', function () {
        [$fixture, $kernel] = shop();
        $response = call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}/leak", csrf: false);

        expect($response->status)->toBe(500)
            ->and($response->body())->not->toContain('R-01')
            ->and(Seen::$failures)->toBe([DataBoundaryException::class]);
    });

    it('never matches a path under /_, whatever its parameters would take', function () {
        $kernel = kernel([TwoParameters::class]);

        expect(call($kernel, 'GET', '/_nothing/here/x', csrf: false)->status)->toBe(404)
            ->and(call($kernel, 'GET', '/plain/here/x', csrf: false)->status)->toBe(204);
    });
});

describe('an endpoint that changes something', function () {
    it('needs the CSRF token, as an action does', function () {
        [$fixture, $kernel] = shop();
        $path = "/api/racks/{$fixture->ids['racks'][0]}/resize";

        expect(call($kernel, 'POST', $path, body: '{"heightU":24}', csrf: false)->status)->toBe(419)
            ->and(Seen::$bodies)->toBe([]);

        $response = call($kernel, 'POST', $path, body: '{"heightU":24}');
        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['heightU'])->toBe(24);
    });

    it('validates its form request, after its checks', function () {
        [$fixture, $kernel] = shop();

        $invalid = call($kernel, 'POST', "/api/racks/{$fixture->ids['racks'][0]}/resize", body: '{"heightU":99}');
        expect($invalid->status)->toBe(422)
            ->and(json_decode($invalid->body(), true)['errors'])->toHaveKey('heightU');

        // Not someone else's rack, however valid the input.
        expect(call($kernel, 'POST', "/api/racks/{$fixture->ids['racks'][1]}/resize", body: '{"heightU":99}')->status)->toBe(403)
            ->and(Seen::$bodies)->toBe([]);
    });

    it('checks every model it takes: the path\'s second, and a form request\'s', function () {
        [$fixture, $kernel] = shop();
        [$rack1, $rack2] = $fixture->ids['racks'];
        [$unit1, $unit2] = $fixture->ids['units'];

        // The second unit is denied by its own check.
        expect(call($kernel, 'PUT', "/api/racks/{$rack1}/units/{$unit2}", body: json_encode(['destination' => $rack1]))->status)->toBe(403);
        // A destination the caller may not update.
        expect(call($kernel, 'PUT', "/api/racks/{$rack1}/units/{$unit1}", body: json_encode(['destination' => $rack2]))->status)->toBe(403);
        expect(Seen::$bodies)->toBe([]);

        $response = call($kernel, 'DELETE', "/api/racks/{$rack1}/units/{$unit1}", body: json_encode(['destination' => $rack1]));
        expect($response->status)->toBe(204)
            ->and(Seen::$bodies)->toBe(['move sw-01-01 to R-01']);
    });

    it('lets a public one through with its reason, behind CSRF still', function () {
        [, $kernel] = shop();

        expect(call($kernel, 'POST', '/api/ping', signedIn: false, csrf: false)->status)->toBe(419)
            ->and(call($kernel, 'POST', '/api/ping', signedIn: false)->status)->toBe(204);
    });
});

describe('an endpoint answering GET and POST', function () {
    it('runs a GET read-only, though it declares a POST too', function () {
        [$fixture, $kernel] = shop();

        expect(call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}/rename", csrf: false)->status)->toBe(500)
            ->and(Seen::$failures)->toBe([ReadOnlyViolation::class]);

        $streamed = call($kernel, 'GET', "/api/racks/{$fixture->ids['racks'][0]}/sheet", csrf: false);
        expect(fn () => $streamed->body())->toThrow(ReadOnlyViolation::class);
    });

    it('holds a POST to the CSRF token, and lets it write', function () {
        [$fixture, $kernel] = shop();
        $path = "/api/racks/{$fixture->ids['racks'][0]}/rename";

        expect(call($kernel, 'POST', $path, csrf: false)->status)->toBe(419)
            ->and(Seen::$bodies)->toBe([])
            ->and(call($kernel, 'POST', $path)->status)->toBe(204)
            ->and(Seen::$bodies)->toBe(['rename']);

        // A fresh rack, since the policy goes by name, which that renamed.
        [$fixture, $kernel] = shop();
        expect(call($kernel, 'POST', "/api/racks/{$fixture->ids['racks'][0]}/sheet")->body())->toBe('done');
    });
});

describe('a #[WithoutSession] endpoint', function () {
    it('runs no global stack, takes no token, and is a 401 unless its own middleware signs someone in', function () {
        [$fixture, $kernel] = shop();
        $path = "/api/scanner/racks/{$fixture->ids['racks'][0]}";

        // The session cookie signs nobody in here, and no CSRF token is asked for.
        expect(call($kernel, 'POST', $path, csrf: false)->status)->toBe(401)
            ->and(Seen::$sessions)->toBe(0);

        $response = call($kernel, 'POST', $path, ['authorization' => 'Bearer scanner-token'], signedIn: false, csrf: false);
        expect($response->status)->toBe(200)
            ->and(Seen::$bodies)->toBe(['scan'])
            ->and(Seen::$sessions)->toBe(0)
            ->and($response->header('vary'))->toBeNull();
    });

    it('refuses a request someone was signed in for before its stack ran', function () {
        [$fixture, $kernel] = shop();
        $kernel->auth->login(new User('owner', ownedRackNames: ['R-01']));

        expect(call($kernel, 'POST', "/api/scanner/racks/{$fixture->ids['racks'][0]}", csrf: false)->status)->toBe(500)
            ->and(Seen::$failures)->toBe([\LogicException::class])
            ->and(Seen::$bodies)->toBe([]);
    });

    it('still holds the caller its middleware signed in to every check', function () {
        [$fixture, $kernel] = shop();

        expect(call($kernel, 'POST', "/api/scanner/racks/{$fixture->ids['racks'][1]}", ['authorization' => 'Bearer scanner-token'], signedIn: false, csrf: false)->status)->toBe(403);
    });
});

it('produces a streamed body after handle() as the request it answers: its caller, its tenant, read-only -- and leaves nothing behind', function () {
    $tenants = new TenantFixture();
    $orm = new Orm($tenants->connection, new IdentityMap('test'), Tenancy::flat());
    $auth = new AuthContext();
    StreamProbe::$auth = $auth;
    StreamProbe::$orm = $orm;
    StreamProbe::$seen = [];
    $kernel = kernel([StreamProbe::class], $orm, 'acme', auth: $auth);
    $script = $tenants->scripts['acme'][0];
    $before = $orm->tenant();

    $response = call($kernel, 'GET', "/api/scripts/{$script}/stream", csrf: false);

    // handle() has returned, and reset the scope: the body is sent now.
    expect($response->status)->toBe(200)
        ->and(StreamProbe::$seen)->toBe([])
        ->and($response->body())->toBe('streamed')
        ->and(StreamProbe::$seen)->toBe(['user' => 'owner', 'tenant' => 'acme', 'readOnly' => true, 'found' => $script])
        // And afterwards, nothing of it is left for the next request.
        ->and($auth->id())->toBeNull()
        ->and($orm->tenant())->toBe($before)
        ->and($orm->isReadOnly())->toBeFalse();
});

it('resolves a model in the request\'s tenant: another tenant\'s is a 404', function () {
    $tenants = new TenantFixture();
    $kernel = kernel([ScriptEndpoints::class], new Orm($tenants->connection, new IdentityMap('test'), Tenancy::flat()), 'acme');

    expect(call($kernel, 'GET', "/api/scripts/{$tenants->scripts['acme'][0]}", csrf: false)->status)->toBe(200)
        ->and(call($kernel, 'GET', "/api/scripts/{$tenants->scripts['globex'][0]}", csrf: false)->status)->toBe(404);
});

final class TwoParameters
{
    #[Get('/{a}/{b}/x'), NoAuthorization(reason: 'test')]
    public function show(string $a, string $b): void {}
}

/** Redirects from methods a 301 or 302 would repeat at the target. */
final class RedirectingEndpoints
{
    #[Endpoint('/api/racks/{rack}/archive', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], name: 'racks.archive'), Authorize('update', 'rack')]
    public function archive(Rack $rack, Request $request): Response
    {
        return Response::redirect("/racks/{$rack->id}", $request->header('x-moved') === 'permanently' ? 301 : 302);
    }

    #[Endpoint('/api/racks/{rack}/relocate', methods: ['PUT'], name: 'racks.relocate'), Authorize('update', 'rack')]
    public function relocate(Rack $rack): Response
    {
        return Response::redirect("/api/racks/{$rack->id}/elsewhere", 307);
    }
}

describe('a redirect from an endpoint', function () {
    it('is a 303 after PUT, PATCH and DELETE, so the client follows it with a GET', function (string $method, array $headers) {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $kernel = kernel([RedirectingEndpoints::class], new Orm($fixture->connection, new IdentityMap('test')));
        $rack = $fixture->ids['racks'][0];

        $response = call($kernel, $method, "/api/racks/{$rack}/archive", $headers);

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe("/racks/{$rack}");
    })->with([
        'PUT, 302' => ['PUT', []],
        'PATCH, 302' => ['PATCH', []],
        'DELETE, 302' => ['DELETE', []],
        'DELETE, 301' => ['DELETE', ['x-moved' => 'permanently']],
    ]);

    it('is left as sent after a POST, which a 302 already turns into a GET', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $kernel = kernel([RedirectingEndpoints::class], new Orm($fixture->connection, new IdentityMap('test')));

        expect(call($kernel, 'POST', "/api/racks/{$fixture->ids['racks'][0]}/archive")->status)->toBe(302);
    });

    it('keeps a 307, which asks for the same method on purpose', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $kernel = kernel([RedirectingEndpoints::class], new Orm($fixture->connection, new IdentityMap('test')));

        expect(call($kernel, 'PUT', "/api/racks/{$fixture->ids['racks'][0]}/relocate")->status)->toBe(307);
    });
});
