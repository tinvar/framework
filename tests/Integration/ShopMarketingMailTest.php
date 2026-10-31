<?php

declare(strict_types=1);

/**
 * The shop's marketing email: the product announcement `tinvar
 * mail:announce` queues for the customers who said yes to it, and the
 * one-click unsubscribe its List-Unsubscribe header carries (RFC 8058, ADR
 * 0048) -- POSTed with no session or CSRF token, by the signed link alone,
 * and refused for any other.
 *
 * Through the testing layer (ADR 0043), with the mail and the queue faked;
 * the last tests draw the email through the real renderer, and are skipped
 * when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\ShopMarketingMail;

use Shop\Http\Actions\Marketing\MarketingLinkActions;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Mail\ConfirmSubscription;
use Shop\Mail\Links;
use Shop\Mail\ConsentSource;
use Shop\Mail\MarketingConsents;
use Shop\Mail\NewsletterIssues;
use Shop\Mail\MarketingList;
use Shop\Mail\MarketingPreferences;
use Shop\Mail\Newsletter;
use Shop\Mail\ProductAnnouncement;
use Shop\Mail\SendNewsletter;
use Shop\Mail\SendOptInConfirmation;
use Shop\Mail\SendProductAnnouncement;
use Shop\Mail\Unsubscribes;
use Shop\Models\Customer;
use Shop\Models\Product;
use Shop\Http\Pages\Marketing\ConfirmMarketing;
use Shop\Http\Pages\Marketing\OneClickUnsubscribe;
use Shop\Tasks\ScheduleNewsletter;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Jobs\Envelope;
use Tinvar\Mail\LogTransport;
use Tinvar\Mail\UnsubscribeLinks;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\SignedToken;
use Tinvar\Support\Signer;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\action;
use function Tinvar\Testing\actingAs;
use function Tinvar\Testing\get;
use function Tinvar\Testing\testing;

const KEY = 'shop-marketing-mail-key-of-32-bytes!';

/** The fakes the current test's shop was built with. */
final class Fakes
{
    public static MailFake $mail;
    public static QueueFake $queue;
}

/** The shop as the test environment has it, its email and its jobs faked; SHOP_URL as set. */
function shop(): Kernel
{
    Fakes::$mail = new MailFake();
    Fakes::$queue = new QueueFake(new Signer(KEY, Envelope::PURPOSE));

    return Kernel::fromEnvironment(
        '/tmp/tinvar-shop-marketing-none.sock',
        key: KEY,
        database: Shop::testDatabase(),
        hub: new HubFake(),
        mailTransport: Fakes::$mail,
        mailRenderer: Fakes::$mail,
        queue: Fakes::$queue,
    );
}

beforeEach(function () {
    Testing::use(shop());
});

afterEach(function () {
    Testing::forget();
    putenv('SHOP_URL');
});

function kernel(): Kernel
{
    $app = testing()->app;
    \assert($app instanceof Kernel);

    return $app;
}

function orm(): Orm
{
    return kernel()->orm;
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
 * The path of the one-click link for $list and $customer, as the shop's own
 * key signs it. The token names no origin, so any https one will do.
 */
function oneClickPath(MarketingList $list, Customer $customer): string
{
    return (string) parse_url(new Unsubscribes(KEY, new Links('https://shop.test'))->url($list, $customer->id), PHP_URL_PATH);
}

/** A mail provider's one-click POST: RFC 8058's body, and no cookie, session or CSRF token. */
function oneClick(string $path, string $body = 'List-Unsubscribe=One-Click', string $method = 'POST'): Response
{
    parse_str($body, $fields);

    return kernel()->handle(
        new Request($method, $path, ['content-type' => 'application/x-www-form-urlencoded'], [], $fields, [], null, '203.0.113.9'),
        $body,
    );
}

/** The customer a body's unsubscribe link opens the preferences of, on $base; null for any other link. */
function preferencesOf(mixed $url, string $base): ?string
{
    if (!\is_string($url) || !str_starts_with($url, $base . Links::PREFERENCES_LINK . '?token=')) {
        return null;
    }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return \is_string($query['token'] ?? null) ? new Unsubscribes(KEY, new Links($base))->preferencesOf($query['token'], orm())?->id : null;
}

/** @return array{int, list<string>} a console command's exit status, and everything it printed */
function console(string $command, array $arguments = [], ?Kernel $kernel = null): array
{
    $lines = [];
    $output = new Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line) use (&$lines): void {
        $lines[] = $line;
    });

    return [($kernel ?? kernel())->console->run($command, $arguments, $output), $lines];
}

