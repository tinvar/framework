<?php

declare(strict_types=1);

/**
 * Channels (ADR 0069): a subscription let in through the pipeline every
 * entry point runs -- the tenant, the session and its user and epoch, the
 * models resolved in the tenant, every #[Authorize] -- and let in again on
 * every trade; publishing held to the tenant in force; and every way a
 * subscription can be refused.
 */

namespace Tinvar\Tests\Channels;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Channel;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Post;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Channels\ChannelException;
use Tinvar\Channels\ChannelRegistry;
use Tinvar\Channels\Channels;
use Tinvar\Channels\ChannelToken;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveToken;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Support\Fixtures\Sources;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once __DIR__ . '/../Support/Fixtures/tenants.php';

const KEY = 'channels-test-application-key-0123456789ab';
const SESSION_COOKIE = '__Host-test_session';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Signs in whoever the test says, at the epoch it says, as a session middleware would. */
final class SignIn implements Middleware
{
    public static ?AuthContext $auth = null;

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-test-user');
        self::$auth?->login($user === null ? null : new Member($user), $request->header('x-test-epoch') ?? '');

        return $next($request);
    }
}

/** The tenant a request names in a header, as a host would. */
final class FromHeader
{
    public function tenantFor(Request $request): ?string
    {
        return $request->header('x-tenant');
    }
}

/** Each user sees their own script: alice the first of a tenant's, bob the second. */
final class ScriptPolicy
{
    /** @var array<string, string> who => the suffix of the scripts they own */
    public static array $owners = [];

    public function view(?Authenticatable $user, Script $script): bool
    {
        $suffix = self::$owners[$user?->authIdentifier() ?? ''] ?? null;

        return $suffix !== null && str_ends_with($script->name, $suffix);
    }
}

final readonly class ScriptRunData extends Data
{
    public function __construct(public string $status) {}
}

final readonly class OtherData extends Data
{
    public function __construct(public string $note) {}
}

final readonly class SmuggleData extends Data
{
    public function __construct(public mixed $value) {}
}

#[Channel('scripts.{script}', message: ScriptRunData::class), Authorize('view', 'script'), TenantFrom(FromHeader::class)]
final readonly class ScriptRuns
{
    public function __construct(public Script $script) {}
}

#[Channel('status', message: OtherData::class), PublicAccess, NoAuthorization(reason: 'the shop\'s status is everyone\'s'),
    TenantFrom(FromHeader::class)]
final readonly class Status {}

#[Channel('rooms.{room}', message: OtherData::class), NoAuthorization(reason: 'a test room'), TenantFrom(FromHeader::class)]
final readonly class Rooms
{
    public function __construct(public int $room) {}
}

final class SessionActions
{
    #[Action(name: 'session.signOut'), NoAuthorization(reason: 'a test action')]
    public function signOut(): ActionResult
    {
        SignIn::$auth?->login(null);

        return ActionResult::ok();
    }

    #[Action(name: 'session.nothing'), NoAuthorization(reason: 'a test action')]
    public function nothing(): ActionResult
    {
        return ActionResult::ok();
    }
}

final class SessionEndpoints
{
    #[Post('/session/sign-out', name: 'session.signOut'), NoAuthorization(reason: 'a test endpoint')]
    public function signOut(): void
    {
        SignIn::$auth?->login(null);
    }

    #[Post('/session/rotate', name: 'session.rotate'), NoAuthorization(reason: 'a test endpoint')]
    public function rotate(): Response
    {
        return Response::make(204)->withCookie(SESSION_COOKIE, 'session-b');
    }

    #[Post('/session/keep', name: 'session.keep'), NoAuthorization(reason: 'a test endpoint')]
    public function keep(): void {}
}

final readonly class NothingData extends Data
{
    public function __construct(public bool $done) {}
}

#[Page('/goodbye', view: 'goodbye', name: 'goodbye'), NoAuthorization(reason: 'a test page')]
final class Goodbye
{
    public function __invoke(): NothingData
    {
        SignIn::$auth?->login(null);

        return new NothingData(true);
    }
}

// --- refused at registration -------------------------------------------------

#[Channel('scripts.{script}', message: OtherData::class), Authorize('viewAny', Script::class), TenantFrom(FromHeader::class)]
final readonly class Unguarded
{
    public function __construct(public Script $script) {}
}

#[Channel('things', message: OtherData::class)]
final readonly class SaysNothing {}

