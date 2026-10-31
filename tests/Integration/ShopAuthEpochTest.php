<?php

declare(strict_types=1);

/**
 * The shop's auth epochs: the number the framework binds client-held data to
 * beside the session and the customer (AuthContext), and which the shop
 * moves on sign-in and sign-out -- where a role change or an impersonation
 * would move it, if the shop had either.
 */

namespace Tinvar\Tests\Integration\ShopAuthEpoch;

use Shop\Auth\AuthEpochs;
use Shop\Auth\Sessions;
use Shop\Http\Middleware\StartSession;
use Shop\Database\Migrations;
use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

const KEY = 'shop-auth-epoch-test-key-32-bytes!!!';

/** @return array{Sessions, AuthEpochs, Orm, \PDO} over a freshly seeded shop */
function shop(): array
{
    $path = sys_get_temp_dir() . '/tinvar-shop-epoch-' . bin2hex(random_bytes(4)) . '.sqlite';
    $connection = PdoConnection::sqlite($path, 'shop', create: true);
    new Seeder($connection)->run(customers: 1);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    });
    $orm = new Orm($connection, new IdentityMap('shop'));
    $epochs = new AuthEpochs($orm);

    return [new Sessions($orm, KEY, epochs: $epochs), $epochs, $orm, new \PDO("sqlite:{$path}")];
}

function demo(Orm $orm, \PDO $pdo): Customer
{
    $id = (string) $pdo->query("SELECT id FROM customers WHERE email = '" . DemoAccount::EMAIL . "'")?->fetchColumn();
    $customer = $orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

function request(string $cookie): Request
{
    return new Request('GET', '/', cookies: [Kernel::SESSION_COOKIE => $cookie]);
}

/** Signs the demo customer in on a fresh session, and returns the cookie it is under now. */
function signIn(Sessions $sessions, Customer $customer): string
{
    $context = $sessions->open(request(Kernel::newSession(KEY)));
    $sessions->signIn($context, $customer);

    return (string) $context->replacement();
}

/** The user and epoch StartSession logs a request in as. */
function loggedInAs(Sessions $sessions, string $cookie): array
{
    $auth = new AuthContext();
    $seen = [];
    new StartSession($sessions, $auth)->handle(request($cookie), static function () use ($auth, &$seen): Response {
        $seen = [$auth->id(), $auth->epoch()];

        return Response::make(200, '');
    });

    return $seen;
}

it('moves the customer\'s epoch on at sign-in, and again at sign-out', function () {
    [$sessions, $epochs, $orm, $pdo] = shop();
    $customer = demo($orm, $pdo);
    expect($epochs->of($customer->id))->toBe('0');

    $cookie = signIn($sessions, $customer);
    $atSignIn = $epochs->of($customer->id);
    $sessions->signOut($sessions->open(request($cookie)));

    expect($atSignIn)->toBe('1')
        ->and($epochs->of($customer->id))->toBe('2');
});

it('logs each request in as the customer, at the customer\'s epoch', function () {
    [$sessions, , $orm, $pdo] = shop();
    $customer = demo($orm, $pdo);

    $cookie = signIn($sessions, $customer);

    expect(loggedInAs($sessions, $cookie))->toBe([$customer->id, '1']);
});

// A role granted, an agent starting to act as the customer: the session id
// stays, and the epoch is what moves -- the next request is someone else's
// as far as what the client holds is concerned.
it('logs the same session in at the new epoch once it has moved', function () {
    [$sessions, $epochs, $orm, $pdo] = shop();
    $customer = demo($orm, $pdo);
    $cookie = signIn($sessions, $customer);

    $epochs->bump($customer->id);

    expect(loggedInAs($sessions, $cookie))->toBe([$customer->id, '2']);
});

it('logs a signed-out visitor in as no one, at no epoch', function () {
    [$sessions] = shop();

    expect(loggedInAs($sessions, Kernel::newSession(KEY)))->toBe([null, '']);
});

// A database from before epochs -- and before the shop had migrations --
// is given the table by migrating it, rather than failing every sign-in.
it('gets its table from migrating a database seeded before it', function () {
    [, $epochs, $orm, $pdo] = shop();
    $pdo->exec('DROP TABLE auth_epochs');
    $pdo->exec('DROP TABLE ' . Migrator::TABLE);
    Migrations::migrate($orm->connection());
    $customer = demo($orm, $pdo);

    $epochs->bump($customer->id);

    expect(new AuthEpochs($orm)->of($customer->id))->toBe('1');
});