describe('one-click unsubscribe', function () {
    it('turns the link\'s one list off for its customer, from a POST with no session or CSRF token', function () {
        $demo = customer();

        $response = oneClick(oneClickPath(MarketingList::Announcements, $demo));

        expect($response->status)->toBe(200)
            ->and($response->body())->toBe("Unsubscribed.\n")
            ->and($response->header('location'))->toBeNull()
            ->and($response->cookies())->toBe([])
            ->and(rows($demo))->toBe(['announcements' => false, 'newsletter' => true]);

        // A provider may POST it again; it is still off, and nothing else moved.
        expect(oneClick(oneClickPath(MarketingList::Announcements, $demo))->status)->toBe(200)
            ->and(rows($demo))->toBe(['announcements' => false, 'newsletter' => true]);
    });

    // A link scanner GETs every link in a mailbox; a reader's client may
    // open the URL as a page. Neither unsubscribes anyone: the GET lands on
    // the shop's page for the link, which asks first (ADR 0116).
    it('unsubscribes no one from a GET, or a POST without RFC 8058\'s body', function () {
        $demo = customer();
        $path = oneClickPath(MarketingList::Newsletter, $demo);
        $token = substr($path, \strlen(UnsubscribeLinks::PATH . '/'));
        $get = oneClick($path, method: 'GET');

        expect($get->status)->toBe(303)
            ->and($get->header('location'))->toBe(Links::UNSUBSCRIBE_LINK . '?token=' . $token)
            ->and($get->header('referrer-policy'))->toBe('no-referrer')
            ->and(oneClick($path, 'unsubscribe=yes')->status)->toBe(400)
            ->and(rows($demo))->toBe(['announcements' => true, 'newsletter' => true]);
    });

    // Fail closed: the token is all that lets the request in, so every way
    // it can be wrong is the same 404, and nothing runs.
    it('refuses a link the shop did not sign for this, whatever it names', function () {
        $demo = customer();
        $path = oneClickPath(MarketingList::Newsletter, $demo);
        [$version, $payload, $mac] = explode('.', substr($path, \strlen(UnsubscribeLinks::PATH . '/')));
        $claims = json_decode((string) Signer::decode($payload), true);
        // Another customer named over a valid signature; a link made with
        // another key; one signed with this key for another purpose; garbage.
        $altered = $version . '.' . Signer::encode((string) json_encode([...$claims, 'p' => customer('customer1@shop.test')->id])) . '.' . $mac;
        $otherKey = (string) parse_url(new Unsubscribes(KEY . '-other', new Links('https://shop.test'))->url(MarketingList::Newsletter, $demo->id), PHP_URL_PATH);
        $otherPurpose = SignedToken::encode(new Signer(KEY, UnsubscribeLinks::PURPOSE), 'route-token', $claims);

        foreach ([
            UnsubscribeLinks::PATH . "/{$altered}",
            $otherKey,
            UnsubscribeLinks::PATH . "/{$otherPurpose}",
            UnsubscribeLinks::PATH . '/v1.not.a-token',
            UnsubscribeLinks::PATH . '/',
        ] as $forged) {
            expect(oneClick($forged)->status)->toBe(404, $forged);
        }

        expect(rows($demo))->toBe(['announcements' => true, 'newsletter' => true])
            ->and(rows(customer('customer1@shop.test')))->toBe([]);
    });

    // The link is good for months and the endpoint is ahead of any throttle:
    // a replay that wrote was a write on SQLite's one writer, as often as
    // anyone holding the link cared to POST it.
    it('writes a no once, and nothing for the link POSTed again', function () {
        $path = oneClickPath(MarketingList::Announcements, customer());
        $pdo = orm()->connection()->pdo();
        \assert($pdo instanceof \Pdo\Sqlite);
        $writes = 0;
        $pdo->setAuthorizer(static function (int $action, ?string $table) use (&$writes): int {
            // SQLITE_INSERT, SQLITE_UPDATE
            if (\in_array($action, [18, 23], true) && $table === 'marketing_preferences') {
                $writes++;
            }

            return \Pdo\Sqlite::OK;
        });

        try {
            expect(oneClick($path)->status)->toBe(200);
            $first = $writes;
            for ($i = 0; $i < 20; $i++) {
                expect(oneClick($path)->status)->toBe(200);
            }
        } finally {
            $pdo->setAuthorizer(null);
        }

        expect($first)->toBeGreaterThan(0)
            ->and($writes)->toBe($first)
            ->and(rows(customer()))->toBe(['announcements' => false, 'newsletter' => true]);
    });

    // The provider shows the unsubscribe as done; a power cut must not take
    // the no back and have the next announcement go (ADR 0050).
    it('commits a no synced in full, and a yes at the next checkpoint', function () {
        $demo = customer();
        $pdo = orm()->connection()->pdo();
        \assert($pdo instanceof \Pdo\Sqlite);
        $levels = [];
        $pdo->setAuthorizer(static function (int $action, ?string $pragma, ?string $value) use (&$levels): int {
            // SQLITE_PRAGMA, setting one
            if ($action === 19 && $pragma === 'synchronous' && $value !== null) {
                $levels[] = strtoupper($value);
            }

            return \Pdo\Sqlite::OK;
        });

        try {
            expect(oneClick(oneClickPath(MarketingList::Newsletter, $demo))->status)->toBe(200);
            $unsubscribed = $levels;
            $levels = [];
            new MarketingPreferences(orm())->set($demo->id, MarketingList::Newsletter, true);
        } finally {
            $pdo->setAuthorizer(null);
        }

        expect($unsubscribed)->toBe(['FULL', 'NORMAL'])
            ->and($levels)->toBe([])
            ->and(rows($demo))->toBe(['announcements' => true, 'newsletter' => true]);
    });

    it('turns nothing on, and turns off nothing but a list the shop sends', function () {
        $other = customer('customer1@shop.test');
        $digest = (string) parse_url(UnsubscribeLinks::fromKey(KEY, Links::MAIL_ORIGIN)->url('digest', $other->id), PHP_URL_PATH);

        expect(oneClick($digest)->status)->toBe(200)
            ->and(oneClick(oneClickPath(MarketingList::Newsletter, $other))->status)->toBe(200)
            // Said no, by one click: kept as a no, never as a yes.
            ->and(rows($other))->toBe(['newsletter' => false]);
    });
});

