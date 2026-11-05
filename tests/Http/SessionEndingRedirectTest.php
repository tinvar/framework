<?php

declare(strict_types=1);

/**
 * A redirect that ends the signed-in session the request came with is a
 * document load for the client router (ADR 0164).
 *
 * tinvar-spec.md: "Client-held data ... is cleared, with a full page load,
 * on logout, ... role change, ... and session revocation". An action that
 * replaced a signed-in session -- a password reset, another account's
 * sign-in link, used where someone was signed in -- answered with
 * ActionResult::redirect(), which reached the router as 409 + X-Redirect, a
 * client visit. The page being left held a live stream for the session the
 * action ended; its next trade was refused with a 401, whose answer -- end
 * the session here, interrupt what is in flight, load this page again --
 * interrupted the visit, and landed the browser back on the link's page,
 * the link spent. The Kernel, which already sees every request that moved
 * who the browser is to take the hub's grant away, answers such a redirect
 * with X-Location instead, which the client loads as a document and leaves
 * to arrive. A sign-in from signed out ends no signed-in session: it keeps
 * its client visit.
 */

namespace Tinvar\Tests\Http\SessionEndingRedirect;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

const KEY = 'session-ending-redirect-test-application-key-0123';
const SESSION_COOKIE = '__Host-test_session';

final readonly class Person implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/**
 * The session, as the accounts kit's StartSession keeps one: the cookie
 * names a session, the session whom it signs in and at which epoch, and an
 * action that rotates it has the response set the new cookie.
 */
final class Sessions implements Middleware
{
    public static ?AuthContext $auth = null;

    /** @var array<string, array{?string, string}> session id => user, epoch */
    public static array $sessions = [];

    /** The session an action rotated this request to; null when none did. */
    public static ?string $rotated = null;

    public function handle(Request $request, \Closure $next): Response
    {
        self::$rotated = null;
        [$user, $epoch] = self::$sessions[$request->cookie(SESSION_COOKIE) ?? ''] ?? [null, ''];
        self::$auth?->login($user === null ? null : new Person($user), $epoch);

        $response = $next($request);

        return self::$rotated === null ? $response : $response->withCookie(SESSION_COOKIE, self::$rotated);
    }

    /** Rotates the session to a new id, signed in as $user at $epoch -- or as nobody. */
    public static function rotate(?string $user, string $epoch = ''): void
    {
        self::$rotated = 'session-' . bin2hex(random_bytes(4));
        self::$sessions[self::$rotated] = [$user, $epoch];
        self::$auth?->login($user === null ? null : new Person($user), $epoch);
    }
}

final class Moves
{
    #[Action(name: 'moves.signOut'), NoAuthorization(reason: 'a fixture')]
    public function signOut(): ActionResult
    {
        Sessions::rotate(null);

        return ActionResult::redirect('/goodbye');
    }

    /** Another account's sign-in link: used where someone was signed in, or nobody. */
    #[Action(name: 'moves.signInAsBob'), PublicAccess('a sign-in'), NoAuthorization(reason: 'a fixture')]
    public function signInAsBob(): ActionResult
    {
        Sessions::rotate('bob', 'b1');

        return ActionResult::redirect('/orders')->withTags('orders');
    }

    /** A password reset: the same account, under a new session id and epoch. */
    #[Action(name: 'moves.reset'), NoAuthorization(reason: 'a fixture')]
    public function reset(): ActionResult
    {
        Sessions::rotate('alice', 'e2');

        return ActionResult::redirect('/orders');
    }

    /**
     * A new session id alone -- the same account at the same epoch: the
     * session cookie is all that tells the response moved who the browser is.
     */
    #[Action(name: 'moves.renew'), NoAuthorization(reason: 'a fixture')]
    public function renew(): ActionResult
    {
        Sessions::rotate('alice', 'e1');

        return ActionResult::redirect('/orders');
    }

    /** A role change: the session kept, the epoch moved. */
    #[Action(name: 'moves.promote'), NoAuthorization(reason: 'a fixture')]
    public function promote(): ActionResult
    {
        Sessions::$auth?->login(new Person('alice'), 'e2');

        return ActionResult::redirect('/orders');
    }

    #[Action(name: 'moves.nothing'), NoAuthorization(reason: 'a fixture')]
    public function nothing(): ActionResult
    {
        return ActionResult::redirect('/orders');
    }
}

