<?php

declare(strict_types=1);

/**
 * The shop's admin panel through the request path, without the renderer:
 * its guard, its membership check and its page's data -- the PHP half of
 * what the browser test drives end to end, written with the testing layer
 * (Tinvar\Testing).
 */

namespace Tinvar\Tests\Integration\ShopAdmin;

use Shop\Actions\OrderActions;
use Shop\Auth\StaffMemberships;
use Shop\Database\Migrations;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Pages\Admin\Orders;
use Shop\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Live\TagsRead;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\get;

const KEY = 'shop-admin-test-key-of-32-bytes!!!!';

beforeEach(function () {
    $this->database = Shop::testDatabase();
    Testing::use(Kernel::fromEnvironment('/tmp/tinvar-shop-admin-none.sock', key: KEY, database: $this->database));
});

afterEach(function () {
    Testing::forget();
});

function customer(Orm $orm, string $email): Customer
{
    $id = (string) $orm->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = $orm->find(Customer::class, $id);
    expect($customer)->toBeInstanceOf(Customer::class);

    return $customer;
}

function shopper(string $email): Customer
{
    return customer(Testing::client()->app->kernel()->orm, $email);
}

it('puts the admin pages in the admin panel by their namespace, behind the customers\' guard', function () {
    $panels = Kernel::panels();

    expect($panels->panelOf(Orders::class))->toBe(StaffMemberships::PANEL)
        ->and($panels->definition(StaffMemberships::PANEL)?->guard)->toBe(\Shop\Auth\RequireCustomer::class)
        ->and($panels->panelOf(\Shop\Pages\Orders::class))->toBeNull();
});

it('sends a visitor who is not signed in to sign in, and back', function () {
    get('/admin/orders')->assertRedirect('/sign-in?next=%2Fadmin%2Forders');
});

// A customer is signed in, and holds no membership of the panel. The 403 is
// the panel's, before the page's loader runs.
it('refuses a signed-in customer with a 403', function () {
    actingAs(shopper(DemoAccount::EMAIL))->get('/admin/orders')->assertForbidden();
});

it('makes the seeded staff account a member holding the permission the page needs, and customers none', function () {
    $orm = new Orm(PdoConnection::sqlite($this->database, 'shop'), new IdentityMap('shop'));
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
    $connection = PdoConnection::sqlite($this->database, 'shop');
    $connection->execute('DROP TABLE staff');
    $connection->execute('DROP TABLE ' . Migrator::TABLE);
    Migrations::migrate($connection);
    $orm = new Orm($connection, new IdentityMap('shop'));

    expect(new StaffMemberships($orm)->membership(customer($orm, StaffAccount::EMAIL), StaffMemberships::PANEL, ''))->toBeNull();
});

// Through the whole request path: the session made for the staff account,
// the panel's guard and membership check, the loader -- and the data the
// renderer would have been handed.
it('lists every customer\'s newest orders, with whose each is', function () {
    $orm = Testing::client()->app->kernel()->orm;
    $total = (int) $orm->connection()->select('SELECT COUNT(*) AS n FROM orders')[0]['n'];
    $newest = $orm->connection()->select(
        'SELECT o.number, c.email FROM orders o JOIN customers c ON c.id = o.customer_id ORDER BY o.placed_at DESC, o.id DESC LIMIT 1',
    )[0];

    actingAs(shopper(StaffAccount::EMAIL))->get('/admin/orders')
        ->assertOk()
        ->assertPage(Orders::class, fn (AssertablePage $page) => $page
            ->where('total', $total)
            ->has('orders', min($total, Orders::LIMIT), fn (AssertablePage $order) => $order
                ->where('number', $newest['number'])
                ->where('customerEmail', $newest['email'])
                ->where('placedAt', fn (string $at): bool => $at !== '')
                ->etc())
            ->where('orders', fn (array $orders): bool => \count(array_unique(array_column($orders, 'customerEmail'))) > 1)
            ->where('orders', function (array $orders): bool {
                $placed = array_column($orders, 'placedAt');
                $sorted = $placed;
                rsort($sorted);

                return $placed === $sorted;
            }))
        // Two for the panel's membership, the session and the loader's two:
        // a loader that started reading each order's customer alone would
        // blow it.
        ->assertQueryBudget(12);
});

it('reads the tag every placed order invalidates, so staff\'s list is told live', function () {
    $orm = Testing::client()->app->kernel()->orm;
    $tags = new TagsRead($orm);
    new Orders($orm, $tags)();

    expect($tags->all())->toBe(["\0" . OrderActions::ORDERS_TAG => ['*']]);
});
