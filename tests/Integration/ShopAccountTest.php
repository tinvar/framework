<?php

declare(strict_types=1);

/**
 * The shop's sessions, sign-in, sign-out and orders, through the whole
 * request path but without the renderer: the PHP half of what the browser
 * test drives end to end, with the database inspected directly.
 */

namespace Tinvar\Tests\Integration\ShopAccount;

use Shop\Http\Actions\Account\AccountActions;
use Shop\Http\Actions\Orders\OrderActions;
use Shop\Auth\Account\NextPath;
use Shop\Auth\Account\Passwords;
use Shop\Auth\Sessions\Sessions;
use Shop\Auth\Account\SignInThrottle;
use Shop\Database\Seeders\Seeder;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Shop\Http\Pages\Account\SignIn;
use Tinvar\Http\ApcuThrottleStore;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\get;

const KEY = 'shop-account-test-key-of-32-bytes!!';

/** A browser of one: a cookie jar, and the Kernel it talks to. */
final class Visitor
{
    /** @var array<string, string> */
    public array $cookies = [];

    /**
     * @param string $address where this visitor connects from
     * @param string $database the shop's database file
     */
    public function __construct(
        public readonly Kernel $kernel,
        public readonly string $address = '127.0.0.1',
        public readonly string $database = '',
    ) {}

    public function get(string $url): Response
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        parse_str((string) $query, $parsed);