describe('the product announcement', function () {
    it('is queued by mail:announce for the customers who said yes, and nobody else', function () {
        $other = customer('customer1@shop.test');
        actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: true)->assertOk();

        [$status, $lines] = console('mail:announce', ['--product=PW-1003']);

        expect($status)->toBe(0, implode("\n", $lines))
            ->and($lines)->toBe(['Queued the announcement of Imaginary espresso beans (PW-1003) for 1 customer; the queue worker sends it.']);
        // The other customer's yes is unconfirmed (ADR 0116): no announcement for them.
        Fakes::$queue->assertPushedCount(1, SendProductAnnouncement::class)->assertPushed(
            SendProductAnnouncement::class,
            fn (SendProductAnnouncement $job): bool => $job->customer->email === DemoAccount::EMAIL && $job->product->sku === 'PW-1003',
        );
        Fakes::$mail->assertNothingSent();
    });

    it('is sent by the worker with List-Unsubscribe and one-click List-Unsubscribe-Post, for its customer and list', function () {
        $demo = customer();
        console('mail:announce', ['--product=PW-1003']);

        [$status, $lines] = console('queue:work', ['--stop-when-empty']);

        expect($status)->toBe(0, implode("\n", $lines));
        Fakes::$mail->assertSentCount(1)->assertSentTo(DemoAccount::EMAIL, ProductAnnouncement::TEMPLATE);
        $sent = Fakes::$mail->sent(ProductAnnouncement::TEMPLATE)[0];
        $headers = $sent->message->unsubscribe?->headers() ?? [];
        expect($sent->message->subject)->toBe('New at Pellwick Supply: Imaginary espresso beans, $18.00')
            ->and($headers['List-Unsubscribe-Post'] ?? null)->toBe('List-Unsubscribe=One-Click')
            ->and(preg_match('#^<(https://pellwick\.test/_mail/unsubscribe/(v1\.[\w.-]+))>$#', $headers['List-Unsubscribe'] ?? '', $m))->toBe(1)
            // The link is this customer's, for this list, signed by the shop.
            ->and(UnsubscribeLinks::fromKey(KEY, Links::MAIL_ORIGIN)->verify($m[2]))->toBe(['list' => 'announcements', 'recipient' => $demo->id, 'tenant' => ''])
            // The body's unsubscribe link opens this customer's preferences
            // by a signed token, with no sign-in (ADR 0107).
            ->and(preferencesOf($sent->props['unsubscribeUrl'], Kernel::baseUrl()))->toBe($demo->id)
            ->and($sent->props['productUrl'])->toStartWith(Kernel::baseUrl() . '/products/');

        // And POSTed, as the reader's mail client would, it turns the list off.
        expect(oneClick((string) parse_url($m[1], PHP_URL_PATH))->status)->toBe(200)
            ->and(rows($demo))->toBe(['announcements' => false, 'newsletter' => true]);
    });

    // A no is a no from the moment it is said: queued before it, the
    // announcement asks again when it is sent.
    it('is not sent to a customer who turned announcements off after it was queued', function () {
        $demo = customer();
        console('mail:announce');
        oneClick(oneClickPath(MarketingList::Announcements, $demo));

        [$status] = console('queue:work', ['--stop-when-empty']);

        expect($status)->toBe(0);
        Fakes::$mail->assertNothingSent();
        expect(Fakes::$queue->failed())->toBe([]);
    });

    it('announces the newest product unless told which, and refuses a SKU the catalog does not have', function () {
        $newest = orm()->whereIn(Product::class, 'sku', ['PW-1024'])[0];

        [$status, $lines] = console('mail:announce');
        [$missing, $missingLines] = console('mail:announce', ['--product=PW-0000']);

        expect($status)->toBe(0)
            ->and($lines[0])->toContain("{$newest->name} ({$newest->sku})")
            ->and($missing)->toBe(64)
            ->and($missingLines)->toBe(["No product has the SKU 'PW-0000'."]);
        Fakes::$queue->assertPushedCount(1);
    });

    // A message a reader could not stop with one click is not one to send
    // in bulk: with no https address to build the link on, nothing goes.
    it('is refused, nothing queued, where the shop has no https address for its links', function () {
        putenv('SHOP_URL=http://shop.example');
        $plain = shop();

        [$status, $lines] = console('mail:announce', [], $plain);
        // Nor does anything answer a link there: no link can have been made.
        $posted = $plain->handle(
            new Request('POST', oneClickPath(MarketingList::Newsletter, customer()), ['content-type' => 'application/x-www-form-urlencoded'], [], ['List-Unsubscribe' => 'One-Click']),
            'List-Unsubscribe=One-Click',
        );

        expect($status)->toBe(78)
            ->and($lines[0])->toContain('RFC 8058')
            ->and($lines[0])->toContain('http://shop.example')
            ->and($posted->status)->toBeGreaterThanOrEqual(400)->toBeLessThan(500)
            ->and($plain->orm->connection()->select('SELECT COUNT(*) AS n FROM marketing_preferences WHERE subscribed = 0'))->toBe([['n' => 0]]);
        Fakes::$queue->assertNothingPushed();
    });

    // Plain HTTP on loopback is dev, and the mail domain stands in for the
    // https origin -- unless the environment says production, where it is
    // a proxy's backend with SHOP_URL unset, and a stand-in would send mail
    // whose one-click link reaches nobody.
    it('stands the mail domain in for an https address on loopback, and never where the environment says production', function () {
        $loopback = new Links('http://127.0.0.1:8080');

        expect($loopback->unsubscribeOrigin())->toBe('https://pellwick.test')
            ->and(new Links('http://[::1]:6123')->unsubscribeOrigin())->toBe('https://pellwick.test')
            ->and(new Links('http://127.0.0.1.shop.example')->unsubscribeOrigin())->toBeNull()
            ->and(new Links('https://shop.test')->unsubscribeOrigin())->toBe('https://shop.test')
            ->and(new Unsubscribes(KEY, $loopback, [])->refusal())->toBeNull()
            ->and(new Unsubscribes(KEY, $loopback, ['APP_ENV' => 'production'])->refusal())->toContain('RFC 8058')
            ->and(new Unsubscribes(KEY, new Links('https://shop.test'), ['APP_ENV' => 'production'])->refusal())->toBeNull();
    });

    it('builds its links on the shop\'s own address when that is https', function () {
        putenv('SHOP_URL=https://shop.test');
        Testing::forget();
        Testing::use(shop());
        console('mail:announce');

        console('queue:work', ['--stop-when-empty']);

        $sent = Fakes::$mail->sent(ProductAnnouncement::TEMPLATE)[0];
        expect($sent->message->unsubscribe?->headers()['List-Unsubscribe'] ?? '')->toStartWith('<https://shop.test/_mail/unsubscribe/v1.')
            ->and($sent->props['unsubscribeUrl'])->toStartWith('https://shop.test/email/preferences?token=v1.');
    });
});