#[Channel('scripts.{script}', message: OtherData::class), Authorize('view', 'script')]
final readonly class TakesSomethingElse
{
    public function __construct(public Script $script, public string $extra) {}
}

#[Channel('scripts.{script}.{run}', message: OtherData::class), Authorize('view', 'script')]
final readonly class TakesTooLittle
{
    public function __construct(public Script $script) {}
}

#[Channel('scripts.{script}', message: Script::class), Authorize('view', 'script')]
final readonly class CarriesAModel
{
    public function __construct(public Script $script) {}
}

#[Channel('scripts.{script}', message: OtherData::class), Authorize('view', 'script')]
final class NotPromoted
{
    public function __construct(Script $script) {}
}

#[Channel('scripts.*', message: OtherData::class), NoAuthorization(reason: 'test')]
final readonly class Wildcard {}

#[Channel('rooms.{room}', message: OtherData::class), Authorize('view', 'room')]
final readonly class ChecksAString
{
    public function __construct(public string $room) {}
}

/** @return array{Kernel, MemoryHub, LiveUpdates, TenantFixture, Orm, ChannelRegistry} */
function kernel(): array
{
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $auth = new AuthContext();
    SignIn::$auth = $auth;
    ScriptPolicy::$owners = ['alice' => '-1', 'bob' => '-2'];
    $container = new Container();

    $channels = new ChannelRegistry();
    $channels->registerAll([ScriptRuns::class, Status::class, Rooms::class]);
    $actions = new ActionRegistry();
    $actions->registerAll([SessionActions::class]);
    $endpoints = new EndpointRegistry();
    $endpoints->registerAll([SessionEndpoints::class]);
    $routes = new RouteRegistry();
    $routes->registerAll([Goodbye::class]);
    $hub = new MemoryHub();
    $live = LiveUpdates::fromKey(KEY, $hub, static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '');

    return [new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: (new PolicyRegistry())->register(Script::class, ScriptPolicy::class),
        resolver: $container->resolver(),
        tenants: new Sources($container->resolver()),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [SignIn::class],
        builds: new BuildRegistry('b1'),
        container: $container,
        live: $live,
        endpoints: $endpoints,
        channels: $channels,
    ), $hub, $live, $fixture, $orm, $channels];
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 * @return array{Response, array<string, mixed>}
 */
function post(Kernel $kernel, string $path, array $body, array $headers, string $session = 'session-a'): array
{
    $cookies = [VerifyCsrfToken::COOKIE => 'tok'];
    if ($session !== '') {
        $cookies[SESSION_COOKIE] = $session;
    }
    $response = $kernel->handle(
        new Request('POST', $path, ['content-type' => 'application/json', 'x-xsrf-token' => 'tok', ...$headers], cookies: $cookies),
        static fn (): Response => Response::make(500),
        json_encode($body, JSON_THROW_ON_ERROR),
    );

    $decoded = $response->body() === '' ? [] : json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    return [$response, \is_array($decoded) ? $decoded : []];
}

/**
 * @param array<string, mixed> $params
 * @param array<string, string> $headers
 * @return array{Response, array<string, mixed>}
 */
function subscribe(Kernel $kernel, string $channel, array $params, array $headers, string $session = 'session-a'): array
{
    return post($kernel, "/_channel/{$channel}", ['params' => $params], $headers, $session);
}

/**
 * @param list<string> $tokens
 * @param array<string, string> $headers
 * @return array{Response, array<string, mixed>}
 */
function trade(Kernel $kernel, array $tokens, array $headers, string $session = 'session-a'): array
{
    return post($kernel, SubscribeEndpoint::PATH, ['tokens' => $tokens], $headers, $session);
}

/** A token minted the way ChannelEndpoint mints one, with whatever claims a test needs to forge. */
function forge(LiveUpdates $live, string $topic, string $script, string $user = 'alice', string $epoch = 'e1', string $tenant = 'acme', ?int $issuedAt = null, ?int $expiresAt = null): string
{
    $issuedAt ??= time();

    return new ChannelToken(new TokenClaims(
        route: 'scripts',
        params: ['script' => $script],
        panel: '',
        tenant: $tenant,
        build: 'b1',
        sessionHash: TokenClaims::hashSession($live->signer, 'session-a'),
        expiresAt: $expiresAt ?? $issuedAt + ChannelToken::DEFAULT_TTL,
        issuedAt: $issuedAt,
        authHash: TokenClaims::hashAuth($live->signer, $user, $epoch),
    ), $topic)->encode($live->signer);
}