        return $this->keep($this->kernel->handle(
            new Request('GET', $path, ['host' => 'localhost', 'accept' => 'text/html'], $parsed, [], $this->cookies, $query, $this->address),
        ));
    }

    /** @param array<string, mixed> $body */
    public function action(string $name, array $body): Response
    {
        $token = $this->csrfToken();

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['host' => 'localhost', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                $this->address,
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * An action as the shop's client sends it: the header copied from the
     * CSRF cookie this visitor was last given, whatever session that was.
     *
     * @param array<string, mixed> $body
     */
    public function actionFromCookie(string $name, array $body): Response
    {
        $token = $this->cookies['__Host-XSRF-TOKEN'] ?? '';

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['host' => 'localhost', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                $this->cookies,
                null,
                $this->address,
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    /** The CSRF cookie a page would have issued for the session this visitor holds now. */
    public function receiveCsrfCookie(): void
    {
        $this->cookies['__Host-XSRF-TOKEN'] = $this->csrfToken();
    }

    /**
     * A form posted before JavaScript loads: urlencoded, with the token in
     * the hidden field rather than a header.
     *
     * @param array<string, string> $fields
     */
    public function postForm(string $name, array $fields, string $from = 'https://shop.test/'): Response
    {
        $token = $this->csrfToken();
        $fields = [...$fields, '_token' => $token];

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['host' => 'localhost', 'content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html', 'referer' => $from],
                [],
                $fields,
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                $this->address,
            ),
            http_build_query($fields),
        ));
    }

    /** The session's own token, as the page would have issued it. */
    private function csrfToken(): string
    {
        return (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', ['host' => 'localhost'], [], [], $this->cookies));
    }

    public function sessionId(): ?string
    {
        return Kernel::sessionFrom($this->cookies[Kernel::SESSION_COOKIE] ?? null, KEY);
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

/**
 * APCu's add, increment, fetch and compare-and-swap over an array -- with
 * wipe(), what APCu does once it fills with entries still live: every entry
 * goes at once, whatever `apc.ttl` says. Anyone can fill it: each new
 * address, an IPv6 /64, is new throttle entries.
 */
final class WipeableApcu
{
    /** @var array<string, int> */
    public array $entries = [];

    public function store(): ApcuThrottleStore
    {
        return new ApcuThrottleStore(
            'shop:throttle:',
            add: function (string $key, int $value, int $ttl): bool {
                if (isset($this->entries[$key])) {
                    return false;
                }
                $this->entries[$key] = $value;

                return true;
            },
            inc: function (string $key, int $step, int $ttl): int|false {
                return isset($this->entries[$key]) ? $this->entries[$key] += $step : false;
            },
            fetch: fn (string $key): int|false => $this->entries[$key] ?? false,
            cas: function (string $key, int $old, int $new): bool {
                if (($this->entries[$key] ?? null) !== $old) {
                    return false;
                }
                $this->entries[$key] = $new;

                return true;
            },
        );
    }

    public function wipe(): void
    {
        $this->entries = [];
    }
}

/** @return array{Visitor, \PDO} a visitor to a freshly seeded shop, and the database behind it */
function shop(?WipeableApcu $apcu = null): array
{
    $path = sys_get_temp_dir() . '/tinvar-shop-account-' . bin2hex(random_bytes(4)) . '.sqlite';
    new Seeder(PdoConnection::sqlite($path, 'shop', create: true))->run(customers: 3);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        // Not in a forked child, which runs shutdown functions too.
        if (getmypid() !== $owner) {
            return;
        }
        foreach (['', '-wal', '-shm', '-throttle', '-throttle-wal', '-throttle-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    });

    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-account-none.sock', key: KEY, database: $path, throttleStore: $apcu?->store());

    return [new Visitor($kernel, database: $path), new \PDO("sqlite:{$path}")];
}

/** @return array<string, mixed>|null */
function sessionRow(\PDO $pdo, ?string $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
    $statement->execute([hash('sha256', (string) $id)]);
    $row = $statement->fetch(\PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

function demoId(\PDO $pdo): string
{
    return (string) $pdo->query("SELECT id FROM customers WHERE email = 'demo@shop.test'")->fetchColumn();
}

function signIn(Visitor $visitor, string $password = DemoAccount::PASSWORD, string $next = '/orders'): Response
{
    return $visitor->action('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => $password, 'next' => $next]);
}

/**
 * The visitor's shop, under test with the testing layer (Tinvar\Testing) as
 * well: the tests below that ask about pages alone are written with it.
 */
function tested(Visitor $visitor): Visitor
{
    Testing::use($visitor->kernel);

    return $visitor;
}

afterEach(function () {
    Testing::forget();
});

function demo(Visitor $visitor): Customer
{
    $customer = $visitor->kernel->orm->find(Customer::class, demoId(new \PDO('sqlite:' . $visitor->database)));
    \assert($customer instanceof Customer);

    return $customer;
}

it('sends a signed-out visitor to sign in, and remembers where they were going', function () {
    tested(shop()[0]);

    get('/orders?page=2')->assertRedirect('/sign-in?next=%2Forders%3Fpage%3D2');
});

// Keyed by a hash of the id: a copy of the table is not a list of cookies.
it('stores a signed-in session server-side, keyed by a hash of its id', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');

    signIn($visitor);
    $row = sessionRow($pdo, $visitor->sessionId());

    expect($visitor->sessionId())->not->toBeNull()
        ->and($row)->not->toBeNull()
        ->and($row['customer_id'])->toBe(demoId($pdo))
        ->and((int) $row['expires_at'] - (int) $row['created_at'])->toBe(Kernel::SESSION_LIFETIME)
        ->and($pdo->query('SELECT COUNT(*) FROM sessions WHERE id = ' . $pdo->quote((string) $visitor->sessionId()))->fetchColumn())
        ->toBe(0, 'the id itself is never a key');
});

// Every cookie-less request -- a crawler, a health check, a 404 -- wrote a
// row, and only a sign-in or sign-out ever swept them. A signed-out session
// needs none: its CSRF and fragment tokens bind to the cookie's id.
it('writes no session row for a visitor who has not signed in', function () {
    [$visitor, $pdo] = shop();
    $count = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    $before = $count();

    for ($i = 0; $i < 20; $i++) {
        $visitor->cookies = [];
        $visitor->get("/no-such-page-{$i}");
    }
    $visitor->get('/sign-in');
    $visitor->get('/');

    expect($visitor->sessionId())->not->toBeNull()
        ->and($count())->toBe($before);
});

// Rows come only from sign-ins, and each sign-in sweeps up to a batch of
// expired ones: the sweep removes far more than sign-ins add, so it cannot
// fall behind, and no one sign-in pays for an unbounded delete.
it('sweeps expired sessions a bounded batch at a time as customers sign in', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    $expired = Sessions::SWEEP_BATCH * 2 + 10;
    $insert = $pdo->prepare('INSERT INTO sessions (id, customer_id, created_at, last_seen_at, expires_at) VALUES (?, NULL, 1, 1, 2)');
    for ($i = 0; $i < $expired; $i++) {
        $insert->execute([hash('sha256', "expired-{$i}")]);
    }
    $left = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM sessions WHERE expires_at <= 2')->fetchColumn();

    signIn($visitor);
    $afterOne = $left();
    signIn($visitor);
    signIn($visitor);

    expect($afterOne)->toBe($expired - Sessions::SWEEP_BATCH)
        ->and($left())->toBe(0);
});

// Rotation is what makes an id planted before sign-in worthless after it.
it('signs in under a new session id bound to the customer, and forgets the old one', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/orders');
    $before = $visitor->sessionId();

    $response = signIn($visitor);

    expect($response->status)->toBe(303)
        ->and($response->header('location'))->toBe('/orders')
        ->and($visitor->sessionId())->not->toBe($before)
        ->and(sessionRow($pdo, $before))->toBeNull()
        ->and(sessionRow($pdo, $visitor->sessionId())['customer_id'])->toBe(demoId($pdo));
});

// Two messages would tell anyone with the form which emails have accounts.
it('answers a wrong password and an unknown email alike, on the password field', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    $wrong = signIn($visitor, 'not-the-password');
    $unknown = $visitor->action('account.signIn', ['email' => 'nobody@shop.test', 'password' => 'x', 'next' => '/']);

    expect($wrong->status)->toBe(422)
        ->and($wrong->body())->toBe($unknown->body())
        ->and(json_decode($wrong->body(), true)['errors'])->toBe(['password' => [AccountActions::NO_MATCH]]);
});

// "<Form> also renders a hidden _token field during dynamic server
// rendering, so forms work before JavaScript loads." The urlencoded body
// was handed to the JSON decoder, and refused as bad JSON; and once read,
// it was answered with JSON the browser showed as text. A browser's own
// post gets a page: the 303 on to where the visitor was going.
it('signs in from a form posted before JavaScript loads', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');

    $response = $visitor->postForm('account.signIn', [
        'email' => DemoAccount::EMAIL,
        'password' => DemoAccount::PASSWORD,
        'next' => '/orders',
    ]);

    expect($response->status)->toBe(303)
        ->and($response->header('location'))->toBe('/orders')
        ->and(sessionRow($pdo, $visitor->sessionId())['customer_id'] ?? null)->toBe(demoId($pdo));
});

/** @return array<string, mixed>|null what the visitor's session was flashed, still waiting */
function flashRow(\PDO $pdo, Visitor $visitor): ?array
{
    $statement = $pdo->prepare('SELECT data FROM flashes WHERE session = ?');
    $statement->execute([hash('sha256', (string) $visitor->sessionId())]);
    $data = $statement->fetchColumn();

    return $data === false ? null : json_decode((string) $data, true);
}

// "Without JavaScript, fall back to redirect-back with flashed errors." The
// shop keeps the flash beside its sessions, by a hash of the session id --
// and the password a visitor typed is never written down.
it('sends a sign-in posted without script back with its errors, and never the password', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');

    $response = $visitor->postForm('account.signIn', [
        'email' => DemoAccount::EMAIL,
        'password' => 'not-the-password',
        'next' => '/orders',
    ], 'https://shop.test/sign-in?next=%2Forders');

    expect($response->status)->toBe(303)
        ->and($response->header('location'))->toBe('/sign-in?next=%2Forders')
        ->and($visitor->cookies)->toHaveKey(\Tinvar\Http\Flash::COOKIE)
        ->and(flashRow($pdo, $visitor))->toBe(['form' => [
            'action' => 'account.signIn',
            'errors' => ['password' => [AccountActions::NO_MATCH]],
            'old' => ['email' => DemoAccount::EMAIL, 'next' => '/orders'],
        ]])
        ->and((string) $pdo->query('SELECT data FROM flashes')->fetchColumn())->not->toContain('not-the-password');
});

it('hands the flash to the page it was sent back to, once', function () {
    tested(shop()[0]);

    $back = get('/sign-in')->then()
        ->submit('account.signIn', ['email' => 'not-an-email', 'password' => 'x', 'next' => '/'], '/sign-in')
        ->assertRedirect('/sign-in');

    // The page it was sent back to is handed the errors, and the email that
    // was typed; the next one is handed nothing.
    $page = $back->follow()->assertPage(SignIn::class, fn (AssertablePage $page) => $page->hasFormErrors(['email']));

    expect($page->recorded()->request->attribute(\Tinvar\Http\Kernel::FLASH)['form']['old'] ?? null)->toBe(['email' => 'not-an-email', 'next' => '/'])
        ->and($page->then()->get('/sign-in')->recorded()->request->attribute(\Tinvar\Http\Kernel::FLASH))->toBeNull();
});

/** @return array{string, int} a stored hash's algorithm and cost */
function costOf(string $hash): array
{
    $info = password_get_info($hash);

    return [(string) $info['algo'], (int) ($info['options']['cost'] ?? 0)];
}

// The decoy was pinned at cost 4, the seeded accounts' cost. Against accounts
// at the default cost an unknown email was refused two hundred times faster
// than a known one, and the time taken said which emails had accounts.
it('checks an unknown email against a hash as costly as the accounts\' own', function () {
    [$visitor, $pdo] = shop();

    expect(costOf((new Passwords($visitor->kernel->orm))->decoy()))->toBe([PASSWORD_BCRYPT, Seeder::PASSWORD_COST]);

    // Most accounts at another cost, as a real shop's would be.
    $update = $pdo->prepare('UPDATE customers SET password_hash = ? WHERE email <> ?');
    $update->execute([password_hash('x', PASSWORD_BCRYPT, ['cost' => 6]), DemoAccount::EMAIL]);

    expect(costOf((new Passwords($visitor->kernel->orm))->decoy()))->toBe([PASSWORD_BCRYPT, 6]);
});

it('rehashes a password on sign-in when the hashing policy has moved on since', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    $pdo->prepare('UPDATE customers SET password_hash = ? WHERE email = ?')
        ->execute([password_hash(DemoAccount::PASSWORD, PASSWORD_BCRYPT, ['cost' => 5]), DemoAccount::EMAIL]);

    expect(signIn($visitor)->status)->toBe(303);

    $stored = (string) $pdo->query("SELECT password_hash FROM customers WHERE email = 'demo@shop.test'")->fetchColumn();

    expect(password_needs_rehash($stored, PASSWORD_DEFAULT))->toBeFalse()
        ->and(password_verify(DemoAccount::PASSWORD, $stored))->toBeTrue();
});

// Rehashed, the load test's twenty thousand sign-ins would each pay for a
// hash at the default cost, and then measure bcrypt rather than the shop.
it('leaves a seeded credential at the seeded cost', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    $before = (string) $pdo->query("SELECT password_hash FROM customers WHERE email = 'demo@shop.test'")->fetchColumn();

    signIn($visitor);

    expect($pdo->query("SELECT password_hash FROM customers WHERE email = 'demo@shop.test'")->fetchColumn())->toBe($before);
});

