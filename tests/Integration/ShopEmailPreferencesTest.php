<?php

declare(strict_types=1);

/**
 * The shop's email preferences page: each kind of marketing email, with the
 * signed-in customer's yes or no to it, and the action its switches call as
 * they are flipped -- held by CustomerPolicy to the customer's own, and to
 * the lists the shop sends.
 *
 * Through the testing layer (ADR 0043), without the renderer.
 */

namespace Tinvar\Tests\Integration\ShopEmailPreferences;

use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Mail\Marketing\MarketingList;
use Shop\Database\Models\Account\Customer;
use Shop\Http\Pages\Marketing\EmailPreferences;
use Tinvar\Bench\Shop;
use Tinvar\Jobs\Envelope;
use Tinvar\Orm\Orm;
use Tinvar\Support\Signer;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\action;
use function Tinvar\Testing\get;
use function Tinvar\Testing\testing;

const KEY = 'shop-email-preferences-key-32-bytes!';

beforeEach(function () {
    $mail = new MailFake();
    Testing::use(Kernel::fromEnvironment(
        '/tmp/tinvar-shop-preferences-none.sock',
        key: KEY,
        database: Shop::testDatabase(),
        hub: new HubFake(),
        mailTransport: $mail,
        mailRenderer: $mail,
        queue: new QueueFake(new Signer(KEY, Envelope::PURPOSE)),
    ));
});

afterEach(function () {
    Testing::forget();
});

function orm(): Orm
{
    return testing()->app->kernel()->orm;
}

function customer(string $email = DemoAccount::EMAIL): Customer
{
    $id = (string) orm()->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = orm()->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

/** @return array<string, bool> what $customer has said to each list that has a row, by list */
function rows(Customer $customer): array
{
    $rows = [];
    foreach (orm()->connection()->select('SELECT list, subscribed FROM marketing_preferences WHERE customer_id = ? ORDER BY list', [$customer->id]) as $row) {
        $rows[(string) $row['list']] = (int) $row['subscribed'] === 1;
    }

    return $rows;
}

/**
 * Each list the page shows, as it encodes it, with what the customer said.
 *
 * @return list<array{id: string, label: string, description: string, subscribed: bool, pending: bool}>
 */
function lists(bool $newsletter, bool $announcements, bool $newsletterPending = false): array
{
    return [
        ['id' => 'newsletter', 'label' => MarketingList::Newsletter->label(), 'description' => MarketingList::Newsletter->description(), 'subscribed' => $newsletter, 'pending' => $newsletterPending],
        ['id' => 'announcements', 'label' => MarketingList::Announcements->label(), 'description' => MarketingList::Announcements->description(), 'subscribed' => $announcements, 'pending' => false],
    ];
}

describe('the preferences page', function () {
    it('is a signed-in customer\'s: a visitor is sent to sign in and back', function () {
        get('/account/email')->assertRedirect('/sign-in?next=%2Faccount%2Femail');
    });

    // Marketing is opted into: the seeded demo account said yes to both,
    // and a customer who never visited the page said nothing, which is no.
    it('shows each list with the customer\'s yes or no: nothing said is a no', function () {
        $demo = customer();

        actingAs($demo)->get('/account/email')->assertOk()->assertPage(EmailPreferences::class, fn (AssertablePage $page) => $page
            ->where('customerId', $demo->id)
            ->where('email', DemoAccount::EMAIL)
            ->where('lists', lists(newsletter: true, announcements: true)));

        actingAs(customer('customer1@shop.test'))->get('/account/email')->assertPage(EmailPreferences::class, fn (AssertablePage $page) => $page
            ->where('lists', lists(newsletter: false, announcements: false))
            ->etc());
    });
});

describe('a switch on it', function () {
    it('saves the customer\'s no at once, asks the inbox for a yes, and the page shows both', function () {
        $demo = customer();
        $other = customer('customer1@shop.test');

        actingAs($demo)->action('account.setMarketing', customer: $demo, list: 'announcements', subscribed: false)->assertOk();
        actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: true)->assertOk();

        // A yes waits on its confirmation (ADR 0116): no row says it yet.
        expect(rows($demo))->toBe(['announcements' => false, 'newsletter' => true])
            ->and(rows($other))->toBe([]);
        actingAs($demo)->get('/account/email')->assertPage(EmailPreferences::class, fn (AssertablePage $page) => $page
            ->where('lists', lists(newsletter: true, announcements: false))
            ->etc());
        actingAs($other)->get('/account/email')->assertPage(EmailPreferences::class, fn (AssertablePage $page) => $page
            ->where('lists', lists(newsletter: false, announcements: false, newsletterPending: true))
            ->etc());
    });

    // CustomerPolicy::update: a customer id is in the page, and knowing one
    // must never be enough to change someone else's mail.
    it('changes no one else\'s, names only lists the shop sends, and needs a customer signed in', function () {
        $demo = customer();
        $other = customer('customer1@shop.test');

        actingAs($other)->action('account.setMarketing', customer: $demo, list: 'newsletter', subscribed: false)->assertForbidden();
        actingAs($demo)->action('account.setMarketing', customer: $demo, list: 'digest', subscribed: false)->assertInvalid(['list']);
        action('account.setMarketing', customer: $demo, list: 'newsletter', subscribed: false)->assertUnauthenticated();

        expect(rows($demo))->toBe(['announcements' => true, 'newsletter' => true]);
    });
});