const ALICE = ['x-test-user' => 'alice', 'x-test-epoch' => 'e1', 'x-tenant' => 'acme'];
const BOB = ['x-test-user' => 'bob', 'x-test-epoch' => 'e1', 'x-tenant' => 'acme'];

describe('registration holds a channel to the build rules', function () {
    it('refuses what it could not hold', function (string $class, string $message) {
        expect(fn () => new ChannelRegistry()->register($class))->toThrow(ChannelException::class, $message);
    })->with([
        'a model no #[Authorize] names' => [Unguarded::class, 'no #[Authorize] names it'],
        'a channel that says nothing of who may subscribe' => [SaysNothing::class, 'declares no #[Authorize]'],
        'a constructor parameter not in the pattern' => [TakesSomethingElse::class, 'is not in the pattern'],
        'a pattern parameter the constructor does not take' => [TakesTooLittle::class, 'takes no $run'],
        'a message that is not a Data class' => [CarriesAModel::class, 'no Data class'],
        'a parameter that is not a promoted property' => [NotPromoted::class, 'not a promoted property'],
        'a wildcard in the pattern' => [Wildcard::class, "has '*'"],
        'a check handed a raw value' => [ChecksAString::class, 'not a model'],
    ]);

    it('refuses two channels by one name', function () {
        $registry = new ChannelRegistry();
        $registry->register(ScriptRuns::class);

        expect(fn () => $registry->register(Unguarded::class))->toThrow(ChannelException::class);
    });

    it('is reported by tinvar check, and by the build rules for a model input and for saying nothing', function () {
        $violations = ChannelRegistry::check([ScriptRuns::class, Unguarded::class, SaysNothing::class]);
        expect(array_column($violations, 'rule'))->toBe([ChannelRegistry::CHANNELS_MUST_COMPILE, ChannelRegistry::CHANNELS_MUST_COMPILE])
            ->and(array_column($violations, 'where'))->toBe([Unguarded::class, SaysNothing::class]);

        $rules = (new BuildRules([Script::class]))->check([ScriptRuns::class, Unguarded::class, SaysNothing::class]);
        expect(array_column($rules, 'rule'))->toBe([BuildRules::AUTHORIZE_EVERY_MODEL_INPUT, BuildRules::CHANNEL_MUST_DECLARE_AUTHORIZATION])
            ->and($rules[0]->where)->toContain(Unguarded::class)
            ->and($rules[0]->where)->toContain('$script');
    });

    it('names a channel by its pattern\'s identifiers', function () {
        expect(new ChannelRegistry()->register(ScriptRuns::class)->name)->toBe('scripts');
    });
});