it('signs in whatever the email\'s case', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    expect($visitor->action('account.signIn', ['email' => 'Demo@Shop.TEST', 'password' => DemoAccount::PASSWORD, 'next' => '/'])->status)
        ->toBe(303);
});

it('sends a customer on only to a path on this site', function (string $next, string $expected) {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    expect(signIn($visitor, next: $next)->header('location'))->toBe($expected);
})->with([
    'a path' => ['/orders?page=2', '/orders?page=2'],
    'another site' => ['https://evil.example/', NextPath::DEFAULT],
    'protocol-relative' => ['//evil.example', NextPath::DEFAULT],
    'a backslash' => ['/\\evil.example', NextPath::DEFAULT],
    'the sign-in page' => ['/sign-in?next=/orders', NextPath::DEFAULT],
    'an endpoint' => ['/_action/account.signOut', NextPath::DEFAULT],
]);

// A visitor already signed in has nothing to do on the sign-in page.
it('sends a signed-in customer on from the sign-in page', function () {
    $visitor = tested(shop()[0]);

    actingAs(demo($visitor))->get('/sign-in?next=%2Forders%3Fpage%3D2')->assertRedirect('/orders?page=2');
});

it('pauses sign-in for an account after too many attempts, and says how long for', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        expect(signIn($visitor, 'wrong')->status)->toBe(422);
    }
    $refused = signIn($visitor);

    expect($refused->status)->toBe(429)
        ->and((int) $refused->header('retry-after'))->toBeGreaterThan(0)
        ->and(json_decode($refused->body(), true)['error']['code'])->toBe('too_many_attempts');
});