// ADR 0107: the newsletter the preferences page lists, sent as the
// announcement is -- to its subscribers alone, with one-click
// List-Unsubscribe headers and a body link that needs no sign-in.
describe('the newsletter', function () {
    it('is queued by mail:newsletter for the customers who said yes to it, and nobody else', function () {
        $other = customer('customer1@shop.test');
        actingAs($other)->action('account.setMarketing', customer: $other, list: 'announcements', subscribed: true)->assertOk();

        [$status, $lines] = console('mail:newsletter', ['--issue=October 2026']);

        expect($status)->toBe(0, implode("\n", $lines))
            ->and($lines)->toBe(['Queued the October 2026 newsletter for 1 customer; the queue worker sends it.']);
        Fakes::$queue->assertPushedCount(1, SendNewsletter::class)->assertPushed(
            SendNewsletter::class,
            fn (SendNewsletter $job): bool => $job->customer->email === DemoAccount::EMAIL && \count($job->products) === NewsletterIssues::PRODUCTS,
        );
        Fakes::$mail->assertNothingSent();
    });

    it('is sent with one-click List-Unsubscribe for its list, and a body link to the reader\'s preferences', function () {
        $demo = customer();
        console('mail:newsletter', ['--issue=October 2026']);

        [$status, $lines] = console('queue:work', ['--stop-when-empty']);

        expect($status)->toBe(0, implode("\n", $lines));
        Fakes::$mail->assertSentCount(1)->assertSentTo(DemoAccount::EMAIL, Newsletter::TEMPLATE);
        $sent = Fakes::$mail->sent(Newsletter::TEMPLATE)[0];
        $headers = $sent->message->unsubscribe?->headers() ?? [];
        expect($sent->message->subject)->toStartWith('Pellwick Supply, October 2026: ')
            ->and($headers['List-Unsubscribe-Post'] ?? null)->toBe('List-Unsubscribe=One-Click')
            ->and(preg_match('#^<(https://pellwick\.test/_mail/unsubscribe/(v1\.[\w.-]+))>$#', $headers['List-Unsubscribe'] ?? '', $m))->toBe(1)
            ->and(UnsubscribeLinks::fromKey(KEY, Links::MAIL_ORIGIN)->verify($m[2]))->toBe(['list' => 'newsletter', 'recipient' => $demo->id, 'tenant' => ''])
            ->and(preferencesOf($sent->props['unsubscribeUrl'], Kernel::baseUrl()))->toBe($demo->id)
            ->and($sent->props['products'])->toHaveCount(NewsletterIssues::PRODUCTS);

        // One click from the reader's mail client, no sign-in, turns it off.
        expect(oneClick((string) parse_url($m[1], PHP_URL_PATH))->status)->toBe(200)
            ->and(rows($demo))->toBe(['announcements' => true, 'newsletter' => false]);
    });

    it('is not sent to a customer who turned it off after it was queued', function () {
        $demo = customer();
        console('mail:newsletter');
        oneClick(oneClickPath(MarketingList::Newsletter, $demo));

        [$status] = console('queue:work', ['--stop-when-empty']);

        expect($status)->toBe(0);
        Fakes::$mail->assertNothingSent();
    });

    it('is refused, nothing queued, where the shop has no https address, or for an issue no subject should carry', function () {
        putenv('SHOP_URL=http://shop.example');
        [$plain, $plainLines] = console('mail:newsletter', [], shop());
        putenv('SHOP_URL');
        [$bad] = console('mail:newsletter', ["--issue=a\nb"], shop());

        expect($plain)->toBe(78)
            ->and($plainLines[0])->toContain('RFC 8058')
            ->and($bad)->toBe(64);
        Fakes::$queue->assertNothingPushed();
    });
});

