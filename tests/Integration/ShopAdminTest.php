<?php

declare(strict_types=1);

/**
 * The shop's admin panel through the request path, without the renderer:
 * its guard, its membership check and its page's data -- the PHP half of
 * what the browser test drives end to end.
 */

namespace Tinvar\Tests\Integration\ShopAdmin;

use Shop\Auth\StaffMemberships;
use Shop\Database\Migrations;
use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Pages\Admin\Orders;
use Shop\StaffAccount;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'shop-admin-test-key-of-32-bytes!!!!';

/** A seeded database, removed when the process ends. */
function database(): string
{
    $path = sys_get_temp_dir() . '/tinvar-shop-admin-' . bin2hex(random_bytes(4)) . '.sqlite';
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

/** A browser of one, signed in as $email when given. */
final class Visitor
{
    /** @var array<string, string> */
    public array $cookies = [];

    public readonly Kernel $kernel;

    public function __construct(string $database)
    {
        $this->kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-admin-none.sock', key: KEY, database: $database);
    }

    public function get(string $path): Response
    {
        return $this->keep($this->kernel->handle(new Request('GET', $path, ['accept' => 'text/html'], [], [], $this->cookies)));
    }

    public function signIn(string $email, string $password): Response
    {
        $this->get('/sign-in');
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                '/_action/account.signIn',
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
            ),
            json_encode(['email' => $email, 'password' => $password, 'next' => '/'], JSON_THROW_ON_ERROR),
        ));
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

function customer(Orm $orm, string $email): Customer
{
    $id = (string) $orm->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = $orm->find(Customer::class, $id);
    expect($customer)->toBeInstanceOf(Customer::class);

    return $customer;
}

it('puts the admin pages in the admin panel by their namespace, behind the customers\' guard', function () {
    $panels = Kernel::panels();

    expect($panels->panelOf(Orders::class))->toBe(StaffMemberships::PANEL)
        ->and($panels->definition(StaffMemberships::PANEL)?->guard)->toBe(\Shop\Auth\RequireCustomer::class)
        ->and($panels->panelOf(\Shop\Pages\Orders::class))->toBeNull();
});

it('sends a visitor who is not signed in to sign in, and back', function () {
    $visitor = new Visitor(database());

    expect($visitor->get('/admin/orders')->header('location'))->toBe('/sign-in?next=%2Fadmin%2Forders');
});

// A customer is signed in, and holds no membership of the panel. The 403 is
// the panel's, before the page's loader runs.
it('refuses a signed-in customer with a 403', function () {
    $visitor = new Visitor(database());
    $visitor->signIn(DemoAccount::EMAIL, DemoAccount::PASSWORD);

    expect($visitor->get('/admin/orders')->status)->toBe(403);
});

it('makes the seeded staff account a member holding the permission the page needs, and customers none', function () {
    $orm = new Orm(PdoConnection::sqlite(database(), 'shop'), new IdentityMap('shop'));
    $memberships = new StaffMemberships($orm);

    $staff = $memberships->membership(customer($orm, StaffAccount::EMAIL), StaffMemberships::PANEL, '');

    expect($staff?->can(StaffMemberships::VIEW_ALL_ORDERS))->toBeTrue()
        ->and($memberships->membership(customer($orm, DemoAccount::EMAIL), StaffMemberships::PANEL, ''))->toBeNull()
        // One tenant: a membership is held in none, and no other panel exists.
        ->and($memberships->membership(customer($orm, StaffAccount::EMAIL), StaffMemberships::PANEL, 'acme'))->toBeNull()
        ->and($memberships->membership(customer($orm, StaffAccount::EMAIL), 'reseller', ''))->toBeNull();
});

// As `auth_epochs` and `flashes` are: a database from before staff existed
// -- and before the shop had migrations -- is given the table, empty, by
// migrating it, rather than failing every admin request.
it('gives a database seeded before staff existed the table, with nobody in it, once migrated', function () {
    $connection = PdoConnection::sqlite(database(), 'shop');
    $connection->execute('DROP TABLE staff');
    $connection->execute('DROP TABLE ' . Migrator::TABLE);
    Migrations::migrate($connection);
    $orm = new Orm($connection, new IdentityMap('shop'));

    expect(new StaffMemberships($orm)->membership(customer($orm, StaffAccount::EMAIL), StaffMemberships::PANEL, ''))->toBeNull();
});

it('lists every customer\'s newest orders, with whose each is', function () {
    $orm = new Orm(PdoConnection::sqlite(database(), 'shop'), new IdentityMap('shop'));
    $total = (int) $orm->connection()->select('SELECT COUNT(*) AS n FROM orders')[0]['n'];

    $page = new Orders($orm)();
    $placed = array_map(static fn ($o): string => $o->placedAt, $page->orders);
    $sorted = $placed;
    rsort($sorted);

    expect($page->total)->toBe($total)
        ->and($page->orders)->toHaveCount(min($total, Orders::LIMIT))
        ->and(\count(array_unique(array_map(static fn ($o): string => $o->customerEmail, $page->orders))))->toBeGreaterThan(1)
        ->and($placed)->toBe($sorted);
});
