<?php

declare(strict_types=1);

/**
 * The preferences a marketing email's unsubscribe link opens (ADR 0107):
 * by the signed token the link carries, with no sign-in -- bound to its
 * reader, the shop's tenant and its own purpose, for ninety days -- and the
 * switches and the one button there, which POST the token back.
 *
 * Through the testing layer (ADR 0043), without the renderer.
 */

namespace Tinvar\Tests\Integration\ShopLinkPreferences;

use Shop\Http\Actions\Marketing\MarketingLinkActions;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Mail\Links;
use Shop\Mail\MarketingList;
use Shop\Mail\Unsubscribes;
use Shop\Models\Customer;
use Shop\Http\Pages\Marketing\LinkPreferences;
use Tinvar\Bench\Shop;
use Tinvar\Jobs\Envelope;
use Tinvar\Mail\PreferenceLinks;
use Tinvar\Mail\UnsubscribeLinks;
use Tinvar\Orm\Orm;
use Tinvar\Render\SignedToken;
use Tinvar\Support\Signer;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\action;
use function Tinvar\Testing\get;
use function Tinvar\Testing\testing;

const KEY = 'shop-link-preferences-key-of-32-bytes';

beforeEach(function () {
    $mail = new MailFake();
    Testing::use(Kernel::fromEnvironment(
        '/tmp/tinvar-shop-link-preferences-none.sock',
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

/** @return array<string, bool> */
function rows(Customer $customer): array
{
    $rows = [];
    foreach (orm()->connection()->select('SELECT list, subscribed FROM marketing_preferences WHERE customer_id = ? ORDER BY list', [$customer->id]) as $row) {
        $rows[(string) $row['list']] = (int) $row['subscribed'] === 1;
    }

    return $rows;
}

/** The token a marketing email's body link carries for $customer, as the shop signs it. */
function token(Customer $customer): string
{
    $url = new Unsubscribes(KEY, new Links('https://shop.test'))->preferencesUrl($customer->id, $customer->email);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

/** @return list<string> tokens the shop must refuse for $customer, each a different way */
function forgeries(Customer $customer): array
{
    $token = token($customer);
    [$version, $payload, $mac] = explode('.', $token);
    $claims = json_decode((string) Signer::decode($payload), true);

    return [
        'another customer named over a valid signature' => $version . '.' . Signer::encode((string) json_encode([...$claims, 'p' => customer('customer1@shop.test')->id])) . '.' . $mac,
        'another key' => PreferenceLinks::fromKey(KEY . '-other')->token($customer->id, '', $customer->email),
        // ADR 0116: a link opens only for the address it was sent to.
        'sent to an address the customer no longer has' => PreferenceLinks::fromKey(KEY)->token($customer->id, '', 'old-' . $customer->email),
        'made for no address' => PreferenceLinks::fromKey(KEY)->token($customer->id),
        'another purpose' => SignedToken::encode(new Signer(KEY, PreferenceLinks::PURPOSE), 'route-token', $claims),
        'the one-click link\'s token' => substr((string) parse_url(UnsubscribeLinks::fromKey(KEY, 'https://shop.test')->url('newsletter', $customer->id), PHP_URL_PATH), \strlen(UnsubscribeLinks::PATH) + 1),
        'another tenant' => PreferenceLinks::fromKey(KEY)->token($customer->id, 'acme', $customer->email),
        'expired' => PreferenceLinks::fromKey(KEY, static fn (): int => time() - PreferenceLinks::LIFETIME - 1)->token($customer->id, '', $customer->email),
        'not a token' => 'v1.not.a-token',
    ];
}

describe('the page a link opens', function () {
    it('shows its reader\'s preferences with no sign-in, sending no Referer and kept by nothing', function () {
        $demo = customer();
        $token = token($demo);

        get('/email/preferences?token=' . $token)->assertOk()
            ->assertHeader('referrer-policy', 'no-referrer')
            ->assertHeader('cache-control', 'private, no-store')
            ->assertPage(LinkPreferences::class, fn (AssertablePage $page) => $page
                ->where('token', $token)
                ->where('email', DemoAccount::EMAIL)
                ->where('lists', [
                    ['id' => 'newsletter', 'label' => MarketingList::Newsletter->label(), 'description' => MarketingList::Newsletter->description(), 'subscribed' => true, 'pending' => false],
                    ['id' => 'announcements', 'label' => MarketingList::Announcements->label(), 'description' => MarketingList::Announcements->description(), 'subscribed' => true, 'pending' => false],
                ]));
    });

    it('shows nothing of anyone for a link it refuses, whichever way', function () {
        foreach ([...forgeries(customer()), 'none' => ''] as $why => $token) {
            get('/email/preferences?token=' . rawurlencode($token))->assertOk()->assertPage(LinkPreferences::class, fn (AssertablePage $page) => $page
                ->where('token', '')
                ->where('email', null)
                ->where('lists', []), $why);
        }
    });

    // ADR 0116: a customer whose address changes has every link sent to the old one revoked.
    it('shows nothing once its customer has another address', function () {
        $demo = customer('customer1@shop.test');
        $token = token($demo);
        orm()->connection()->execute('UPDATE customers SET email = ? WHERE id = ?', ['moved-1@shop.test', $demo->id]);
        orm()->flush();

        get('/email/preferences?token=' . $token)->assertPage(LinkPreferences::class, fn (AssertablePage $page) => $page
            ->where('email', null)
            ->etc());
        action('email.setByLink', token: $token, list: 'newsletter', subscribed: false)->assertInvalid(['token' => MarketingLinkActions::LINK_REFUSED]);
    });

    it('shows nothing once its customer is gone', function () {
        $demo = customer('customer1@shop.test');
        $token = token($demo);
        orm()->connection()->execute('DELETE FROM customers WHERE id = ?', [$demo->id]);
        // As the next request finds it: the model read above is not held.
        orm()->flush();

        get('/email/preferences?token=' . $token)->assertPage(LinkPreferences::class, fn (AssertablePage $page) => $page
            ->where('email', null)
            ->etc());
    });
});

describe('a switch on it', function () {
    it('changes its reader\'s one list, with no sign-in, and no one else\'s', function () {
        $demo = customer();
        $other = customer('customer1@shop.test');

        action('email.setByLink', token: token($demo), list: 'newsletter', subscribed: false)->assertOk();
        // A yes from a link asks the inbox first (ADR 0116): nothing is on yet.
        action('email.setByLink', token: token($other), list: 'announcements', subscribed: true)->assertOk();

        expect(rows($demo))->toBe(['announcements' => true, 'newsletter' => false])
            ->and(rows($other))->toBe([])
            ->and(orm()->connection()->select('SELECT list, source, confirmed_at FROM marketing_opt_ins WHERE customer_id = ?', [$other->id]))
            ->toBe([['list' => 'announcements', 'source' => 'email-link', 'confirmed_at' => null]]);
    });

    it('turns every list off in one click', function () {
        $demo = customer();

        action('email.unsubscribeAllByLink', token: token($demo))->assertOk();

        expect(rows($demo))->toBe(['announcements' => false, 'newsletter' => false]);
    });

    it('refuses a link it would not open, the same way every time, and changes nothing', function () {
        $demo = customer();
        foreach (forgeries($demo) as $why => $token) {
            action('email.setByLink', token: $token, list: 'newsletter', subscribed: false)->assertInvalid(['token' => MarketingLinkActions::LINK_REFUSED]);
            action('email.unsubscribeAllByLink', token: $token)->assertInvalid(['token' => MarketingLinkActions::LINK_REFUSED]);
        }

        expect(rows($demo))->toBe(['announcements' => true, 'newsletter' => true])
            ->and(rows(customer('customer1@shop.test')))->toBe([]);
    });

    it('names only lists the shop sends', function () {
        action('email.setByLink', token: token(customer()), list: 'digest', subscribed: false)->assertInvalid(['list']);
    });

    // A link anyone holding the email may POST as often as they like: a
    // repeat is a read, not a write on SQLite's one writer.
    it('writes nothing for what is said already', function () {
        $demo = customer();
        $pdo = orm()->connection()->pdo();
        \assert($pdo instanceof \Pdo\Sqlite);
        $writes = 0;
        $pdo->setAuthorizer(static function (int $action, ?string $table) use (&$writes): int {
            if (\in_array($action, [18, 23], true) && $table === 'marketing_preferences') {
                $writes++;
            }

            return \Pdo\Sqlite::OK;
        });

        try {
            action('email.setByLink', token: token($demo), list: 'newsletter', subscribed: true)->assertOk();
            action('email.unsubscribeAllByLink', token: token($demo))->assertOk();
            $first = $writes;
            action('email.unsubscribeAllByLink', token: token($demo))->assertOk();
        } finally {
            $pdo->setAuthorizer(null);
        }

        expect($first)->toBeGreaterThan(0)->and($writes)->toBe($first);
    });
});