// Every attempt used to count, from anywhere: ten wrong guesses by someone
// who knew only an email address kept its owner out for fifteen minutes.
it('does not let wrong guesses from one address keep the account\'s owner out from another', function () {
    [$attacker] = shop();
    $owner = new Visitor($attacker->kernel, '203.0.113.9');
    $attacker->get('/sign-in');
    $owner->get('/sign-in');

    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        signIn($attacker, 'wrong');
    }

    expect(signIn($attacker)->status)->toBe(429, 'the guessing address is paused')
        ->and(signIn($owner)->status)->toBe(303, 'the owner, elsewhere, is not');
});

// One subscriber is handed a whole /64, so it is one address here as it is
// to the throttle.
it('counts an IPv6 guesser\'s failures by its /64', function () {
    [$visitor] = shop();
    $guesser = new Visitor($visitor->kernel, '2001:db8:0:1::1');
    $neighbour = new Visitor($visitor->kernel, '2001:db8:0:1::2');
    $elsewhere = new Visitor($visitor->kernel, '2001:db8:0:2::1');
    foreach ([$guesser, $neighbour, $elsewhere] as $visitor) {
        $visitor->get('/sign-in');
    }

    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        signIn($guesser, 'wrong');
    }

    expect(signIn($neighbour)->status)->toBe(429)
        ->and(signIn($elsewhere)->status)->toBe(303);
});

