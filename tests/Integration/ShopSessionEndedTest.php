<?php

declare(strict_types=1);

/**
 * A session the server ended takes the browser's live grant with it (ADR
 * 0075).
 *
 * The hub checks a grant's signature and its expiry, nothing else, so a
 * browser that was let in to a signed-in customer's topics stayed let in
 * until the grant ran out -- after "sign out everywhere" deleted its
 * session row, or once its cookie stopped verifying and the shop handed it
 * a new one. The framework takes the grant away whenever a response moves
 * the browser to another session; these are the two the shop makes outside
 * anything it can see, and each is now a replacement the grant goes with.
 */

namespace Tinvar\Tests\Integration\ShopSessionEnded;

use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Database\Models\Account\Customer;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\MemoryHub;

const KEY = 'shop-session-ended-key-of-32-bytes!!';

beforeEach(function () {
    $this->hub = new MemoryHub();
    $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-ended-none.sock', key: KEY, database: Shop::testDatabase(), hub: $this->hub);
    $this->product = (string) $this->kernel->orm->connection()->select('SELECT id FROM products ORDER BY id LIMIT 1')[0]['id'];
    $id = (string) $this->kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];
    $customer = $this->kernel->orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);
    $this->customer = $customer;
});

/** A request the global middleware runs for, which renders nothing: the stock reading. */
function ask(Kernel $kernel, string $product, ?string $session): Response
{
    return $kernel->handle(new Request(
        'GET',
        "/products/{$product}/stock",
        ['host' => '127.0.0.1', 'accept' => 'application/json'],
        cookies: $session === null ? [] : [Kernel::SESSION_COOKIE => $session],
        remoteAddress: '127.0.0.1',
    ));
}

/** The session cookie a response sets, or null for none. */
function issued(Response $response): ?string
{
    $line = $response->cookies()[Kernel::SESSION_COOKIE] ?? null;
    if ($line === null) {
        return null;
    }

    return substr(explode(';', $line, 2)[0], \strlen(Kernel::SESSION_COOKIE) + 1);
}

function signedIn(Kernel $kernel, Customer $customer): string
{
    return $kernel->signedIn($customer, new Request('GET', '/', ['host' => '127.0.0.1']))[Kernel::SESSION_COOKIE];
}

it('replaces a signed-in session whose row was deleted, and takes its live grant away', function () {
    $session = signedIn($this->kernel, $this->customer);

    // Signed in and untouched: nothing replaced, nothing revoked.
    $kept = ask($this->kernel, $this->product, $session);
    expect($kept->status)->toBe(200)
        ->and(issued($kept))->toBeNull()
        ->and($kept->header('x-test-hub-revoked'))->toBeNull();

    // "Sign out everywhere": every row of the customer's gone.
    $this->kernel->orm->connection()->execute('DELETE FROM sessions WHERE customer_id = ?', [$this->customer->id]);

    $ended = ask($this->kernel, $this->product, $session);
    $replacement = issued($ended);

    expect($ended->status)->toBe(200)
        ->and($replacement)->not->toBeNull()
        ->and($replacement)->not->toBe($session)
        ->and(Kernel::sessionFrom($replacement, KEY))->not->toBeNull()
        ->and($ended->header('x-test-hub-revoked'))->toBe('1')
        // And the replacement is an ordinary signed-out session: kept, not
        // replaced again, and its grant left alone from here.
        ->and(issued($again = ask($this->kernel, $this->product, $replacement)))->toBeNull()
        ->and($again->header('x-test-hub-revoked'))->toBeNull();
});

it('replaces a signed-in session whose row is another host\'s, as one whose row is gone', function () {
    $session = signedIn($this->kernel, $this->customer);
    $this->kernel->orm->connection()->execute('UPDATE sessions SET realm = ? WHERE customer_id = ?', ['elsewhere.example', $this->customer->id]);

    $ended = ask($this->kernel, $this->product, $session);

    expect(issued($ended))->not->toBeNull()
        ->and($ended->header('x-test-hub-revoked'))->toBe('1');
});

it('takes the live grant away with a session cookie that no longer verifies', function (\Closure $cookie) {
    $response = ask($this->kernel, $this->product, $cookie());

    expect(issued($response))->not->toBeNull()
        ->and($response->header('x-test-hub-revoked'))->toBe('1');
})->with([
    'expired' => [static fn (): string => Kernel::newSession(KEY, time() - Kernel::SESSION_LIFETIME - 60)],
    'signed with another key' => [static fn (): string => Kernel::newSession('another-key-of-at-least-32-bytes!!!')],
    'not a cookie this shop writes' => [static fn (): string => 'forged'],
    // A browser drops the cookie when it expires, and may still hold a grant.
    'none at all' => [static fn (): ?string => null],
]);

// A signed-out visitor's cookie has no row and never had one: it is kept,
// and the grant its pages traded for is theirs to keep.
it('keeps a signed-out session, and its grant', function () {
    $session = Kernel::newSession(KEY);

    $response = ask($this->kernel, $this->product, $session);

    expect(issued($response))->toBeNull()
        ->and($response->header('x-test-hub-revoked'))->toBeNull();
});

// Signing out rotates to a signed-out session, which has no row by design:
// it is not mistaken for one the server ended.
it('keeps the session a sign-out issues', function () {
    $session = signedIn($this->kernel, $this->customer);
    $context = new \Shop\Auth\Sessions\SessionContext((string) Kernel::sessionFrom($session, KEY), '127.0.0.1', $this->customer);
    $this->kernel->sessions->signOut($context);
    $signedOut = (string) $context->replacement();

    $response = ask($this->kernel, $this->product, $signedOut);

    expect(issued($response))->toBeNull()
        ->and($response->header('x-test-hub-revoked'))->toBeNull();
});