describe('a subscription', function () {
    it('is let in for a model of the request\'s tenant the user may see, and traded for its topic alone', function () {
        [$kernel, $hub, $live, $fixture] = kernel();
        $script = $fixture->scripts['acme'][0];

        [$response, $body] = subscribe($kernel, 'scripts', ['script' => $script], ALICE);
        expect($response->status)->toBe(200)
            ->and($response->header('cache-control'))->toBe('private, no-store')
            ->and($body['token'])->toBeString();

        $token = ChannelToken::decode((string) $body['token'], $live->signer);
        $topic = $live->channelTopics?->for('acme', 'scripts', ['script' => $script]);
        expect($token->topic)->toBe($topic)
            ->and($token->claims->tenant)->toBe('acme')
            ->and($token->claims->params)->toBe(['script' => $script]);

        $before = time();
        [$traded, $grant] = trade($kernel, [(string) $body['token']], ALICE);
        expect($traded->status)->toBe(200)
            ->and($grant['topics'])->toBe([$topic])
            ->and($grant['tokens'][0])->toBeString()
            // A grant that names a channel is a short one.
            ->and($grant['expiresAt'])->toBeLessThanOrEqual(time() + SubscribeEndpoint::CHANNEL_GRANT_SECONDS)
            ->and($grant['expiresAt'])->toBeGreaterThanOrEqual($before + SubscribeEndpoint::CHANNEL_GRANT_SECONDS)
            ->and($hub->grants[0]['topics'])->toBe([$topic]);
    });

    it('is refused for another user\'s model with a 403, and mints nothing', function () {
        [$kernel, $hub, , $fixture] = kernel();

        [$response, $body] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['acme'][1]], ALICE);

        expect($response->status)->toBe(403)
            ->and($body)->not->toHaveKey('token')
            ->and($hub->grants)->toBe([]);
    });

    it('is refused for another tenant\'s model with a 404, as a page is', function () {
        [$kernel, , , $fixture] = kernel();

        [$response, $body] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['globex'][0]], ALICE);

        expect($response->status)->toBe(404)
            ->and($body)->not->toHaveKey('token');
    });

    it('is refused to someone not signed in, unless the channel is public', function () {
        [$kernel, , , $fixture] = kernel();

        [$refused] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['acme'][0]], ['x-tenant' => 'acme']);
        [$public, $body] = subscribe($kernel, 'status', [], ['x-tenant' => 'acme']);

        expect($refused->status)->toBe(401)
            ->and($public->status)->toBe(200)
            ->and($body['token'])->toBeString();
    });

    it('is refused with no session to bind it to', function () {
        [$kernel] = kernel();

        [$response, $body] = subscribe($kernel, 'status', [], ['x-tenant' => 'acme'], session: '');

        expect($response->status)->toBe(401)
            ->and($body['error']['code'] ?? null)->toBe('no_session');
    });

    it('takes no topic, wildcard or parameter it does not declare from the client', function () {
        [$kernel, , , $fixture] = kernel();

        [$extra] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['acme'][0], 'topic' => 'tinvar:AAAAAAAAAAAAAAAAAAAAAA'], ALICE);
        [$wildcard] = subscribe($kernel, 'scripts', ['script' => '*'], ALICE);
        [$missing] = subscribe($kernel, 'scripts', [], ALICE);
        [$unknown] = subscribe($kernel, 'scripts.*', [], ALICE);
        [$list] = post($kernel, '/_channel/scripts', ['params' => ['a', 'b']], ALICE);

        expect($extra->status)->toBe(400)
            ->and($wildcard->status)->toBe(404)
            ->and($missing->status)->toBe(404)
            ->and($unknown->status)->toBe(404)
            ->and($list->status)->toBe(400);
    });

    it('holds a scalar parameter to one spelling, so 7 and "07" are not two channels', function () {
        [$kernel, , $live] = kernel();

        [, $seven] = subscribe($kernel, 'rooms', ['room' => 7], ALICE);
        [, $string] = subscribe($kernel, 'rooms', ['room' => '7'], ALICE);
        [$padded] = subscribe($kernel, 'rooms', ['room' => '07'], ALICE);

        expect(ChannelToken::decode((string) $seven['token'], $live->signer)->topic)
            ->toBe(ChannelToken::decode((string) $string['token'], $live->signer)->topic)
            ->and($padded->status)->toBe(404);
    });

    it('is answered only to POST', function () {
        [$kernel] = kernel();

        $response = $kernel->handle(new Request('GET', '/_channel/status', ['x-tenant' => 'acme']), static fn (): Response => Response::make(500));

        expect($response->status)->toBe(405);
    });
});

it('is told there is nothing to hear when the application has no hub, whatever it names', function () {
    $orm = new Orm(new TenantFixture()->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $channels = new ChannelRegistry();
    $channels->register(ScriptRuns::class);
    $container = new Container();
    $kernel = new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        tenants: new Sources($container->resolver()),
        allowedQueries: static fn (Request $r): array => [],
        channels: $channels,
    );

    [$response, $body] = subscribe($kernel, 'scripts', ['script' => 'anything'], []);
    $get = $kernel->handle(new Request('GET', '/_channel/scripts'), static fn (): Response => Response::make(500));

    expect($kernel->channels_)->toBeNull()
        ->and($response->status)->toBe(204)
        ->and($body)->toBe([])
        ->and($get->status)->toBe(405);
});