it('counts only failures, so signing in often never pauses anyone', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    $statuses = [];
    for ($i = 0; $i <= SignInThrottle::PER_ACCOUNT; $i++) {
        $statuses[] = signIn($visitor)->status;
    }

    expect(array_unique($statuses))->toBe([303]);
});

it('clears an address\'s failures on an account when it signs in', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    for ($round = 0; $round < 2; $round++) {
        for ($i = 1; $i < SignInThrottle::PER_ACCOUNT; $i++) {
            expect(signIn($visitor, 'wrong')->status)->toBe(422);
        }
        expect(signIn($visitor)->status)->toBe(303, "round {$round}");
    }
});

// The per-address budget alone would let a guesser with many addresses try
// ten passwords from each; the ceiling across all of them bounds that.
it('pauses an account for everyone once guesses from many addresses reach its ceiling', function () {
    [$visitor] = shop();
    $kernel = $visitor->kernel;
    $from = static function (int $n) use ($kernel): Visitor {
        $visitor = new Visitor($kernel, "198.51.100.{$n}");
        $visitor->get('/sign-in');

        return $visitor;
    };
    $addresses = intdiv(SignInThrottle::ACCOUNT_CEILING, SignInThrottle::PER_ACCOUNT);

    for ($n = 1; $n < $addresses; $n++) {
        $guesser = $from($n);
        for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
            signIn($guesser, 'wrong');
        }
    }
    $before = signIn($from(200))->status;

    $last = $from($addresses);
    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        signIn($last, 'wrong');
    }

    expect($before)->toBe(303, 'below the ceiling')
        ->and(signIn($from(201))->status)->toBe(429, 'at it, from an address that never guessed');
});

// Under FrankenPHP the throttles count in APCu, which a flood of new
// addresses can fill, and a full APCu clears every entry: counted there, an
// account's pause -- and its ceiling across addresses -- went with them, and
// the next guess was let in. Sign-in counts where nothing else can evict
// them.
it('keeps an account paused, from one address and across them, when APCu is wiped', function () {
    $apcu = new WipeableApcu();
    [$visitor] = shop($apcu);
    $kernel = $visitor->kernel;
    $visitor->get('/sign-in');

    for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
        expect(signIn($visitor, 'wrong')->status)->toBe(422);
    }
    expect(signIn($visitor)->status)->toBe(429);

    $apcu->wipe();
    expect(signIn($visitor)->status)->toBe(429, 'one address\'s pause on the account survives the wipe');

    // The ceiling across addresses: reached, wiped, still reached.
    $from = static function (int $n) use ($kernel): Visitor {
        $guesser = new Visitor($kernel, "198.51.100.{$n}");
        $guesser->get('/sign-in');

        return $guesser;
    };
    for ($n = 1; $n < intdiv(SignInThrottle::ACCOUNT_CEILING, SignInThrottle::PER_ACCOUNT); $n++) {
        $guesser = $from($n);
        for ($i = 0; $i < SignInThrottle::PER_ACCOUNT; $i++) {
            signIn($guesser, 'wrong');
        }
    }
    expect(signIn($from(200))->status)->toBe(429);

    $apcu->wipe();
    expect(signIn($from(201))->status)->toBe(429, 'the ceiling across addresses survives the wipe');
});

