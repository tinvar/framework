<?php

declare(strict_types=1);

/**
 * The shop on two hosts: the admin panel on admin.shop.test, staff signing
 * in on shop.test and handed across with a one-time code (ADR 0039) -- no
 * cookie shared between the two, a new session made on the admin host, and
 * each session good on its own host alone. The page at the end is rendered
 * by the real renderer; that test is skipped when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\ShopHandoff;

use Shop\Auth\AuthEpochs;
use Shop\Auth\StaffMemberships;
use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\StaffAccount;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\HandoffConsent;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'shop-handoff-test-key-of-32-bytes!!!!';

const SHOP = 'https://shop.test';

const ADMIN = 'https://admin.shop.test';

/** A seeded database, removed when the process ends. */
function database(): string
{
    $path = sys_get_temp_dir() . '/tinvar-shop-handoff-' . bin2hex(random_bytes(4)) . '.sqlite';
    new Seeder(PdoConnection::sqlite($path, 'shop', create: true))->run(customers: 3);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (['', '-wal', '-shm', '-throttle', '-throttle-wal', '-throttle-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    });

    return $path;
}

/** The shop's host, where everyone signs in, and the admin panel's own. */
function realms(): Realms
{
    return new Realms(
        Realm::platform(SHOP),
        [Realm::platform(ADMIN, panel: StaffMemberships::PANEL)],
        platformPanels: [StaffMemberships::PANEL],
    );
}

function kernel(string $database, string $socket = '/tmp/tinvar-shop-handoff-none.sock'): Kernel
{
    return Kernel::fromEnvironment($socket, key: KEY, database: $database, realms: realms());
}

/** A browser: a cookie jar per host, following redirects between them. */
final class Browser
{
    /** @var array<string, array<string, string>> host => cookie => value */
    public array $jar = [];

    /** @var list<string> host and path of each request, in order */
    public array $trail = [];

    public function __construct(public readonly Kernel $kernel) {}

    public function get(string $url): Response
    {
        $parts = parse_url($url);
        \assert(\is_array($parts));
        $host = $parts['host'] ?? '';
        $raw = $parts['query'] ?? '';
        parse_str($raw, $query);
        /** @var array<string, string> $query */
        $this->trail[] = $host . ($parts['path'] ?? '/');

        return $this->keep($host, $this->kernel->handle(new Request(
            'GET',
            $parts['path'] ?? '/',
            ['host' => $host, 'accept' => 'text/html'],
            $query,
            [],
            $this->jar[$host] ?? [],
            $raw,
            '127.0.0.1',
        )));
    }

    /** $url and every redirect after it, until a response that is not one. */
    public function follow(string $url, int $hops = 8): Response
    {
        $response = $this->get($url);
        while ($hops-- > 0 && \in_array($response->status, [302, 303], true)) {
            $location = (string) $response->header('location');
            $url = str_starts_with($location, 'https://') ? $location : 'https://' . parse_url($url, PHP_URL_HOST) . $location;
            $response = $this->get($url);
        }

        return $response;
    }

    /** Signs in through the shop's own form, on the shop's host. */
    public function signIn(string $email, string $password, string $next = '/'): Response
    {
        $this->get(SHOP . '/');
        $token = (string) new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), static fn (Request $r): string => session($r))
            ->for(new Request('GET', '/', cookies: $this->jar['shop.test']));

        return $this->keep('shop.test', $this->kernel->handle(
            new Request(
                'POST',
                '/_action/account.signIn',
                ['host' => 'shop.test', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->jar['shop.test'], '__Host-XSRF-TOKEN' => $token],
                null,
                '127.0.0.1',
            ),
            json_encode(['email' => $email, 'password' => $password, 'next' => $next], JSON_THROW_ON_ERROR),
        ));
    }

    /** The session id this browser holds on $host. */
    public function sessionAt(string $host): string
    {
        return session(new Request('GET', '/', cookies: $this->jar[$host] ?? []));
    }

    private function keep(string $host, Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->jar[$host][$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

function session(Request $request): string
{
    return Kernel::sessionFrom($request->cookie(Kernel::SESSION_COOKIE), KEY) ?? '';
}

function orm(string $database): Orm
{
    return new Orm(PdoConnection::sqlite($database, 'shop'), new IdentityMap('shop'));
}

function staffId(Orm $orm): string
{
    return (string) $orm->connection()->select('SELECT id FROM customers WHERE email = ?', [StaffAccount::EMAIL])[0]['id'];
}

it('hands staff signed in on the shop\'s host to the admin host, where the admin panel lets them in', function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        $this->markTestSkipped($unbuilt);
    }

    $database = database();
    $orm = orm($database);
    $renderer = Renderer::start('msgpack', 'shop-handoff');
    $browser = new Browser(kernel($database, $renderer->socketPath));

    try {
        expect($browser->signIn(StaffAccount::EMAIL, StaffAccount::PASSWORD)->status)->toBe(303);
        $epoch = new AuthEpochs($orm)->of(staffId($orm));
        $browser->trail = [];

        $page = $browser->follow(ADMIN . '/admin/orders');
    } finally {
        $renderer->stop();
    }

    expect($page->status)->toBe(200, $page->body())
        ->and($page->body())->toContain(DemoAccount::EMAIL)
        ->and($browser->trail)->toBe([
            // The admin host's guard: no session here, so a handoff.
            'admin.shop.test/admin/orders',
            'admin.shop.test/_handoff/start',
            // Signed in on the shop's host already, so a code at once: the
            // admin host is the shop's own, and no consent is asked for it.
            'shop.test/_handoff/authorize',
            'admin.shop.test/_handoff/callback',
            'admin.shop.test/admin/orders',
        ])
        // Two sessions, one per host, neither the other's cookie.
        ->and($browser->sessionAt('admin.shop.test'))->not->toBe('')
        ->and($browser->sessionAt('admin.shop.test'))->not->toBe($browser->sessionAt('shop.test'))
        ->and($orm->connection()->select('SELECT realm FROM sessions WHERE id = ?', [hash('sha256', $browser->sessionAt('admin.shop.test'))]))
        ->toBe([['realm' => 'admin.shop.test']])
        // The same staff member on both: nothing the shop's host holds for
        // them is purged by the move.
        ->and(new AuthEpochs($orm)->of(staffId($orm)))->toBe($epoch);
});

it('sends someone signed in nowhere from the admin host to sign in on the shop\'s host, and back after', function () {
    $browser = new Browser(kernel(database()));

    $start = $browser->get(ADMIN . '/admin/orders');
    $toAuthority = $browser->get('https://admin.shop.test' . $start->header('location'));
    $authorize = (string) $toAuthority->header('location');
    $toSignIn = $browser->get($authorize);
    $next = (string) parse_url($authorize, PHP_URL_PATH) . '?' . parse_url($authorize, PHP_URL_QUERY);

    expect($start->header('location'))->toBe('/_handoff/start?return=%2Fadmin%2Forders')
        ->and($authorize)->toStartWith(SHOP . HandoffEndpoint::AUTHORIZE . '?')
        // Credentials typed on the shop's host, with the handoff to come back to.
        ->and($toSignIn->header('location'))->toBe('/sign-in?next=' . rawurlencode($next));

    // The shop's own sign-in sends them on to it, and it hands them over.
    $signedIn = $browser->signIn(StaffAccount::EMAIL, StaffAccount::PASSWORD, $next);
    expect($signedIn->header('location'))->toBe($next);

    $callback = $browser->get(SHOP . $next);
    $arrived = $browser->get((string) $callback->header('location'));

    expect($callback->header('location'))->toStartWith(ADMIN . HandoffEndpoint::CALLBACK . '?code=')
        ->and($arrived->status)->toBe(303)
        ->and($arrived->header('location'))->toBe('/admin/orders')
        ->and($arrived->cookies())->toHaveKey(Kernel::SESSION_COOKIE);
});

it('refuses a customer the admin panel would refuse, and makes no session on the admin host', function () {
    $database = database();
    $browser = new Browser(kernel($database));
    $browser->signIn(DemoAccount::EMAIL, DemoAccount::PASSWORD);

    $refused = $browser->follow(ADMIN . '/admin/orders');

    expect($refused->status)->toBe(403)
        ->and(end($browser->trail))->toBe('shop.test/_handoff/authorize')
        ->and(orm($database)->connection()->select("SELECT COUNT(*) AS n FROM sessions WHERE realm = 'admin.shop.test'"))->toBe([['n' => 0]]);
});

it('keeps each session to its host: neither host\'s cookie signs anyone in on the other', function () {
    $browser = new Browser(kernel(database()));
    $browser->signIn(StaffAccount::EMAIL, StaffAccount::PASSWORD);
    $start = $browser->get(ADMIN . '/_handoff/start?return=%2Fadmin%2Forders');
    $authorize = $browser->get((string) $start->header('location'));
    $browser->get((string) $authorize->header('location'));

    $swapped = new Browser($browser->kernel);
    $swapped->jar = ['shop.test' => $browser->jar['admin.shop.test'], 'admin.shop.test' => $browser->jar['shop.test']];

    // Each is a visitor with no session on the other host: the shop's host
    // asks them to sign in, the admin host starts a handoff.
    expect($swapped->get(SHOP . '/orders')->header('location'))->toBe('/sign-in?next=%2Forders')
        ->and($swapped->get(ADMIN . '/admin/orders')->header('location'))->toBe('/_handoff/start?return=%2Fadmin%2Forders');
});

it('redeems a code once, across every worker the database is shared by, and ends the session it made when it comes back', function () {
    $database = database();
    $browser = new Browser(kernel($database));
    $browser->signIn(StaffAccount::EMAIL, StaffAccount::PASSWORD);
    $start = $browser->get(ADMIN . '/_handoff/start?return=%2Fadmin%2Forders');
    $arrivedWith = $browser->jar['admin.shop.test'];
    $callback = (string) $browser->get((string) $start->header('location'))->header('location');

    expect($browser->get($callback)->status)->toBe(303);

    // The browser it signed in, again: answered as before, nothing made or
    // ended.
    $sessions = "SELECT COUNT(*) AS n FROM sessions WHERE realm = 'admin.shop.test'";
    expect($browser->get($callback)->status)->toBe(303)
        ->and(orm($database)->connection()->select($sessions))->toBe([['n' => 1]]);

    // Another worker -- another Kernel over the same database -- with the
    // cookie the browser arrived with, which the code's challenge is for:
    // the cookie a fixation plants, so once the session exists it is a copy.
    $replay = new Browser(kernel($database));
    $replay->jar['admin.shop.test'] = $arrivedWith;
    $refused = $replay->get($callback);

    expect($refused->status)->toBe(403)
        // The code was copied, so the session its redemption made is ended:
        // the browser that redeemed it is sent to hand off again.
        ->and(orm($database)->connection()->select($sessions))->toBe([['n' => 0]])
        ->and($browser->get(ADMIN . '/admin/orders')->header('location'))->toBe('/_handoff/start?return=%2Fadmin%2Forders')
        // And no other: the shop's host still knows them.
        ->and(orm($database)->connection()->select("SELECT COUNT(*) AS n FROM sessions WHERE realm = 'shop.test'"))->toBe([['n' => 1]]);
});

it('stays on one host, with nothing mounted, unless SHOP_ADMIN_URL names the admin host', function () {
    $previous = getenv('SHOP_ADMIN_URL');
    $previousUrl = getenv('SHOP_URL');
    putenv('SHOP_ADMIN_URL');

    try {
        expect(Kernel::realms())->toBeNull();

        putenv('SHOP_ADMIN_URL=http://admin.localhost:8080/');
        putenv('SHOP_URL=http://localhost:8080');
        $realms = Kernel::realms();
    } finally {
        putenv($previous === false ? 'SHOP_ADMIN_URL' : "SHOP_ADMIN_URL={$previous}");
        putenv($previousUrl === false ? 'SHOP_URL' : "SHOP_URL={$previousUrl}");
    }

    expect($realms?->authority->origin)->toBe('http://localhost:8080')
        ->and($realms?->forHost('admin.localhost')?->panel)->toBe(StaffMemberships::PANEL);

    $browser = new Browser(Kernel::fromEnvironment('/tmp/tinvar-shop-handoff-none.sock', key: KEY, database: database()));

    expect($browser->get(ADMIN . '/_handoff/start')->status)->toBe(404);
});

// The shop has no custom domain to be asked about, so its screen is drawn
// here directly: the page a handoff to one would stop at, rendered by the
// real renderer in the shop's own kit.
it('draws the consent screen a custom domain is asked about as a page of its own, with the forms the handoff wrote', function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        $this->markTestSkipped($unbuilt);
    }

    $database = database();
    $renderer = Renderer::start('msgpack', 'shop-handoff-consent');
    $kernel = kernel($database, $renderer->socketPath);
    $staff = $kernel->orm->find(Customer::class, staffId(orm($database)));
    \assert($staff instanceof Customer);
    $consent = new HandoffConsent($staff, Realm::domain('https://partner.example', 'partner', 'portal'), 'v1.the-ticket.its-mac', 'the-csrf-token', time() + 300);

    try {
        $page = $kernel->consentScreen(new Request('GET', HandoffEndpoint::AUTHORIZE, ['host' => 'shop.test', 'accept' => 'text/html']), $consent);
    } finally {
        $renderer->stop();
    }

    preg_match_all('#<form action="/_handoff/authorize" method="post">(.*?)</form>#s', $page->body(), $forms);
    $fields = array_map(static function (string $form): array {
        preg_match_all('#<input type="hidden" name="([^"]+)" value="([^"]*)"/?>#', $form, $inputs, PREG_SET_ORDER);

        return array_column($inputs, 2, 1);
    }, $forms[1]);

    expect($page->status)->toBe(200, $page->body())
        ->and($page->body())->toContain('partner.example')
        ->and($page->body())->toContain(StaffAccount::EMAIL)
        ->and($page->body())->toContain('Sign in to partner.example')
        // No header, no navigation: a system page.
        ->and($page->body())->not->toContain('/orders')
        ->and($page->header('content-security-policy'))->toContain("script-src 'nonce-")
        // Cancel first, the confirm on the right; each its own form.
        ->and($fields)->toBe([
            ['_token' => 'the-csrf-token', 'ticket' => 'v1.the-ticket.its-mac', 'decision' => 'cancel'],
            ['_token' => 'the-csrf-token', 'ticket' => 'v1.the-ticket.its-mac', 'decision' => 'confirm'],
        ]);
});