describe('a trade', function () {
    it('grants nothing for a token traded in another tenant than its own', function () {
        [$kernel, $hub, , $fixture] = kernel();
        [, $body] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['acme'][0]], ALICE);

        [$response, $grant] = trade($kernel, [(string) $body['token']], [...ALICE, 'x-tenant' => 'globex']);

        expect($response->status)->toBe(200)
            ->and($grant['tokens'])->toBe([null])
            ->and($grant['topics'])->toBe([])
            ->and($hub->grants)->toBe([]);
    });

    it('is the 401 that purges the client once the epoch has moved', function () {
        [$kernel, $hub, , $fixture] = kernel();
        [, $body] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['acme'][0]], ALICE);

        [$response, $error] = trade($kernel, [(string) $body['token']], [...ALICE, 'x-test-epoch' => 'e2']);

        expect($response->status)->toBe(401)
            ->and($error)->toBe(['error' => ['code' => 'session_changed']])
            ->and($hub->revoked)->toBe(1);
    });

    it('is the 401 once the user has signed out, or from another session', function () {
        [$kernel, , , $fixture] = kernel();
        [, $body] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['acme'][0]], ALICE);

        [$signedOut] = trade($kernel, [(string) $body['token']], ['x-tenant' => 'acme']);
        [$elsewhere] = trade($kernel, [(string) $body['token']], ALICE, session: 'session-b');

        expect($signedOut->status)->toBe(401)
            ->and($elsewhere->status)->toBe(401);
    });

    it('stops granting a model the user may no longer see, renewal or not', function () {
        [$kernel, $hub, , $fixture] = kernel();
        [, $body] = subscribe($kernel, 'scripts', ['script' => $fixture->scripts['acme'][0]], ALICE);
        [, $first] = trade($kernel, [(string) $body['token']], ALICE);
        expect($first['topics'])->toHaveCount(1);

        ScriptPolicy::$owners = [];
        $grants = \count($hub->grants);
        [, $second] = trade($kernel, [(string) $first['tokens'][0]], ALICE);

        expect($second['tokens'])->toBe([null])
            ->and($second['topics'])->toBe([])
            ->and(\count($hub->grants))->toBe($grants);
    });

    it('grants nothing for a token whose topic is not its channel\'s, signed or not', function () {
        [$kernel, $hub, $live, $fixture] = kernel();
        $mine = $fixture->scripts['acme'][0];
        $bobs = $fixture->scripts['acme'][1];
        $topics = $live->channelTopics;
        expect($topics)->not->toBeNull();

        // Signed with the server's key, naming alice's script and bob's topic.
        $forged = forge($live, (string) $topics?->for('acme', 'scripts', ['script' => $bobs]), $mine);
        // And one under another key altogether.
        $foreign = new ChannelToken(
            ChannelToken::decode(forge($live, (string) $topics?->for('acme', 'scripts', ['script' => $mine]), $mine), $live->signer)->claims,
            (string) $topics?->for('acme', 'scripts', ['script' => $bobs]),
        )->encode(new Signer('another-application-key-0123456789abcdef', 'live-token'));

        [, $grant] = trade($kernel, [$forged, $foreign], ALICE);

        expect($grant['tokens'])->toBe([null, null])
            ->and($grant['topics'])->toBe([])
            ->and($hub->grants)->toBe([]);
    });

    it('grants nothing for a token past its expiry or its lifetime', function () {
        [$kernel, $hub, $live, $fixture] = kernel();
        $script = $fixture->scripts['acme'][0];
        $topic = (string) $live->channelTopics?->for('acme', 'scripts', ['script' => $script]);

        $expired = forge($live, $topic, $script, issuedAt: time() - 400, expiresAt: time() - 1);
        $tooOld = forge($live, $topic, $script, issuedAt: time() - ChannelToken::MAX_LIFETIME - 10, expiresAt: time() + 100);
        $good = forge($live, $topic, $script);

        [, $grant] = trade($kernel, [$expired, $tooOld], ALICE);
        expect($grant['tokens'])->toBe([null, null])
            ->and($hub->grants)->toBe([]);

        [, $grant] = trade($kernel, [$good], ALICE);
        expect($grant['topics'])->toBe([$topic]);
    });

    it('reads no live token as a channel token, nor the other way about', function () {
        [$kernel, $hub, $live, $fixture] = kernel();
        $script = $fixture->scripts['acme'][0];
        $topic = (string) $live->channelTopics?->for('acme', 'scripts', ['script' => $script]);

        // A live token naming the channel's topic: well signed, and of the
        // wrong kind, so its page -- which does not exist -- is what decides.
        $asLive = new LiveToken(
            ChannelToken::decode(forge($live, $topic, $script), $live->signer)->claims,
            [$topic => ['*']],
        )->encode($live->signer);

        [, $grant] = trade($kernel, [$asLive], ALICE);
        expect($grant['tokens'])->toBe([null])
            ->and($hub->grants)->toBe([]);

        expect(fn () => ChannelToken::decode($asLive, $live->signer))->toThrow(\Tinvar\Render\TokenError::class);
    });

    it('carries a page\'s topics and a channel\'s in one grant', function () {
        [$kernel, $hub, $live, $fixture] = kernel();
        $script = $fixture->scripts['acme'][0];
        $page = (string) new LiveMinter(
            signer: $live->signer,
            topics: $live->topics,
            route: 'missing.page',
            params: [],
            panel: '',
            tenant: 'acme',
            build: 'b1',
            sessionId: 'session-a',
            user: 'alice',
            authEpoch: 'e1',
        )->mint(["acme\0orders"]);
        [, $body] = subscribe($kernel, 'scripts', ['script' => $script], ALICE);

        [, $grant] = trade($kernel, [$page, (string) $body['token']], ALICE);

        // The page is no route this server has, so only the channel is heard.
        expect($grant['tokens'][0])->toBeNull()
            ->and($grant['tokens'][1])->toBeString()
            ->and($grant['topics'])->toBe([(string) $live->channelTopics?->for('acme', 'scripts', ['script' => $script])]);
    });
});