// Fail closed: a shop told to count sign-in in APCu does not start.
it('refuses to count sign-in attempts in APCu', function () {
    $path = sys_get_temp_dir() . '/tinvar-shop-account-' . bin2hex(random_bytes(4)) . '.sqlite';
    new Seeder(PdoConnection::sqlite($path, 'shop', create: true))->run(customers: 1);

    try {
        expect(fn () => Kernel::fromEnvironment('/tmp/tinvar-shop-account-none.sock', key: KEY, database: $path, signInStore: new WipeableApcu()->store()))
            ->toThrow(\InvalidArgumentException::class, 'not counted in APCu');
    } finally {
        foreach (glob("{$path}*") ?: [] as $file) {
            unlink($file);
        }
    }
});

// A page used to be the only thing that issued the CSRF cookie, and only a
// page that succeeded. Sign in to land on a 404 and the cookie still held the
// old session's token, so signing out from that page's header was a 419.
it('hands over the new session\'s CSRF token with its cookie, so the next page need not', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');
    $visitor->receiveCsrfCookie();

    expect($visitor->actionFromCookie('account.signIn', [
        'email' => DemoAccount::EMAIL,
        'password' => DemoAccount::PASSWORD,
        'next' => '/products/no-such-product',
    ])->status)->toBe(303);

    expect($visitor->get('/products/no-such-product')->status)->toBe(404)
        ->and($visitor->actionFromCookie('account.signOut', [])->status)->toBe(200, 'signed out from the 404')
        ->and($visitor->actionFromCookie('account.signIn', [
            'email' => DemoAccount::EMAIL,
            'password' => DemoAccount::PASSWORD,
            'next' => '/',
        ])->status)->toBe(303, 'and in again, with the token the sign-out handed over');
});

it('signs out under a new, signed-out session, and the old one stops working', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    signIn($visitor);
    $signedIn = $visitor->cookies[Kernel::SESSION_COOKIE];
    $signedInId = $visitor->sessionId();

    expect($visitor->action('account.signOut', [])->status)->toBe(200)
        ->and($visitor->sessionId())->not->toBe($signedInId)
        ->and(sessionRow($pdo, $signedInId))->toBeNull()
        ->and(sessionRow($pdo, $visitor->sessionId()))->toBeNull('signed out, so no row');

    // Someone who kept the signed-in cookie is no one now.
    $visitor->cookies[Kernel::SESSION_COOKIE] = $signedIn;
    $product = (string) $pdo->query('SELECT id FROM products LIMIT 1')->fetchColumn();

    expect($visitor->action('orders.place', ['product' => $product, 'quantity' => 1])->status)->toBe(401);
});

