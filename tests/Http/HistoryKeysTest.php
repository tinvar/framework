<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\HistoryEncryption;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\EncryptHistory;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\HistoryKeys;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\PageMetadata;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Support\Signer;
use Tinvar\Tests\Auth\Fixtures\User as AuthUser;

require_once __DIR__ . '/../Auth/Fixtures/policies.php';

/**
 * History encryption, the server's half: the session-scoped key, which pages
 * are handed it, and how it reaches the client.
 *
 * tinvar-spec.md, "Security": "History encryption: on by default for
 * authenticated routes, including remembered form state, using
 * `crypto.subtle` and a session-scoped key. `#[EncryptHistory(false)]` opts
 * a page out." See ADR 0019.
 */
const SESSION_COOKIE = '__Host-test_session';
const KEY = 'history-keys-test-application-key!';

function keys(string $secret = KEY): HistoryKeys
{
    return new HistoryKeys(
        new Signer($secret, HistoryKeys::PURPOSE),
        static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '',
    );
}

function in(string $session): Request
{
    return new Request('GET', '/', cookies: [SESSION_COOKIE => $session]);
}

final readonly class AccountData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/account', view: 'account/show', name: 'account.show')]
final class AccountPage
{
    public function __invoke(): AccountData
    {
        return new AccountData('Account');
    }
}

#[Page('/account/statement', view: 'account/statement', name: 'account.statement'), EncryptHistory(false)]
final class StatementPage
{
    public function __invoke(): AccountData
    {
        return new AccountData('Statement');
    }
}

#[Page('/kiosk', view: 'kiosk/show', name: 'kiosk.show'), EncryptHistory]
final class KioskPage
{
    public function __invoke(): AccountData
    {
        return new AccountData('Kiosk');
    }
}

#[Layout('/settings', view: 'settings/layout'), EncryptHistory(false)]
final class SettingsLayout
{
    public function __invoke(): AccountData
    {
        return new AccountData('Settings');
    }
}

/** @return array{Kernel, AuthContext} */
function kernel(?HistoryKeys $keys = null): array
{
    $routes = new RouteRegistry();
    $routes->registerAll([AccountPage::class, StatementPage::class, KioskPage::class]);
    $auth = new AuthContext();

    return [new Kernel(
        orm: new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        historyKeys: $keys,
    ), $auth];
}

/** A page, recording the request its responder was handed. */
function page(?Request &$seen = null): \Closure
{
    return static function (LoadedRoute $loaded, Request $request) use (&$seen): Response {
        $seen = $request;

        return Response::make(200, '<html></html>');
    };
}

/** @param array<string, string> $headers */
function visit(string $path, array $headers = [], string $session = 'session-a'): Request
{
    return new Request('GET', $path, $headers, cookies: [SESSION_COOKIE => $session]);
}

const CLIENT = ['rsc' => '1', 'accept' => 'text/x-component'];

describe('the key', function () {
    it('is 32 bytes, base64url, the same for the same session every time it is asked', function () {
        $key = keys()->for(in('session-a'));

        expect($key)->toBeString()
            ->and(\strlen((string) Signer::decode((string) $key)))->toBe(32)
            ->and(keys()->for(in('session-a')))->toBe($key);
    });

    // A new id on sign-in and sign-out is a new key, so what was sealed under
    // the old one cannot be opened by anything handed to the new session.
    it('is another key for another session', function () {
        expect(keys()->for(in('session-a')))->not->toBe(keys()->for(in('session-b')));
    });

    // The same three things a route token's binding names.
    it('is another key in another tenant or panel', function () {
        $base = keys()->for(in('session-a'), 'tenant-1', 'shop');

        expect(keys()->for(in('session-a'), 'tenant-2', 'shop'))->not->toBe($base)
            ->and(keys()->for(in('session-a'), 'tenant-1', 'admin'))->not->toBe($base)
            ->and(keys()->for(in('session-a'), 'tenant-1shop', ''))->not->toBe($base);
    });

    it('is none without a session: one key for every visitor without one would be anyone\'s', function () {
        expect(keys()->for(new Request('GET', '/')))->toBeNull();
    });

    // Not derivable without the application key, nor equal to anything
    // else that key signs for the same session.
    it('takes the application key, and is no other MAC of the session', function () {
        $csrf = new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '');

        expect(keys('another-application-key-of-32bytes')->for(in('session-a')))->not->toBe(keys()->for(in('session-a')))
            ->and($csrf->for(in('session-a')))->not->toBe(keys()->for(in('session-a')));
    });
});

