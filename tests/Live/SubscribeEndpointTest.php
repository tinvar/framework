<?php

declare(strict_types=1);

/**
 * /_live/subscribe: live tokens traded for the hub's access to their topics.
 *
 * The hub checks a signature and an expiry, never a session, so this is
 * where the binding is held: a token is refused for another session, or for
 * another user or auth epoch under this one, and the refusal is the 401 that
 * ends the session on the client. The grant names the topics of the tokens
 * that survived, and only those.
 */

namespace Tinvar\Tests\Live\SubscribeEndpoint;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveToken;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

const KEY = 'subscribe-endpoint-test-key-0123456789abcdef';
const SESSION_COOKIE = '__Host-test_session';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

final readonly class TitleData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/orders', view: 'orders', name: 'orders.index'), NoAuthorization(reason: 'a test page')]
final class OrdersPage
{
    public function __invoke(): TitleData
    {
        return new TitleData('Orders');
    }
}

/** @return array{SubscribeEndpoint, MemoryHub, LiveUpdates, AuthContext} */
function endpoint(?BuildRegistry $builds = null): array
{
    $hub = new MemoryHub();
    $live = LiveUpdates::fromKey(KEY, $hub, static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '');
    $auth = new AuthContext();
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'), tenants: Tenancy::flat());
    $routes = new RouteRegistry();
    $routes->register(OrdersPage::class);
    $resolver = static fn (string $class): object => new $class();

    return [new SubscribeEndpoint(
        live: $live,
        auth: $auth,
        orm: $orm,
        routes: $routes,
        loaders: new LoaderRunner($orm, $resolver, new AllowAllGate()),
        entry: new EntryContext($orm, $resolver, auth: $auth),
        middlewareResolver: static function (string $class): Middleware {
            $middleware = new $class();
            \assert($middleware instanceof Middleware);

            return $middleware;
        },
        builds: $builds ?? new BuildRegistry('b1'),
    ), $hub, $live, $auth];
}

/**
 * @param list<string> $read namespaced tags
 */
function token(LiveUpdates $live, array $read, string $session = 'session-a', ?string $user = 'u-1', string $epoch = 'e1', string $tenant = '', string $panel = '', string $build = 'b1'): string
{
    return (string) new LiveMinter(
        signer: $live->signer,
        topics: $live->topics,
        route: 'orders.index',
        params: [],
        panel: $panel,
        tenant: $tenant,
        build: $build,
        sessionId: $session,
        user: $user,
        authEpoch: $epoch,
    )->mint($read);
}

/** @param list<mixed> $tokens */
function trade(SubscribeEndpoint $endpoint, array $tokens, string $session = 'session-a', ?int $now = null): Response
{
    $request = new Request('POST', SubscribeEndpoint::PATH, ['content-type' => 'application/json'], cookies: [SESSION_COOKIE => $session]);

    return $endpoint->handle($request, json_encode(['tokens' => $tokens], JSON_THROW_ON_ERROR), $now);
}

/** @return array<string, mixed> */
function body(Response $response): array
{
    /** @var array<string, mixed> */
    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
}

describe('a trade', function () {
    it('grants the topics of every token the session holds, and hands each back refreshed', function () {
        [$endpoint, $hub, $live, $auth] = endpoint();
        $auth->login(new Member('u-1'), 'e1');
        $page = token($live, ["\0orders:customer:42"]);
        $fragment = token($live, ["\0shipping:7", "\0orders:customer:42"]);

        $response = trade($endpoint, [$page, $fragment]);
        $body = body($response);

        $topics = [$live->topics->of("\0orders:customer:42"), $live->topics->of("\0shipping:7")];
        sort($topics);

        expect($response->status)->toBe(200)
            ->and($body['topics'])->toBe($topics)
            ->and($body['url'])->toBe($hub->subscribeUrl($topics))
            ->and($hub->grants)->toHaveCount(1)
            ->and($hub->grants[0]['topics'])->toBe($topics)
            ->and($hub->grants[0]['subject'])->toBe(TokenClaims::hashSession($live->signer, 'session-a'))
            ->and($response->header('cache-control'))->toBe('private, no-store');

        foreach ([0, 1] as $i) {
            $refreshed = LiveToken::decode($body['tokens'][$i], $live->signer);
            expect($refreshed->topics)->toBe(LiveToken::decode([$page, $fragment][$i], $live->signer)->topics);
        }
    });

    it('lasts no longer than one grant, nor past the end of the tokens\' lifetime', function () {
        [$endpoint, $hub, $live, $auth] = endpoint();
        $auth->login(new Member('u-1'), 'e1');
        $token = token($live, ["\0orders"]);
        $issued = LiveToken::decode($token, $live->signer)->claims->issuedAt;

        $now = $issued + 5;
        $body = body(trade($endpoint, [$token], now: $now));
        expect($body['expiresAt'])->toBe($now + SubscribeEndpoint::GRANT_SECONDS);

        // A minute before the token's absolute lifetime ends: the grant ends
        // with it, not five minutes on. Traded along the way, as a client
        // does, so the token is never past its own expiry.
        $late = $issued + LiveToken::MAX_LIFETIME - 60;
        $current = $token;
        for ($at = $issued + 500; $at < $late; $at += 500) {
            $current = body(trade($endpoint, [$current], now: $at))['tokens'][0];
        }
        $body = body(trade($endpoint, [$current], now: $late));
        expect($body['expiresAt'])->toBe($issued + LiveToken::MAX_LIFETIME)
            ->and($hub->grants[array_key_last($hub->grants)]['expiresAt'])->toBe($issued + LiveToken::MAX_LIFETIME);
    });
});