// "Sign out everywhere", on the account page: every session the customer
// has, on every device and host, ended in one durable transaction with
// their epoch moved, so every token, once hold, channel grant and email
// link minted for them stops counting (ADR 0075).
it('signs out everywhere: every session ended, the epoch moved, synced in full', function () {
    [$visitor, $pdo] = shop();
    $phone = new Visitor($visitor->kernel, '127.0.0.2', $visitor->database);
    foreach ([$visitor, $phone] as $device) {
        $device->get('/sign-in');
        signIn($device);
    }
    $here = $visitor->sessionId();
    $there = $phone->sessionId();
    $demo = demoId($pdo);
    $epoch = static fn (): int => (int) $pdo->query("SELECT epoch FROM auth_epochs WHERE customer_id = '{$demo}'")->fetchColumn();
    $before = $epoch();

    $sqlite = $visitor->kernel->orm->connection()->pdo();
    \assert($sqlite instanceof \Pdo\Sqlite);
    $levels = [];
    $sqlite->setAuthorizer(static function (int $action, ?string $pragma, ?string $value) use (&$levels): int {
        if ($action === 19 && $pragma === 'synchronous' && $value !== null) {
            $levels[] = strtoupper($value);
        }

        return \Pdo\Sqlite::OK;
    });
    $response = $visitor->action('account.signOutEverywhere', []);
    $sqlite->setAuthorizer(null);

    expect($response->status)->toBe(200)
        ->and(json_decode($response->body(), true)['value'] ?? null)->toBe(['next' => '/'])
        ->and($levels)->toBe(['FULL', 'NORMAL'], 'one durable transaction')
        ->and($visitor->sessionId())->not->toBe($here)
        ->and(sessionRow($pdo, $here))->toBeNull()
        ->and(sessionRow($pdo, $there))->toBeNull()
        ->and(sessionRow($pdo, $visitor->sessionId()))->toBeNull('signed out, so no row')
        ->and((int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE customer_id = '{$demo}'")->fetchColumn())->toBe(0)
        ->and($epoch())->toBe($before + 1);

    // The other device is no one at its next request, and is moved to a
    // signed-out session of its own, which takes its live grant with it.
    $product = (string) $pdo->query('SELECT id FROM products LIMIT 1')->fetchColumn();
    $phone->receiveCsrfCookie();
    expect($phone->actionFromCookie('orders.place', ['product' => $product, 'quantity' => 1])->status)->toBe(401);

    // A page for the signed in: sign in first, as for anyone signed out.
    expect($phone->get('/orders')->status)->toBeIn([302, 303])
        ->and($phone->sessionId())->not->toBe($there)
        ->and(sessionRow($pdo, $phone->sessionId()))->toBeNull();
});

it('signs out everywhere only for someone signed in', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');

    expect($visitor->action('account.signOutEverywhere', [])->status)->toBe(401);
});

// The shop's database syncs NORMAL, which a power cut can take the last
// commits back from. A sign-out taken back would be a session that signs in
// again, so who a session belongs to is committed synced in full (ADR 0050).
it('commits a sign-in and a sign-out synced in full, and nothing else it does on the way', function () {
    [$visitor] = shop();
    $pdo = $visitor->kernel->orm->connection()->pdo();
    \assert($pdo instanceof \Pdo\Sqlite);
    $levels = [];
    $pdo->setAuthorizer(static function (int $action, ?string $pragma, ?string $value) use (&$levels): int {
        if ($action === 19 && $pragma === 'synchronous' && $value !== null) {
            $levels[] = strtoupper($value);
        }

        return \Pdo\Sqlite::OK;
    });

    $visitor->get('/sign-in');
    signIn($visitor);
    $visitor->get('/orders');
    $visitor->action('account.signOut', []);
    $pdo->setAuthorizer(null);

    expect($levels)->toBe(['FULL', 'NORMAL', 'FULL', 'NORMAL'])
        ->and($visitor->kernel->orm->connection()->select('PRAGMA synchronous')[0]['synchronous'])->toBe(1);
});

// Hovering a link prefetches its page. "Prefetch requests skip side effects
// like previous-URL tracking", and marking the session as in use is one.
it('does not count a prefetch as the session being used', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    signIn($visitor);
    $pdo->prepare('UPDATE sessions SET last_seen_at = 1 WHERE id = ?')->execute([hash('sha256', (string) $visitor->sessionId())]);
    $seen = static fn (): int => (int) (sessionRow($pdo, $visitor->sessionId())['last_seen_at'] ?? 0);

    $prefetched = $visitor->kernel->handle(
        new Request('GET', '/no-such-page', ['host' => 'localhost', 'rsc' => '1', 'x-version' => 'dev', 'purpose' => 'prefetch'], [], [], $visitor->cookies),
    );
    $afterPrefetch = $seen();
    $visitor->get('/no-such-page');

    expect($prefetched->status)->toBe(404, 'a prefetch is answered as any GET is')
        ->and($afterPrefetch)->toBe(1)
        ->and($seen())->toBeGreaterThan(1);
});