describe('which pages carry it', function () {
    it('hands a page rendered for a signed-in user its session\'s key', function () {
        [$kernel, $auth] = kernel(keys());
        $auth->login(new AuthUser('u-1'));

        $kernel->handle(visit('/account'), page($seen));

        expect($seen?->attribute(Kernel::HISTORY_KEY))->toBe(keys()->for(in('session-a')));
    });

    it('hands none to a page rendered for a visitor who is not signed in', function () {
        [$kernel] = kernel(keys());

        $response = $kernel->handle(visit('/account', CLIENT), page($seen));

        expect($seen?->attribute(Kernel::HISTORY_KEY))->toBeNull()
            ->and($response->header(HistoryKeys::HEADER))->toBeNull();
    });

    it('hands none to a page that opts out with #[EncryptHistory(false)], signed in or not', function () {
        [$kernel, $auth] = kernel(keys());
        $auth->login(new AuthUser('u-1'));

        $response = $kernel->handle(visit('/account/statement', CLIENT), page($seen));

        expect($seen?->attribute(Kernel::HISTORY_KEY))->toBeNull()
            ->and($response->header(HistoryKeys::HEADER))->toBeNull();
    });

    it('hands a page that says #[EncryptHistory] its key though no one is signed in', function () {
        [$kernel] = kernel(keys());

        $kernel->handle(visit('/kiosk'), page($seen));

        expect($seen?->attribute(Kernel::HISTORY_KEY))->toBe(keys()->for(in('session-a')));
    });

    it('hands none to anyone without a session, whatever the page says', function () {
        [$kernel] = kernel(keys());

        $kernel->handle(new Request('GET', '/kiosk'), page($seen));

        expect($seen?->attribute(Kernel::HISTORY_KEY))->toBeNull();
    });

    it('hands none to anyone when the application issues no history keys', function () {
        [$kernel, $auth] = kernel(null);
        $auth->login(new AuthUser('u-1'));

        $response = $kernel->handle(visit('/account', CLIENT), page($seen));

        expect($seen?->attribute(Kernel::HISTORY_KEY))->toBeNull()
            ->and($response->header(HistoryKeys::HEADER))->toBeNull();
    });
});

describe('how it reaches the client', function () {
    // A document cannot read headers; its page metadata carries the key.
    it('goes to a client navigation as a header, and to a document only through its metadata', function () {
        [$kernel, $auth] = kernel(keys());
        $key = keys()->for(in('session-a'));

        $auth->login(new AuthUser('u-1'));
        $payload = $kernel->handle(visit('/account', CLIENT), page());
        $auth->login(new AuthUser('u-1'));
        $document = $kernel->handle(visit('/account', ['accept' => 'text/html']), page());

        expect($payload->header(HistoryKeys::HEADER))->toBe($key)
            ->and($document->header(HistoryKeys::HEADER))->toBeNull();
    });

    // A prefetched page is mounted later, from the cache, and is sealed
    // with the key it came with.
    it('goes with a prefetched page too', function () {
        [$kernel, $auth] = kernel(keys());
        $auth->login(new AuthUser('u-1'));

        $response = $kernel->handle(visit('/account', [...CLIENT, 'purpose' => 'prefetch']), page());

        expect($response->header(HistoryKeys::HEADER))->toBe(keys()->for(in('session-a')));
    });

    it('is never left for a shared cache to keep', function () {
        [$kernel, $auth] = kernel(keys());
        $auth->login(new AuthUser('u-1'));

        $response = $kernel->handle(visit('/account', CLIENT), page());

        expect($response->header('cache-control'))->toBe('private, no-store')
            ->and($response->header('vary'))->toContain('Cookie');
    });

    it('is written into the page metadata only for a page that has one', function () {
        $args = ['routeId' => 'account/show', 'params' => [], 'loaderData' => [], 'requestId' => 'r', 'cspNonce' => 'n', 'allowedQueries' => []];

        $with = Frame::render(...$args, page: new PageMetadata('/account', 'b1', 'v1.t', 'account/show', 'a2V5'));
        $without = Frame::render(...$args, page: new PageMetadata('/account', 'b1', 'v1.t', 'account/show'));

        expect($with['page']['historyKey'] ?? null)->toBe('a2V5')
            ->and($without['page'])->not->toHaveKey('historyKey');
    });
});

describe('#[EncryptHistory]', function () {
    it('is compiled into the page it is on', function () {
        $routes = new RouteRegistry();

        expect($routes->register(StatementPage::class)->encryptHistory)->toBeFalse()
            ->and($routes->register(KioskPage::class)->encryptHistory)->toBeTrue()
            ->and($routes->register(AccountPage::class)->encryptHistory)->toBeNull();
    });

    // "opts a page out": on a layout it would opt out nothing, and look as
    // though it had.
    it('is refused on a layout', function () {
        new RouteRegistry()->register(SettingsLayout::class);
    })->throws(RoutingException::class, 'read off pages only');
});