describe('drawn by the renderer', function () {
    beforeEach(function () {
        $unbuilt = Shop::ensureBuilt();
        if ($unbuilt !== null) {
            test()->markTestSkipped($unbuilt);
        }
    });

    it('is drawn from @tinvar/mail\'s kit as MER-36 asks, and logged with its List-Unsubscribe headers', function () {
        $directory = \Tinvar\Support\PrivateDirectory::create('tinvar-mktshop');
        $database = "{$directory}/shop.sqlite";
        new \Shop\Database\Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);
        $renderer = Renderer::start('msgpack', 'marketing-mail');
        putenv("SHOP_MAIL_LOG={$directory}/mail.log");
        putenv('SHOP_URL=https://shop.test');

        try {
            $shop = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database);
            [$queued] = console('mail:announce', ['--product=PW-1007'], $shop);
            [$worked, $lines] = console('queue:work', ['--stop-when-empty'], $shop);
            $sent = LogTransport::read("{$directory}/mail.log");
            $demo = (string) $shop->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];

            expect([$queued, $worked])->toBe([0, 0], implode("\n", $lines))
                ->and($sent)->toHaveCount(1)
                ->and($sent[0]['to'])->toBe(['"' . DemoAccount::NAME . '" <' . DemoAccount::EMAIL . '>'])
                ->and($sent[0]['subject'])->toBe('New at Pellwick Supply: Pocket thunderstorm, $36.00')
                ->and($sent[0]['listUnsubscribePost'])->toBe('List-Unsubscribe=One-Click')
                ->and(preg_match('#^<https://shop\.test(/_mail/unsubscribe/v1\.[\w.-]+)>$#', (string) $sent[0]['listUnsubscribe'], $m))->toBe(1);

            $html = (string) $sent[0]['html'];
            $text = (string) $sent[0]['text'];
            // The first line says what happened (MER-36.4); inline styles and
            // tables (MER-36.1); the logo, why, how to stop, and the postal
            // address (MER-36.3); every link in the text part (MER-36.2).
            expect($text)->toContain('Pocket thunderstorm is in the catalog')
                ->and($html)->toContain('<table')
                ->and($html)->not->toMatch('/\sclass="/')
                ->and($html)->toContain('src="https://shop.test/brand/mark-64.png"')
                ->and($text)->toContain('Why am I getting this? You turned on product announcements for ' . DemoAccount::EMAIL)
                ->and($html)->toContain('href="https://shop.test/email/preferences?token=v1.')
                ->and($text)->toContain('Pellwick Supply · 1 Example Way · Springfield, ST 00000');
            foreach (\Tinvar\Mail\RenderedMail::links($html) as $link) {
                expect($link)->toStartWith('https://shop.test/')
                    ->and($text)->toContain($link);
            }

            // The logged link, POSTed to the shop, unsubscribes its reader.
            $response = $shop->handle(
                new Request('POST', $m[1], ['content-type' => 'application/x-www-form-urlencoded'], [], ['List-Unsubscribe' => 'One-Click']),
                'List-Unsubscribe=One-Click',
            );
            expect($response->status)->toBe(200)
                ->and($shop->orm->connection()->select('SELECT subscribed FROM marketing_preferences WHERE customer_id = ? AND list = ?', [$demo, 'announcements']))
                ->toBe([['subscribed' => 0]]);
        } finally {
            putenv('SHOP_MAIL_LOG');
            putenv('SHOP_URL');
            $renderer->stop();
            \Tinvar\Support\PrivateDirectory::remove($directory);
        }
    });
});