// last_seen_at says a session is in use and nothing else reads it, so it is
// written only once it is TOUCH_EVERY old: once a minute was 333 writes a
// second for the load test's 20,000 sessions (ADR 0050).
it('marks a session in use at most once every five minutes', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    signIn($visitor);
    $id = hash('sha256', (string) $visitor->sessionId());
    $seen = static fn (): int => (int) (sessionRow($pdo, $visitor->sessionId())['last_seen_at'] ?? 0);
    $set = static function (int $at) use ($pdo, $id): void {
        $pdo->prepare('UPDATE sessions SET last_seen_at = ? WHERE id = ?')->execute([$at, $id]);
    };

    $recent = time() - Sessions::TOUCH_EVERY + 60;
    $set($recent);
    $visitor->get('/no-such-page');
    $afterRecent = $seen();

    $set(time() - Sessions::TOUCH_EVERY - 1);
    $visitor->get('/no-such-page');

    expect(Sessions::TOUCH_EVERY)->toBe(300)
        ->and($afterRecent)->toBe($recent)
        ->and($seen())->toBeGreaterThanOrEqual(time() - 1);
});

// An order id is not a secret: it is in URLs and screenshots.
it('shows an order only to the customer who placed it', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    signIn($visitor);
    $theirs = (string) $pdo->query('SELECT id FROM orders WHERE customer_id <> ' . $pdo->quote(demoId($pdo)) . ' LIMIT 1')->fetchColumn();

    expect($visitor->get("/orders/{$theirs}")->status)->toBe(403);
});

it('places an order at the product\'s price, and refuses one it cannot fill', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    signIn($visitor);
    $teacup = $pdo->query("SELECT id, price_cents FROM products WHERE sku = 'PW-1001'")->fetch(\PDO::FETCH_ASSOC);
    $soldOut = (string) $pdo->query('SELECT id FROM products WHERE stock = 0')->fetchColumn();

    $placed = $visitor->action('orders.place', ['product' => $teacup['id'], 'quantity' => 3]);
    // On to the order it placed.
    $id = substr((string) $placed->header('location'), \strlen('/orders/'));
    $order = $pdo->query('SELECT * FROM orders WHERE id = ' . $pdo->quote($id))->fetch(\PDO::FETCH_ASSOC);

    expect($placed->status)->toBe(303)
        ->and($placed->header('location'))->toStartWith('/orders/')
        ->and($order['customer_id'])->toBe(demoId($pdo))
        ->and($order['status'])->toBe('placed')
        ->and((int) $order['subtotal_cents'])->toBe(3 * (int) $teacup['price_cents'])
        ->and((int) $order['total_cents'])->toBe((int) $order['subtotal_cents'] + (int) $order['shipping_cents']);

    expect($visitor->action('orders.place', ['product' => $teacup['id'], 'quantity' => 11])->status)->toBe(422)
        ->and($visitor->action('orders.place', ['product' => $teacup['id'], 'quantity' => 0])->status)->toBe(422)
        ->and(json_decode($visitor->action('orders.place', ['product' => $soldOut, 'quantity' => 1])->body(), true)['errors'])
        ->toHaveKey('quantity');
});

// "An action's response lists the server-side query tags it invalidated
// (X-Invalidated-Tags), and the client flushes matching prefetch entries
// automatically" -- so a list of orders prefetched before the order is not
// the one shown after it. Every order's tag, and the customer's own, which
// their orders page reads and is told of live in another tab.
it('says placing an order invalidated the customer\'s orders', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/sign-in');
    signIn($visitor);
    $teacup = (string) $pdo->query("SELECT id FROM products WHERE sku = 'PW-1001'")->fetchColumn();

    $placed = $visitor->action('orders.place', ['product' => $teacup, 'quantity' => 1]);
    $refused = $visitor->action('orders.place', ['product' => $teacup, 'quantity' => 0]);

    $customer = (string) $pdo->query("SELECT id FROM customers WHERE email = 'demo@shop.test'")->fetchColumn();

    expect($placed->header('x-invalidated-tags'))->toBe(OrderActions::ORDERS_TAG . ',' . OrderActions::customerTag($customer))
        ->and($refused->header('x-invalidated-tags'))->toBeNull('nothing was written, so nothing is stale');
});

// The seeded credentials follow from the customer's number, so a load test
// can sign in as any of them without a list.
it('seeds customers whose credentials follow from their number', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    expect($visitor->action('account.signIn', [
        'email' => Seeder::emailFor(2),
        'password' => Seeder::passwordFor(2),
        'next' => '/',
    ])->status)->toBe(303);
});
