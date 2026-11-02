<?php

declare(strict_types=1);

/**
 * The public API through the Kernel (ADR 0159): `POST /_api/{operation}`
 * and an endpoint marked #[RequiresScope], each taking an API token and no
 * session -- no cookie read, none issued, no CSRF token asked for -- and a
 * token refused on every path a session is read.
 */

namespace Tinvar\Tests\Api\ApiKernel;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Api\ApiAccess;
use Tinvar\Api\ApiTokens;
use Tinvar\Api\ArrayApiTokens;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Calls\Fixtures\AuditActions;
use Tinvar\Tests\Calls\Fixtures\RackActions;
use Tinvar\Tests\Calls\Fixtures\RackData;
use Tinvar\Tests\Calls\Fixtures\RackPolicy;
use Tinvar\Tests\Calls\Fixtures\Ran;
use Tinvar\Tests\Calls\Fixtures\ShowRack;
use Tinvar\Tests\Calls\Fixtures\Staff;
use Tinvar\Tests\Calls\Fixtures\User;
use Tinvar\Tests\Calls\Fixtures\Users;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';

/** Signs in whoever the `session` cookie names: the application's session, as a global middleware does. */
final class SessionCookie implements Middleware
{
    public static ?AuthContext $auth = null;

    public static int $runs = 0;

    public function handle(Request $request, \Closure $next): Response
    {
        self::$runs++;
        $user = $request->cookie('session');
        if ($user !== null && self::$auth !== null) {
            self::$auth->login(new User($user), 'e1');
        }

        return $next($request);
    }
}

/** A panel's own middleware -- a network allowlist, say -- shut until a test opens it. */
final class OfficeOnly implements Middleware
{
    public static bool $open = false;

    public function handle(Request $request, \Closure $next): Response
    {
        if (!self::$open) {
            throw new \Tinvar\Http\HttpException(403, 'Only from the office network.', 'forbidden');
        }

        return $next($request);
    }
}

/** A panel's guard: lets in only the users a test names, as an admins list does. */
final class AdminsOnly implements Middleware
{
    /** @var list<string> */
    public static array $admins = [];

    public static ?AuthContext $auth = null;

    public function handle(Request $request, \Closure $next): Response
    {
        if (!\in_array(self::$auth?->id(), self::$admins, true)) {
            throw new \Tinvar\Http\ForbiddenException('Admins only.');
        }

        return $next($request);
    }
}

final class RackApi
{
    #[Get('/api/racks/{rack}', name: 'api.racks.show'), Authorize('view', 'rack'), RequiresScope('racks:read')]
    public function show(Rack $rack): RackData
    {
        return new RackData($rack->id, $rack->name);
    }
}

final class RackPage
{
    #[Get('/racks/{rack}', name: 'racks.show'), Authorize('view', 'rack')]
    public function show(Rack $rack): RackData
    {
        return new RackData($rack->id, $rack->name);
    }
}

final class World
{
    public Kernel $kernel;
    public Fixture $fixture;
    public AuthContext $auth;
    public Users $users;
    public Staff $staff;
    public ArrayApiTokens $store;
    public PanelContext $panels;

    /** @param array<string, int> $limits */
    public function __construct(array $limits = [], bool $api = true, PanelDefinition $admin = new PanelDefinition('admin'))
    {
        $this->fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($this->fixture->connection, new IdentityMap('api-kernel'));
        $this->auth = SessionCookie::$auth = AdminsOnly::$auth = new AuthContext();
        $this->panels = new PanelContext();
        SessionCookie::$runs = 0;
        $this->users = new Users();
        $this->staff = new Staff();
        $this->store = new ArrayApiTokens();
        $resolver = static fn (string $class): object => $class === RackActions::class ? new RackActions($orm) : new $class();

        $actions = new ActionRegistry($resolver);
        $actions->registerAll([RackActions::class, AuditActions::class]);
        $queries = new QueryRegistry($resolver);
        $queries->registerAll([ShowRack::class]);
        $endpoints = new EndpointRegistry();
        $endpoints->registerAll([RackApi::class, RackPage::class]);

        $this->kernel = new Kernel(
            orm: $orm,
            routes: new RouteRegistry(),
            queries: $queries,
            actions: $actions,
            auth: $this->auth,
            policies: new PolicyRegistry()->register(Rack::class, RackPolicy::class),
            resolver: $resolver,
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [SessionCookie::class],
            throttle: new Throttle(new ArrayThrottleStore(), $this->auth, static fn (Request $r): string => $r->remoteAddress ?? ''),
            limits: $limits,
            panels: $this->panels,
            panelRegistry: new PanelRegistry($admin),
            memberships: $this->staff,
            endpoints: $endpoints,
            apiAccess: $api ? new ApiAccess($this->store, $this->users) : null,
        );
    }

    public function rack(): string
    {
        return $this->fixture->ids['racks'][0];
    }