/** @return array{Kernel, MemoryHub} */
function kernel(bool $live = true): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $auth = new AuthContext();
    Sessions::$auth = $auth;
    Sessions::$sessions = ['session-alice' => ['alice', 'e1'], 'session-visitor' => [null, '']];
    $actions = new ActionRegistry();
    $actions->register(Moves::class);
    $hub = new MemoryHub();

    return [new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [Sessions::class],
        live: $live ? LiveUpdates::fromKey(KEY, $hub, static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '') : null,
    ), $hub];
}

/** Calls $action under $session, from script through the client router or as a browser's own form post. */
function call(Kernel $kernel, string $action, string $session, bool $script = true): Response
{
    $headers = $script
        ? ['content-type' => 'application/json', 'accept' => 'application/json', 'rsc' => '1']
        : ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html,application/xhtml+xml'];

    return $kernel->handle(
        new Request(
            'POST',
            "/_action/{$action}",
            [...$headers, 'x-xsrf-token' => 'tok'],
            [],
            [],
            [VerifyCsrfToken::COOKIE => 'tok', SESSION_COOKIE => $session],
        ),
        static fn (): Response => throw new \LogicException('no page here'),
        $script ? '{}' : '',
    );
}

describe('a redirect that ends the signed-in session the request came with', function () {
    it('sends the client router on with a document load, not a visit', function (string $action, string $to) {
        [$kernel, $hub] = kernel();

        $response = call($kernel, $action, 'session-alice');

        expect($response->status)->toBe(409)
            ->and($response->header('x-location'))->toBe($to)
            ->and($response->header('x-redirect'))->toBeNull()
            // What the redirect does besides, carried as ever: whatever the
            // hub was letting the old session hear is taken away.
            ->and($response->header('x-test-hub-revoked'))->toBe('1')
            ->and($hub->revoked)->toBe(1);
    })->with([
        'a sign-out' => ['moves.signOut', '/goodbye'],
        'a sign-in as someone else' => ['moves.signInAsBob', '/orders'],
        'a reset: the same account, a new session and epoch' => ['moves.reset', '/orders'],
        'a new session id alone' => ['moves.renew', '/orders'],
        'a role change: the session kept, the epoch moved' => ['moves.promote', '/orders'],
    ]);

    it('carries the session cookie and the invalidated tags onto the document load', function () {
        [$kernel] = kernel();

        $response = call($kernel, 'moves.signInAsBob', 'session-alice');
        $cookie = explode(';', $response->cookies()[SESSION_COOKIE] ?? '', 2)[0];

        expect($response->header('x-location'))->toBe('/orders')
            ->and(Sessions::$sessions[substr($cookie, \strlen(SESSION_COOKIE) + 1)] ?? null)->toBe(['bob', 'b1'])
            ->and($response->header('x-invalidated-tags'))->toBe('orders');
    });

    it('is told by who is signed in alone, where the application gave the Kernel no session to read', function () {
        [$kernel] = kernel(live: false);

        expect(call($kernel, 'moves.signOut', 'session-alice')->header('x-location'))->toBe('/goodbye')
            ->and(call($kernel, 'moves.promote', 'session-alice')->header('x-location'))->toBe('/orders')
            // A new id for the same account at the same epoch cannot be told
            // without one: the visit it was before.
            ->and(call($kernel, 'moves.renew', 'session-alice')->header('x-redirect'))->toBe('/orders');
    });

    it('sends a browser\'s own form post on with the 303 as ever', function () {
        [$kernel] = kernel();

        $response = call($kernel, 'moves.reset', 'session-alice', script: false);

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/orders')
            ->and($response->header('x-location'))->toBeNull();
    });
});

describe('a redirect that ends no signed-in session', function () {
    it('keeps the client visit for a sign-in from signed out', function () {
        [$kernel, $hub] = kernel();

        $response = call($kernel, 'moves.signInAsBob', 'session-visitor');

        expect($response->status)->toBe(409)
            ->and($response->header('x-redirect'))->toBe('/orders')
            ->and($response->header('x-location'))->toBeNull()
            // The grant the signed-out session had goes all the same.
            ->and($hub->revoked)->toBe(1);
    });

    it('keeps the client visit for an action that moved nobody', function () {
        [$kernel, $hub] = kernel();

        $response = call($kernel, 'moves.nothing', 'session-alice');

        expect($response->status)->toBe(409)
            ->and($response->header('x-redirect'))->toBe('/orders')
            ->and($response->header('x-location'))->toBeNull()
            ->and($hub->revoked)->toBe(0);
    });
});
