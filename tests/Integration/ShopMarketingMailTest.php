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

use Shop\Commands\SendNewsletterIssue;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Mail\Links;
use Shop\Mail\MarketingList;
use Shop\Mail\MarketingPreferences;
use Shop\Mail\Newsletter;
use Shop\Mail\ProductAnnouncement;
use Shop\Mail\SendNewsletter;
use Shop\Mail\SendProductAnnouncement;
use Shop\Mail\Unsubscribes;
use Shop\Models\Customer;
use Shop\Models\Product;
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
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;
use Tinvar\Testing\Testing;

use function Tinvar\Testing\actingAs;
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

    return \is_string($query['token'] ?? null) ? new Unsubscribes(KEY, new Links($base))->preferencesOf($query['token']) : null;
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
    // open the URL as a page. Neither unsubscribes anyone.
    it('unsubscribes no one from a GET, or a POST without RFC 8058\'s body', function () {
        $demo = customer();
        $path = oneClickPath(MarketingList::Newsletter, $demo);

        expect(oneClick($path, method: 'GET')->status)->toBe(405)
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
        Fakes::$queue->assertPushedCount(1)->assertPushed(
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
        Fakes::$queue->assertPushedCount(1)->assertPushed(
            SendNewsletter::class,
            fn (SendNewsletter $job): bool => $job->customer->email === DemoAccount::EMAIL && \count($job->products) === SendNewsletterIssue::PRODUCTS,
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
            ->and($sent->props['products'])->toHaveCount(SendNewsletterIssue::PRODUCTS);

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