describe('an action that changes who the browser is', function () {
    it('takes the hub\'s grant away, and one that changes nothing leaves it', function () {
        [$kernel, $hub] = kernel();

        [$signOut] = post($kernel, '/_action/session.signOut', [], ALICE);
        expect($signOut->status)->toBe(200)
            ->and($signOut->header('x-test-hub-revoked'))->toBe('1');

        [$nothing] = post($kernel, '/_action/session.nothing', [], ALICE);
        expect($nothing->status)->toBe(200)
            ->and($nothing->header('x-test-hub-revoked'))->toBeNull()
            ->and($hub->revoked)->toBe(1);
    });

    it('takes it away whatever answered: an endpoint that signs out, or hands out another session', function () {
        [$kernel] = kernel();

        [$signOut] = post($kernel, '/session/sign-out', [], ALICE);
        [$rotate] = post($kernel, '/session/rotate', [], ALICE);
        [$keep] = post($kernel, '/session/keep', [], ALICE);

        expect($signOut->status)->toBe(204)
            ->and($signOut->header('x-test-hub-revoked'))->toBe('1')
            ->and($rotate->header('x-test-hub-revoked'))->toBe('1')
            ->and($keep->status)->toBe(204)
            ->and($keep->header('x-test-hub-revoked'))->toBeNull();
    });

    it('takes it away for a page whose loader signs out', function () {
        [$kernel] = kernel();

        $response = $kernel->handle(
            new Request('GET', '/goodbye', ALICE, cookies: [SESSION_COOKIE => 'session-a']),
            static fn (): Response => Response::make(200, 'page'),
        );

        expect($response->status)->toBe(200)
            ->and($response->header('x-test-hub-revoked'))->toBe('1');
    });
});