describe('the binding', function () {
    it('refuses a token minted for another session, with the 401 that ends the session, and takes the grant away', function () {
        [$endpoint, $hub, $live, $auth] = endpoint();
        $auth->login(new Member('u-1'), 'e1');
        $theirs = token($live, ["\0orders"], session: 'session-b');

        $response = trade($endpoint, [$theirs], session: 'session-a');

        expect($response->status)->toBe(401)
            ->and(body($response))->toBe(['error' => ['code' => 'session_changed']])
            ->and($hub->grants)->toBe([])
            ->and($hub->revoked)->toBe(1);
    });

    it('refuses one minted for another user, or another auth epoch, under the same session', function (?string $user, string $epoch) {
        [$endpoint, $hub, $live, $auth] = endpoint();
        $auth->login(new Member('u-1'), 'e1');

        $response = trade($endpoint, [token($live, ["\0orders"], user: $user, epoch: $epoch)]);

        expect($response->status)->toBe(401)
            ->and($hub->grants)->toBe([]);
    })->with([
        'another user' => ['u-2', 'e1'],
        'a role changed since' => ['u-1', 'e0'],
        'signed out when it was minted' => [null, ''],
    ]);

    it('refuses one held after signing out: the user it names is no longer here', function () {
        [$endpoint, $hub, $live, $auth] = endpoint();
        $token = token($live, ["\0orders"]);
        $auth->login(null);

        expect(trade($endpoint, [$token])->status)->toBe(401);
    });

    it('refuses every token to a request with no session', function () {
        [$endpoint, $hub, $live] = endpoint();

        $response = trade($endpoint, [token($live, ["\0orders"])], session: '');

        expect($response->status)->toBe(401)
            ->and(body($response))->toBe(['error' => ['code' => 'no_session']]);
    });
});

describe('a token that cannot be used', function () {
    it('is answered with null in its place, its topics not granted, whatever the reason', function () {
        [$endpoint, $hub, $live, $auth] = endpoint(new BuildRegistry('b2', ['b1']));
        $auth->login(new Member('u-1'), 'e1');
        $good = token($live, ["\0orders"], build: 'b2');
        $forged = substr($good, 0, -2) . 'xx';
        $revokedBuild = token($live, ["\0revoked"], build: 'b0');
        $otherTenant = token($live, ["t-9\0orders"], tenant: 't-9', build: 'b2');

        $body = body(trade($endpoint, [$good, $forged, 'not a token', 42, $revokedBuild, $otherTenant]));

        expect($body['tokens'][0])->toBeString()
            ->and(\array_slice($body['tokens'], 1))->toBe([null, null, null, null, null])
            ->and($body['topics'])->toBe([$live->topics->of("\0orders")]);
    });

    // Each token within its cap, together past what one grant's cookie can
    // carry: granted, the browser dropped the cookie, the stream was refused,
    // and the client traded again and again. Granted nothing, it stops.
    it('grants nothing when the tokens together name more topics than one grant may carry', function () {
        [$endpoint, $hub, $live, $auth] = endpoint();
        $auth->login(new Member('u-1'), 'e1');
        $half = intdiv(LiveToken::MAX_TOPICS, 2) + 1;
        $page = token($live, array_map(static fn (int $i): string => "\0page:{$i}", range(1, $half)));
        $fragment = token($live, array_map(static fn (int $i): string => "\0fragment:{$i}", range(1, $half)));

        $body = body(trade($endpoint, [$page, $fragment]));

        expect($body['topics'])->toBe([])
            ->and($body['url'])->toBeNull()
            ->and($body['tokens'])->toHaveCount(2)
            ->and($hub->grants)->toBe([])
            ->and($hub->revoked)->toBe(1);
    });

    it('takes away whatever an earlier grant allowed when nothing is left to grant', function () {
        [$endpoint, $hub, $live, $auth] = endpoint();
        $auth->login(new Member('u-1'), 'e1');

        $body = body(trade($endpoint, ['v1.e30.AAAA']));

        expect($body)->toBe(['tokens' => [null], 'topics' => [], 'url' => null, 'expiresAt' => null])
            ->and($hub->revoked)->toBe(1)
            ->and($hub->grants)->toBe([]);
    });
});

describe('the request', function () {
    it('is POST only', function () {
        [$endpoint] = endpoint();

        $endpoint->handle(new Request('GET', SubscribeEndpoint::PATH));
    })->throws(HttpException::class, 'POST only');

    it('carries a list of tokens, and not too many', function (mixed $tokens) {
        [$endpoint] = endpoint();

        try {
            trade($endpoint, $tokens);
            $this->fail('accepted');
        } catch (HttpException $e) {
            expect($e->status)->toBe(400);
        }
    })->with([
        'none' => [[]],
        'too many' => [array_fill(0, SubscribeEndpoint::MAX_TOKENS + 1, 'v1.a.b')],
    ]);
});