// ADR 0116: a yes to marketing is asked of the inbox, recorded with where
// it was said, and turns the list on only once its single-use link is used.
describe('the double opt-in', function () {
    /** The confirmation's link for $customer, sent by the worker; its token. */
    function confirmation(): string
    {
        [$status, $lines] = console('queue:work', ['--stop-when-empty']);
        expect($status)->toBe(0, implode("\n", $lines));
        $sent = Fakes::$mail->sent(ConfirmSubscription::TEMPLATE);
        $url = (string) end($sent)->props['url'];
        expect($url)->toStartWith(Links::MAIL_ORIGIN . Links::CONFIRM_LINK . '?token=');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    /** @return list<array<string, mixed>> */
    function consents(Customer $customer): array
    {
        return orm()->connection()->select('SELECT list, address, source, confirmed_at, link_id FROM marketing_opt_ins WHERE customer_id = ? ORDER BY requested_at', [$customer->id]);
    }

    it('asks the inbox, records where the yes was said, and turns the list on once its link is used', function () {
        $other = customer('customer1@shop.test');

        $asked = actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: true)->assertOk();
        expect($asked->value())->toBe(['list' => 'newsletter', 'subscribed' => false, 'pending' => true]);
        expect(rows($other))->toBe([]);
        Fakes::$queue->assertPushed(SendOptInConfirmation::class);
        Fakes::$mail->assertNothingSent();

        $token = confirmation();
        Fakes::$mail->assertSentTo('customer1@shop.test', ConfirmSubscription::TEMPLATE);
        $sent = Fakes::$mail->sent(ConfirmSubscription::TEMPLATE)[0];
        // Not marketing: no List-Unsubscribe, and nothing to leave yet.
        expect($sent->message->unsubscribe)->toBeNull();

        // Opening the link confirms nothing: a scanner opens links too.
        get(Links::CONFIRM_LINK . '?token=' . $token)->assertOk()
            ->assertHeader('referrer-policy', 'no-referrer')
            ->assertPage(ConfirmMarketing::class, fn (AssertablePage $page) => $page
                ->where('token', $token)
                ->where('list', 'Newsletter')
                ->where('email', 'customer1@shop.test')
                ->where('done', false));
        expect(rows($other))->toBe([]);

        action('email.confirmSubscription', token: $token)->assertRedirect(Links::CONFIRM_LINK . '?confirmed=newsletter');
        expect(rows($other))->toBe(['newsletter' => true])
            ->and(consents($other)[0]['list'])->toBe('newsletter')
            ->and(consents($other)[0]['address'])->toBe('customer1@shop.test')
            ->and(consents($other)[0]['source'])->toBe(ConsentSource::Account->value)
            ->and(consents($other)[0]['confirmed_at'])->toBeString();

        // Single-use: used, it confirms nothing again.
        action('email.confirmSubscription', token: $token)->assertInvalid(['token' => MarketingLinkActions::CONFIRM_REFUSED]);
        get(Links::CONFIRM_LINK . '?token=' . $token)->assertPage(ConfirmMarketing::class, fn (AssertablePage $page) => $page
            ->where('token', '')->where('list', null)->etc());
    });

    it('says where the yes came from when an emailed link said it', function () {
        $other = customer('customer1@shop.test');
        $url = new Unsubscribes(KEY, new Links('https://shop.test'))->preferencesUrl($other->id, $other->email);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        action('email.setByLink', token: (string) $query['token'], list: 'announcements', subscribed: true)->assertOk();
        action('email.confirmSubscription', token: confirmation())->assertRedirect();

        expect(consents($other)[0]['source'])->toBe(ConsentSource::EmailLink->value)
            ->and(rows($other))->toBe(['announcements' => true]);
    });

    // A no said since puts back nothing an older confirmation would.
    it('withdraws a pending yes when the list is turned off, its link with it', function () {
        $other = customer('customer1@shop.test');
        actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: true)->assertOk();
        $token = confirmation();
        actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: false)->assertOk();

        action('email.confirmSubscription', token: $token)->assertInvalid(['token' => MarketingLinkActions::CONFIRM_REFUSED]);
        expect(rows($other))->toBe(['newsletter' => false])
            ->and(consents($other))->toBe([]);
    });

    it('opens for the address it went to and no other', function () {
        $other = customer('customer1@shop.test');
        actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: true)->assertOk();
        $token = confirmation();
        orm()->connection()->execute('UPDATE customers SET email = ? WHERE id = ?', ['moved-1@shop.test', $other->id]);
        orm()->flush();

        action('email.confirmSubscription', token: $token)->assertInvalid(['token' => MarketingLinkActions::CONFIRM_REFUSED]);
        expect(rows(customer('moved-1@shop.test')))->toBe([]);
    });

    it('sends one confirmation for a switch flipped over and over, and none for a list already on', function () {
        $demo = customer();
        $other = customer('customer1@shop.test');
        foreach ([true, true, true] as $on) {
            actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: $on)->assertOk();
        }
        $already = actingAs($demo)->action('account.setMarketing', customer: $demo, list: 'newsletter', subscribed: true)->assertOk();
        expect($already->value())->toBe(['list' => 'newsletter', 'subscribed' => true, 'pending' => false]);

        Fakes::$queue->assertPushedCount(1, SendOptInConfirmation::class);
    });

    // Off withdraws the pending yes, row and all; the window is kept apart
    // from it, so off and on again is no way around it.
    it('sends no second confirmation within the window for a switch turned off and on again', function () {
        $other = customer('customer1@shop.test');
        $flip = static fn (bool $on) => actingAs($other)->action('account.setMarketing', customer: $other, list: 'newsletter', subscribed: $on)->assertOk();

        $flip(true);
        confirmation();
        $flip(false);
        $again = $flip(true);
        [$status, $lines] = console('queue:work', ['--stop-when-empty']);
        expect($status)->toBe(0, implode("\n", $lines));

        // Asked for, and on its way once the window has passed.
        expect($again->value())->toBe(['list' => 'newsletter', 'subscribed' => false, 'pending' => true])
            ->and(Fakes::$mail->sent(ConfirmSubscription::TEMPLATE))->toHaveCount(1)
            ->and(consents($other))->toHaveCount(1);

        // Once it has passed, at once.
        orm()->connection()->execute('UPDATE marketing_opt_in_sends SET sent_at = ? WHERE customer_id = ?', [gmdate('Y-m-d\TH:i:s\Z', time() - MarketingConsents::RESEND_AFTER - 1), $other->id]);
        $flip(false);
        $flip(true);
        confirmation();
        expect(Fakes::$mail->sent(ConfirmSubscription::TEMPLATE))->toHaveCount(2);
    });

    it('refuses a token that is not a confirmation link, the same way', function () {
        $demo = customer();
        $preferences = new Unsubscribes(KEY, new Links('https://shop.test'))->preferencesUrl($demo->id, $demo->email);
        parse_str((string) parse_url($preferences, PHP_URL_QUERY), $query);

        foreach ([(string) $query['token'], substr(oneClickPath(MarketingList::Newsletter, $demo), \strlen(UnsubscribeLinks::PATH . '/')), 'v1.not.a-token'] as $token) {
            action('email.confirmSubscription', token: $token)->assertInvalid(['token' => MarketingLinkActions::CONFIRM_REFUSED]);
        }
    });
});