describe('publishing', function () {
    /** @return array{Channels, MemoryHub, LiveUpdates, TenantFixture, Orm} */
    function publisher(): array
    {
        [, $hub, $live, $fixture, $orm, $registry] = kernel();

        return [$live->channels($registry, $orm), $hub, $live, $fixture, $orm];
    }

    it('reaches the topic a subscriber in the same tenant was granted, once the write commits', function () {
        [$channels, $hub, $live, $fixture, $orm] = publisher();
        $id = $fixture->scripts['acme'][0];

        $orm->actAs('acme', function () use ($orm, $channels, $hub, $id): void {
            $orm->transaction(function () use ($orm, $channels, $hub, $id): void {
                $script = $orm->find(Script::class, $id);
                \assert($script instanceof Script);
                $channels->publish(new ScriptRuns($script), new ScriptRunData('done'));
                expect($hub->published)->toBe([]);
            });
        });

        $topic = (string) $live->channelTopics?->for('acme', 'scripts', ['script' => $id]);
        expect($hub->published)->toHaveCount(1)
            ->and($hub->published[0]['topic'])->toBe($topic)
            ->and(json_decode($hub->published[0]['data'], true))->toBe([
                'topic' => $topic,
                'channel' => 'scripts',
                'message' => ['status' => 'done'],
            ]);
    });

    it('sends nothing for a write that is rolled back', function () {
        [$channels, $hub, , $fixture, $orm] = publisher();
        $id = $fixture->scripts['acme'][0];

        try {
            $orm->actAs('acme', function () use ($orm, $channels, $id): void {
                $orm->transaction(function () use ($orm, $channels, $id): void {
                    $script = $orm->find(Script::class, $id);
                    \assert($script instanceof Script);
                    $channels->publish(new ScriptRuns($script), new ScriptRunData('done'));

                    throw new \RuntimeException('rolled back');
                });
            });
        } catch (\RuntimeException) {
        }

        expect($hub->published)->toBe([]);
    });

    it('publishes in another tenant to a topic no subscriber of the first holds', function () {
        [$channels, $hub, $live, $fixture, $orm] = publisher();
        $id = $fixture->scripts['globex'][0];

        $orm->actAs('globex', function () use ($orm, $channels, $id): void {
            $script = $orm->find(Script::class, $id);
            \assert($script instanceof Script);
            $channels->publish(new ScriptRuns($script), new ScriptRunData('done'));
        });

        expect($hub->published[0]['topic'])->toBe((string) $live->channelTopics?->for('globex', 'scripts', ['script' => $id]))
            ->and($hub->published[0]['topic'])->not->toBe((string) $live->channelTopics?->for('acme', 'scripts', ['script' => $id]));
    });

    it('refuses a model of another tenant\'s, published into this one', function () {
        [$channels, $hub, , $fixture, $orm] = publisher();
        $theirs = $orm->actAs('globex', fn (): ?Script => $orm->find(Script::class, $fixture->scripts['globex'][0]));
        \assert($theirs instanceof Script);

        expect(fn () => $orm->actAs('acme', fn () => $channels->publish(new ScriptRuns($theirs), new ScriptRunData('done'))))
            ->toThrow(\LogicException::class, 'no row of the tenant in force');
        expect($hub->published)->toBe([]);
    });

    it('refuses to publish across tenants', function () {
        [$channels, , , $fixture, $orm] = publisher();
        $script = $orm->actAs('acme', fn (): ?Script => $orm->find(Script::class, $fixture->scripts['acme'][0]));
        \assert($script instanceof Script);

        expect(fn () => $orm->acrossTenants(fn () => $channels->publish(new ScriptRuns($script), new ScriptRunData('done'))))
            ->toThrow(\LogicException::class, 'acrossTenants()');
    });

    it('refuses a message of another class, a non-Data value inside one, and an unregistered channel', function () {
        [$channels, $hub, , $fixture, $orm] = publisher();
        $script = $orm->actAs('acme', fn (): ?Script => $orm->find(Script::class, $fixture->scripts['acme'][0]));
        \assert($script instanceof Script);

        $orm->actAs('acme', function () use ($channels, $script): void {
            expect(fn () => $channels->publish(new ScriptRuns($script), new OtherData('x')))
                ->toThrow(\LogicException::class, 'carries')
                ->and(fn () => $channels->publish(new Rooms(1), new OtherData('x')))->not->toThrow(\Throwable::class)
                ->and(fn () => $channels->publish(new Unguarded($script), new OtherData('x')))
                ->toThrow(\LogicException::class, 'not a channel the application registered');
        });

        // A channel registered with another registry is none of this one's.
        $elsewhere = new Channels(new ChannelRegistry(), \Tinvar\Channels\ChannelTopics::fromKey(KEY), $orm, $hub);
        expect(fn () => $elsewhere->publish(new Status(), new OtherData('x')))
            ->toThrow(\LogicException::class, 'not a channel the application registered');
    });

    it('refuses a Data message carrying anything but Data, through the encoder', function () {
        $fixture = new TenantFixture();
        $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
        $registry = new ChannelRegistry();
        $registry->register(Smuggled::class);
        $channels = new Channels($registry, \Tinvar\Channels\ChannelTopics::fromKey(KEY), $orm, new MemoryHub());

        expect(fn () => $channels->publish(new Smuggled(), new SmuggleData(new \stdClass())))
            ->toThrow(DataBoundaryException::class);
    });

    it('refuses a message larger than one may be', function () {
        [$channels, , , , $orm] = publisher();

        expect(fn () => $orm->actAs('acme', fn () => $channels->publish(new Status(), new OtherData(str_repeat('x', Channels::MAX_MESSAGE_BYTES)))))
            ->toThrow(\LogicException::class, 'past the');
    });
});

#[Channel('smuggled', message: SmuggleData::class), NoAuthorization(reason: 'test')]
final readonly class Smuggled {}
