<?php

declare(strict_types=1);

/**
 * The binding client-held data is held under, through the Kernel: the
 * session, and who is signed in at which auth epoch.
 *
 * tinvar-spec.md, "Client-held data": bound to (user, tenant), and "cleared
 * ... on role change, impersonation start and stop". The binding was the
 * session alone, standing in for the user because a session id is rotated
 * whenever who it belongs to changes. A role change or an impersonation that
 * keeps the session id kept everything drawn under the old role, and the
 * server went on honouring #[Once] claims made for it. The route token now
 * names the user and the auth epoch the application keeps for them
 * (AuthContext), and a claim counts only for the same.
 */

namespace Tinvar\Tests\Http\AuthBinding;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Once;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

final class Resolved
{
    public static int $times = 0;
}

final readonly class DashboardData extends Data
{
    /** @param Lazy<string> $figures */
    public function __construct(#[Once(key: 'dashboard.figures')] public Lazy $figures) {}
}

#[Page('/dashboard', view: 'dashboard', name: 'dashboard'), NoAuthorization(reason: 'a test page')]
final class DashboardPage
{
    public function __invoke(): DashboardData
    {
        return new DashboardData(Lazy::of(static function (): string {
            Resolved::$times++;

            return 'figures';
        }));
    }
}

const KEY = 'auth-binding-test-key-0123456789abcdef';

/** @return array{Kernel, AuthContext} */
function kernel(): array
{
    Resolved::$times = 0;
    $routes = new RouteRegistry();
    $routes->registerAll([DashboardPage::class]);
    $auth = new AuthContext();

    return [new Kernel(
        orm: new Orm(new Fixture(racks: 1, unitsPerRack: 1, sites: 1)->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new AllowAllGate(),
        fragments: new FragmentSupport(
            signer: new Signer(KEY, 'fragment'),
            builds: new BuildRegistry('b1'),
            render: static fn (): mixed => null,
            sessionId: static fn (Request $r): string => 'session-a',
            routeSigner: new Signer(KEY, RouteToken::PURPOSE),
        ),
    ), $auth];
}

/**
 * The dashboard's payload and route token, for whoever the AuthContext says.
 *
 * @param array<string, string> $headers
 * @return array{array<string, mixed>, string}
 */
function visit(Kernel $kernel, array $headers = []): array
{
    $response = $kernel->handle(
        Request::get('/dashboard', ['RSC' => '1', 'X-Version' => 'b1', ...$headers]),
        static fn (LoadedRoute $loaded, Request $r): Response => Response::make(
            200,
            json_encode($loaded->encode(new Encoder()), JSON_THROW_ON_ERROR),
            ['content-type' => 'application/json'],
        ),
    );
    expect($response->status)->toBe(200, $response->body());

    /** @var array{page: array<string, mixed>} $data */
    $data = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    return [$data['page'], (string) $response->header('x-route-token')];
}

function claims(string $token): TokenClaims
{
    return RouteToken::decode($token, new Signer(KEY, RouteToken::PURPOSE))->claims;
}

describe('the route token', function () {
    it('names who is signed in, and at which auth epoch, beside the session', function () {
        [$kernel, $auth] = kernel();
        $auth->login(new Member('m-1'), '1');
        [, $one] = visit($kernel);
        $auth->login(new Member('m-1'), '2');
        [, $promoted] = visit($kernel);
        $auth->login(new Member('m-2'), '1');
        [, $other] = visit($kernel);
        $auth->login(null);
        [, $guest] = visit($kernel);

        $hashes = array_map(static fn (string $t): string => claims($t)->authHash, [$one, $promoted, $other, $guest]);
        expect(array_unique(array_map(static fn (string $t): string => claims($t)->sessionHash, [$one, $promoted, $other, $guest])))->toHaveCount(1)
            ->and(array_unique($hashes))->toHaveCount(4)
            ->and($hashes[0])->toMatch('/^[0-9a-f]{32}$/');
    });

    // Readable in page source: a hash, never the user's id or the epoch.
    it('carries neither the user\'s id nor the epoch as they are', function () {
        [$kernel, $auth] = kernel();
        $auth->login(new Member('member-4242'), 'epoch-77');

        [, $token] = visit($kernel);

        $body = (string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true);
        expect($body)->not->toContain('member-4242')
            ->and($body)->not->toContain('epoch-77');
    });
});

describe('a #[Once] claim', function () {
    it('counts for the same user at the same auth epoch', function () {
        [$kernel, $auth] = kernel();
        $auth->login(new Member('m-1'), '1');
        [, $token] = visit($kernel);

        $auth->login(new Member('m-1'), '1');
        [$page] = visit($kernel, ['X-Have-Once' => 'dashboard.figures', 'X-Once-Binding' => $token]);

        expect($page['figures'])->toBe(['$once' => 'dashboard.figures', 'held' => true])
            ->and(Resolved::$times)->toBe(1);
    });

    // A role changed, the session id kept: the figures were drawn for what
    // the old role could see.
    it('counts for nothing once the auth epoch has moved, in the same session', function () {
        [$kernel, $auth] = kernel();
        $auth->login(new Member('m-1'), '1');
        [, $token] = visit($kernel);

        $auth->login(new Member('m-1'), '2');
        [$page] = visit($kernel, ['X-Have-Once' => 'dashboard.figures', 'X-Once-Binding' => $token]);

        expect($page['figures'])->toHaveKey('value')
            ->and(Resolved::$times)->toBe(2);
    });

    // Impersonation started: the session is the support agent's, the user
    // the customer's.
    it('counts for nothing for another user in the same session', function () {
        [$kernel, $auth] = kernel();
        $auth->login(new Member('agent'), '1');
        [, $token] = visit($kernel);

        $auth->login(new Member('customer'), '1');
        [$page] = visit($kernel, ['X-Have-Once' => 'dashboard.figures', 'X-Once-Binding' => $token]);

        expect($page['figures'])->toHaveKey('value');
    });
});

describe('the claims', function () {
    // A fragment token binds to the session alone, and reads as it did.
    it('read a token with no user binding as bound to none', function () {
        $claims = TokenClaims::fromMap(['r' => 'x', 'a' => [], 'n' => '', 'y' => '', 'b' => 'b1', 's' => 'h', 'e' => 2, 'i' => 1]);

        expect($claims->authHash)->toBe('')
            ->and($claims->toMap())->not->toHaveKey('u');
    });

    it('keep the user binding across a refresh', function () {
        $claims = new TokenClaims('x', [], '', '', 'b1', 'h', time() + 60, time(), authHash: 'abc');

        expect($claims->refreshed(3600, 7200)?->authHash)->toBe('abc');
    });
});