// ADR 0116: the page a reader who opens a one-click link lands on, which
// asks before it turns the list off.
describe('the page a one-click link lands on', function () {
    it('names the list and asks, and its button turns that one list off', function () {
        $demo = customer();
        $token = substr(oneClickPath(MarketingList::Newsletter, $demo), \strlen(UnsubscribeLinks::PATH . '/'));

        get(Links::UNSUBSCRIBE_LINK . '?token=' . $token)->assertOk()
            ->assertHeader('referrer-policy', 'no-referrer')
            ->assertPage(OneClickUnsubscribe::class, fn (AssertablePage $page) => $page
                ->where('token', $token)->where('list', 'Newsletter')->where('email', null)->where('done', false));
        expect(rows($demo))->toBe(['announcements' => true, 'newsletter' => true]);

        action('email.unsubscribeByOneClickLink', token: $token)->assertRedirect(Links::UNSUBSCRIBE_LINK . '?stopped=newsletter');
        expect(rows($demo))->toBe(['announcements' => true, 'newsletter' => false]);
    });

    it('shows nothing and changes nothing for a link it did not make', function () {
        $demo = customer();
        $forged = substr((string) parse_url(new Unsubscribes(KEY . '-other', new Links('https://shop.test'))->url(MarketingList::Newsletter, $demo->id), PHP_URL_PATH), \strlen(UnsubscribeLinks::PATH . '/'));

        get(Links::UNSUBSCRIBE_LINK . '?token=' . $forged)->assertPage(OneClickUnsubscribe::class, fn (AssertablePage $page) => $page
            ->where('token', '')->where('list', null)->etc());
        action('email.unsubscribeByOneClickLink', token: $forged)->assertInvalid(['token' => MarketingLinkActions::LINK_REFUSED]);
        expect(rows($demo))->toBe(['announcements' => true, 'newsletter' => true]);
    });
});