    /** @param list<string> $scopes */
    public function token(array $scopes, string $user = 'ada', ?string $panel = null): string
    {
        $this->users->epochs[$user] ??= 'e1';
        $tokens = $this->kernel->apiTokens ?? throw new \LogicException('No API tokens.');
        $issue = fn (): string => $this->auth->actingAs(new User($user), static fn (): string => $tokens->issue('Test', $scopes)->plaintext, 'e1');

        return $panel === null ? $issue() : $this->panels->within($panel, $issue);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function call(string $method, string $path, array $headers = [], string $body = '', array $cookies = []): Response
    {
        $input = $body === '' ? [] : (array) json_decode($body, true);

        return $this->kernel->handle(
            new Request($method, $path, ['accept' => 'application/json', ...($body === '' ? [] : ['content-type' => 'application/json']), ...$headers], [], $input, $cookies, remoteAddress: '203.0.113.9'),
            static fn (): Response => Response::make(404),
            $body,
        );
    }
}

/** @return array<string, mixed> */
function json(Response $response): array
{
    return (array) json_decode($response->body(), true);
}

describe('/_api', function () {
    it('runs a query and an action for the token\'s user, with no session, cookie or CSRF token', function () {
        $w = new World();
        $token = $w->token(['racks:read', 'racks:write']);

        $shown = $w->call('POST', '/_api/racks.show', ['authorization' => "Bearer {$token}"], json_encode(['rack' => $w->rack()], JSON_THROW_ON_ERROR));
        $renamed = $w->call('POST', '/_api/racks.rename', ['authorization' => "Bearer {$token}"], json_encode(['rack' => $w->rack(), 'name' => 'From the API'], JSON_THROW_ON_ERROR));

        expect($shown->status)->toBe(200)
            ->and(json($shown))->toBe(['id' => $w->rack(), 'name' => json($shown)['name']])
            ->and($shown->header('content-type'))->toBe('application/json')
            ->and($shown->header('cache-control'))->toBe('private, no-store')
            ->and($shown->cookies())->toBe([])
            ->and($renamed->status)->toBe(200)
            ->and(json($renamed))->toBe(['id' => $w->rack(), 'name' => 'From the API'])
            ->and(SessionCookie::$runs)->toBe(0)
            ->and($w->kernel->opensNoSession(new Request('POST', '/_api/racks.show')))->toBeTrue();
    });

    it('answers 401 with WWW-Authenticate for no token, or one that does not hold', function (?string $authorization, string $code) {
        $w = new World();
        $w->token(['racks:read']);

        $response = $w->call('POST', '/_api/racks.show', $authorization === null ? [] : ['authorization' => $authorization], json_encode(['rack' => $w->rack()], JSON_THROW_ON_ERROR));

        expect($response->status)->toBe(401)
            ->and(json($response)['error']['code'])->toBe($code)
            ->and($response->header('www-authenticate'))->toStartWith('Bearer realm="api"');
    })->with([
        'none' => [null, 'api_token_required'],
        'not a token' => ['Bearer nonsense', 'api_token_invalid'],
        'an unknown one' => ['Bearer tvr_' . str_repeat('0', 32) . '_' . str_repeat('a', 43), 'api_token_invalid'],
    ]);

    it('is not signed in by a session cookie, nor led by one past a token', function () {
        $w = new World();

        $response = $w->call('POST', '/_api/racks.show', [], json_encode(['rack' => $w->rack()], JSON_THROW_ON_ERROR), ['session' => 'ada']);

        expect($response->status)->toBe(401)
            ->and(SessionCookie::$runs)->toBe(0);
    });

    it('refuses a token lacking the scope with a 403 naming it, and runs nothing', function () {
        $w = new World();
        $token = $w->token(['racks:read']);

        $response = $w->call('POST', '/_api/racks.rename', ['authorization' => "Bearer {$token}"], json_encode(['rack' => $w->rack(), 'name' => 'X'], JSON_THROW_ON_ERROR));

        expect($response->status)->toBe(403)
            ->and(json($response)['error']['code'])->toBe('insufficient_scope')
            ->and($response->header('www-authenticate'))->toBe('Bearer realm="api", error="insufficient_scope", scope="racks:write"');
    });

    it('answers 422 with the validator\'s errors, 404 for what nothing exposes, and 405 for anything but POST', function () {
        $w = new World();
        $token = $w->token(['racks:read', 'racks:write']);
        $bearer = ['authorization' => "Bearer {$token}"];

        $invalid = $w->call('POST', '/_api/racks.rename', $bearer, json_encode(['rack' => $w->rack(), 'name' => ''], JSON_THROW_ON_ERROR));

        expect($invalid->status)->toBe(422)
            ->and(json($invalid)['errors'])->toHaveKey('name')
            ->and($w->call('POST', '/_api/racks.scrap', $bearer, '{}')->status)->toBe(404)
            ->and($w->call('POST', '/_api/racks/show', $bearer, '{}')->status)->toBe(404)
            ->and($w->call('POST', '/_api/racks.show', $bearer, json_encode(['rack' => 'no-such-rack'], JSON_THROW_ON_ERROR))->status)->toBe(404)
            ->and($w->call('GET', '/_api/racks.show', $bearer)->status)->toBe(405);
    });

    it('spends the api throttle by address, and by the token\'s user, and answers 429 with Retry-After', function () {
        $w = new World(['api' => 2]);
        $token = $w->token(['racks:read']);
        $body = json_encode(['rack' => $w->rack()], JSON_THROW_ON_ERROR);
        $statuses = [];
        foreach (range(1, 3) as $_) {
            $last = $w->call('POST', '/_api/racks.show', ['authorization' => "Bearer {$token}"], $body);
            $statuses[] = $last->status;
        }

        expect($statuses)->toBe([200, 200, 429])
            ->and($last->header('retry-after'))->not->toBeNull()
            ->and(json($last)['error']['code'])->toBe('too_many_requests');
    });

    it('runs an operation in a panel behind the panel\'s guard and its middleware, as a page or an endpoint there is', function () {
        $w = new World(admin: new PanelDefinition('admin', guard: AdminsOnly::class, middleware: [OfficeOnly::class]));
        $w->staff->members['ada'] = ['racks.audit'];
        $token = $w->token(['racks:audit'], panel: 'admin');
        $call = static fn (): Response => $w->call('POST', '/_api/racks.audit', ['authorization' => "Bearer {$token}"], json_encode(['rack' => $w->rack()], JSON_THROW_ON_ERROR));
        Ran::$bodies = [];

        // Off the office network, and an admin: the panel's middleware refuses.
        AdminsOnly::$admins = ['ada'];
        OfficeOnly::$open = false;
        $offNetwork = $call();
        // On it, and no longer an admin: the panel's guard refuses.
        OfficeOnly::$open = true;
        AdminsOnly::$admins = [];
        $demoted = $call();
        // On it, and an admin: let in.
        AdminsOnly::$admins = ['ada'];
        $allowed = $call();

        expect($offNetwork->status)->toBe(403)
            ->and($demoted->status)->toBe(403)
            ->and($allowed->status)->toBe(200)
            ->and(Ran::$bodies)->toBe(['audit']);
    });

    it('ends every token when the user\'s epoch moves', function () {
        $w = new World();
        $token = $w->token(['racks:read']);
        $w->users->epochs['ada'] = 'e2';

        expect($w->call('POST', '/_api/racks.show', ['authorization' => "Bearer {$token}"], json_encode(['rack' => $w->rack()], JSON_THROW_ON_ERROR))->status)->toBe(401);
    });
})->group('wired');

describe('an endpoint marked #[RequiresScope]', function () {
    it('takes a token holding its scope, and no session', function () {
        $w = new World();
        $token = $w->token(['racks:read']);

        $response = $w->call('GET', "/api/racks/{$w->rack()}", ['authorization' => "Bearer {$token}"]);

        expect($response->status)->toBe(200)
            ->and(json($response)['id'])->toBe($w->rack())
            ->and($response->cookies())->toBe([])
            ->and(SessionCookie::$runs)->toBe(0)
            ->and($w->kernel->opensNoSession(new Request('GET', "/api/racks/{$w->rack()}")))->toBeTrue();
    });

    it('refuses a token without its scope, no token, and a session cookie', function () {
        $w = new World();
        $token = $w->token(['racks:write']);

        expect($w->call('GET', "/api/racks/{$w->rack()}", ['authorization' => "Bearer {$token}"])->status)->toBe(403)
            ->and($w->call('GET', "/api/racks/{$w->rack()}")->status)->toBe(401)
            ->and($w->call('GET', "/api/racks/{$w->rack()}", [], '', ['session' => 'ada'])->status)->toBe(401)
            ->and(SessionCookie::$runs)->toBe(0);
    });
})->group('wired');

describe('a session\'s path', function () {
    it('refuses an API token, and says where to send it', function () {
        $w = new World();
        $token = $w->token(['racks:read']);

        $page = $w->call('GET', "/racks/{$w->rack()}", ['authorization' => "Bearer {$token}"]);
        $action = $w->call('POST', '/_action/racks.rename', ['authorization' => "Bearer {$token}"], json_encode(['rack' => $w->rack(), 'name' => 'X'], JSON_THROW_ON_ERROR));

        expect($page->status)->toBe(401)
            ->and(json($page)['error']['code'])->toBe('api_token_not_accepted')
            ->and($action->status)->toBe(401)
            ->and(json($action)['error']['code'])->toBe('api_token_not_accepted')
            ->and(SessionCookie::$runs)->toBe(0);
    });

    it('lets the session through as before', function () {
        $w = new World();
        $w->users->epochs['ada'] = 'e1';

        expect($w->call('GET', "/racks/{$w->rack()}", [], '', ['session' => 'ada'])->status)->toBe(200);
    });
})->group('wired');

describe('the Kernel', function () {
    it('refuses #[RequiresScope] when it was given no ApiAccess', function () {
        expect(fn () => new World(api: false))->toThrow(\InvalidArgumentException::class, 'requires API token scopes, and the Kernel was given no ApiAccess');
    });

    it('puts ApiTokens in reach, offering the scopes each panel declares', function () {
        $w = new World();

        expect($w->kernel->apiTokens)->toBeInstanceOf(ApiTokens::class)
            ->and($w->kernel->apiTokens?->grantable())->toBe(['racks:read', 'racks:write']);
    });
})->group('wired');
