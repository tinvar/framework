<?php

declare(strict_types=1);

/**
 * The shop's sessions, sign-in, sign-out and orders, through the whole
 * request path but without the renderer: the PHP half of what the browser
 * test drives end to end, with the database inspected directly.
 */

namespace Tinvar\Tests\Integration\ShopAccount;

use Shop\Actions\AccountActions;
use Shop\Auth\NextPath;
use Shop\Auth\SignInThrottle;
use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'shop-account-test-key-of-32-bytes!!';

/** A browser of one: a cookie jar, and the Kernel it talks to. */
final class Visitor
{
    /** @var array<string, string> */
    public array $cookies = [];

    /** @param string $address where this visitor connects from */
    public function __construct(public readonly Kernel $kernel, public readonly string $address = '127.0.0.1') {}

    public function get(string $url): Response
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        parse_str((string) $query, $parsed);

        return $this->keep($this->kernel->handle(
            new Request('GET', $path, ['accept' => 'text/html'], $parsed, [], $this->cookies, $query, $this->address),
        ));
    }

    /** @param array<string, mixed> $body */
    public function action(string $name, array $body): Response
    {
        // The session's own token, as the page would have issued it.
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                $this->address,
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
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

/** @return array{Visitor, \PDO} a visitor to a freshly seeded shop, and the database behind it */
function shop(): array
{
    $path = sys_get_temp_dir() . '/tinvar-shop-account-' . bin2hex(random_bytes(4)) . '.sqlite';
    new Seeder(PdoConnection::sqlite($path, 'shop', create: true))->run(customers: 3);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        // Not in a forked child, which runs shutdown functions too.
        if (getmypid() !== $owner) {
            return;
        }
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    });

    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-account-none.sock', key: KEY, database: $path);

    return [new Visitor($kernel), new \PDO("sqlite:{$path}")];
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

it('sends a signed-out visitor to sign in, and remembers where they were going', function () {
    [$visitor] = shop();

    expect($visitor->get('/orders?page=2')->header('location'))->toBe('/sign-in?next=%2Forders%3Fpage%3D2');
});

// Keyed by a hash of the id: a copy of the table is not a list of cookies.
it('stores a new session server-side, keyed by a hash of its id', function () {
    [$visitor, $pdo] = shop();

    $visitor->get('/orders');
    $row = sessionRow($pdo, $visitor->sessionId());

    expect($visitor->sessionId())->not->toBeNull()
        ->and($row)->not->toBeNull()
        ->and($row['customer_id'])->toBeNull()
        ->and((int) $row['expires_at'] - (int) $row['created_at'])->toBe(Kernel::SESSION_LIFETIME)
        ->and($pdo->query('SELECT COUNT(*) FROM sessions WHERE id = ' . $pdo->quote((string) $visitor->sessionId()))->fetchColumn())
        ->toBe(0, 'the id itself is never a key');
});

// Rotation is what makes an id planted before sign-in worthless after it.
it('signs in under a new session id bound to the customer, and forgets the old one', function () {
    [$visitor, $pdo] = shop();
    $visitor->get('/orders');
    $before = $visitor->sessionId();

    $response = signIn($visitor);

    expect($response->status)->toBe(200)
        ->and(json_decode($response->body(), true)['value'])->toBe(['next' => '/orders'])
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

it('signs in whatever the email\'s case', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    expect($visitor->action('account.signIn', ['email' => 'Demo@Shop.TEST', 'password' => DemoAccount::PASSWORD, 'next' => '/'])->status)
        ->toBe(200);
});

it('sends a customer on only to a path on this site', function (string $next, string $expected) {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    expect(json_decode(signIn($visitor, next: $next)->body(), true)['value']['next'])->toBe($expected);
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
    [$visitor] = shop();
    $visitor->get('/sign-in');
    signIn($visitor);

    expect($visitor->get('/sign-in?next=%2Forders%3Fpage%3D2')->header('location'))->toBe('/orders?page=2');
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
        ->and(signIn($owner)->status)->toBe(200, 'the owner, elsewhere, is not');
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
        ->and(signIn($elsewhere)->status)->toBe(200);
});

it('counts only failures, so signing in often never pauses anyone', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    $statuses = [];
    for ($i = 0; $i <= SignInThrottle::PER_ACCOUNT; $i++) {
        $statuses[] = signIn($visitor)->status;
    }

    expect(array_unique($statuses))->toBe([200]);
});

it('clears an address\'s failures on an account when it signs in', function () {
    [$visitor] = shop();
    $visitor->get('/sign-in');

    for ($round = 0; $round < 2; $round++) {
        for ($i = 1; $i < SignInThrottle::PER_ACCOUNT; $i++) {
            expect(signIn($visitor, 'wrong')->status)->toBe(422);
        }
        expect(signIn($visitor)->status)->toBe(200, "round {$round}");
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

    expect($before)->toBe(200, 'below the ceiling')
        ->and(signIn($from(201))->status)->toBe(429, 'at it, from an address that never guessed');
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
        ->and(sessionRow($pdo, $visitor->sessionId())['customer_id'])->toBeNull();

    // Someone who kept the signed-in cookie is no one now.
    $visitor->cookies[Kernel::SESSION_COOKIE] = $signedIn;
    $product = (string) $pdo->query('SELECT id FROM products LIMIT 1')->fetchColumn();

    expect($visitor->action('orders.place', ['product' => $product, 'quantity' => 1])->status)->toBe(401);
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
    $id = json_decode($placed->body(), true)['value']['order'];
    $order = $pdo->query('SELECT * FROM orders WHERE id = ' . $pdo->quote($id))->fetch(\PDO::FETCH_ASSOC);

    expect($placed->status)->toBe(200)
        ->and($order['customer_id'])->toBe(demoId($pdo))
        ->and($order['status'])->toBe('placed')
        ->and((int) $order['subtotal_cents'])->toBe(3 * (int) $teacup['price_cents'])
        ->and((int) $order['total_cents'])->toBe((int) $order['subtotal_cents'] + (int) $order['shipping_cents']);

    expect($visitor->action('orders.place', ['product' => $teacup['id'], 'quantity' => 11])->status)->toBe(422)
        ->and($visitor->action('orders.place', ['product' => $teacup['id'], 'quantity' => 0])->status)->toBe(422)
        ->and(json_decode($visitor->action('orders.place', ['product' => $soldOut, 'quantity' => 1])->body(), true)['errors'])
        ->toHaveKey('quantity');
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
    ])->status)->toBe(200);
});