// ADR 0116: the newsletter goes once a month by itself.
describe('the scheduled newsletter', function () {
    function issues(): NewsletterIssues
    {
        return new NewsletterIssues(orm(), kernel()->jobs, new MarketingPreferences(orm()), new Unsubscribes(KEY, new Links(Kernel::baseUrl())));
    }

    it('queues the month\'s issue once, however many times it runs, from nine on the first', function () {
        $task = new ScheduleNewsletter();

        expect($task->run(issues(), new \DateTimeImmutable('2026-11-01T08:59:00Z')))->toBeNull()
            ->and($task->run(issues(), new \DateTimeImmutable('2026-11-01T09:23:00Z')))->toBe(1)
            ->and($task->run(issues(), new \DateTimeImmutable('2026-11-01T10:23:00Z')))->toBeNull()
            ->and($task->run(issues(), new \DateTimeImmutable('2026-11-17T10:23:00Z')))->toBeNull()
            ->and(orm()->connection()->select('SELECT month, title, queued FROM newsletter_issues'))->toBe([['month' => '2026-11', 'title' => 'November 2026', 'queued' => 1]]);
        Fakes::$queue->assertPushedCount(1, SendNewsletter::class)->assertPushed(
            SendNewsletter::class,
            fn (SendNewsletter $job): bool => $job->customer->email === DemoAccount::EMAIL && $job->issue === 'November 2026',
        );

        expect($task->run(issues(), new \DateTimeImmutable('2026-12-02T00:23:00Z')))->toBe(1);
    });

    it('is listed among the shop\'s tasks, hourly', function () {
        $schedule = new \ReflectionClass(ScheduleNewsletter::class)->getAttributes(\Tinvar\Attributes\Schedule::class)[0]->newInstance();

        expect(Kernel::classes()->tasks)->toContain(ScheduleNewsletter::class)
            ->and($schedule->expression)->toBe('23 * * * *');
    });

    it('claims nothing where the shop has no https address, so a later run sends it', function () {
        $issues = new NewsletterIssues(orm(), kernel()->jobs, new MarketingPreferences(orm()), new Unsubscribes(KEY, new Links('http://shop.example'), []));

        expect(new ScheduleNewsletter()->run($issues, new \DateTimeImmutable('2026-11-03T12:00:00Z')))->toBeNull()
            ->and(orm()->connection()->select('SELECT month FROM newsletter_issues'))->toBe([]);
        Fakes::$queue->assertNotPushed(SendNewsletter::class);
    });
});
