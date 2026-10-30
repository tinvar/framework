<?php

declare(strict_types=1);

/**
 * The shop's exports and imports through the request path and the queue
 * worker, without the renderer (ADR 0070): staff export every order as a
 * CSV, emailed a link on the shop's canonical address, and import stock
 * levels from one -- and a customer, a staff member without the
 * permission, a moved auth epoch and a bad file are each refused.
 */

namespace Tinvar\Tests\Integration\ShopTransfers;

use Shop\Auth\AuthEpochs;
use Shop\Auth\StaffMemberships;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Queries\Availability;
use Shop\Pages\Admin\Transfers as TransfersPage;
use Shop\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Support\Signer;
use Tinvar\Testing\AssertablePage;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Testing;
use Tinvar\Transfers\TransferRecord;
use Tinvar\Transfers\Transfers;

use function Tinvar\Testing\actingAs;

const KEY = 'shop-transfers-test-key-of-32-bytes!';

/** A browser signed in as one customer: its session cookie, and the session's CSRF token. */
final class Staff
{
    /** @var array<string, string> */
    public array $cookies;

    public function __construct(public readonly Kernel $kernel, public readonly Customer $customer)
    {
        $this->cookies = $kernel->signedIn($customer, new Request('GET', '/', ['host' => '127.0.0.1']));
    }

    /** @param array<string, mixed> $body */
    public function export(array $body = []): Response
    {
        return $this->kernel->handle(
            new Request('POST', '/_export/orders', $this->headers(['content-type' => 'application/json']), [], [], $this->withToken()),
            json_encode((object) $body, JSON_THROW_ON_ERROR),
        );
    }

    public function import(string $csv): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'shop-import-');
        \assert(\is_string($path));
        file_put_contents($path, $csv);

        try {
            return $this->kernel->handle(new Request(
                'POST',
                '/_import/stock',
                $this->headers(['content-type' => 'multipart/form-data; boundary=x']),
                [],
                [],
                $this->withToken(),
                files: ['file' => new UploadedFile($path, 'stock.csv', 'text/csv', UPLOAD_ERR_OK, \strlen($csv))],
            ));
        } finally {
            @unlink($path);
        }
    }

    public function download(string $path): Response
    {
        return $this->kernel->handle(new Request('GET', $path, ['host' => '127.0.0.1'], [], [], $this->cookies));
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra): array
    {
        return ['host' => '127.0.0.1', 'accept' => 'application/json', 'x-xsrf-token' => $this->token(), ...$extra];
    }

    /** @return array<string, string> */
    private function withToken(): array
    {
        return [...$this->cookies, '__Host-XSRF-TOKEN' => $this->token()];
    }

    private function token(): string
    {
        return (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));
    }
}

beforeEach(function () {
    $this->database = Shop::testDatabase();
    $this->mail = new MailFake();
    $this->kernel = Kernel::fromEnvironment(
        '/tmp/tinvar-shop-transfers-none.sock',
        key: KEY,
        database: $this->database,
        mailTransport: $this->mail,
        mailRenderer: $this->mail,
    );
    Testing::use($this->kernel);
});

afterEach(function () {
    Testing::forget();
});

function account(Kernel $kernel, string $email): Customer
{
    $id = (string) $kernel->orm->connection()->select('SELECT id FROM customers WHERE email = ?', [$email])[0]['id'];
    $customer = $kernel->orm->find(Customer::class, $id);
    \assert($customer instanceof Customer);

    return $customer;
}

/** Works the transfers queue, then the mail it queued, as `tinvar queue:work` does. */
function work(Kernel $kernel): void
{
    $output = new Output(static function (string $line): void {}, static function (string $line): void {});
    expect($kernel->console->run('queue:work', ['--queue=' . Transfers::QUEUE . ',mail', '--stop-when-empty'], $output))->toBe(0);
}

/** @return list<TransferRecord> */
function records(Kernel $kernel): array
{
    return array_map(
        static fn (array $row): TransferRecord => new TransferRecord(
            (string) $row['id'],
            $row['kind'] === 'import' ? 'import' : 'export',
            (string) $row['name'],
            (string) $row['format'],
            (string) $row['tenant'],
            (string) $row['panel'],
            (string) $row['user_id'],
            match ($row['status']) {
                'queued' => 'queued', 'running' => 'running', 'done' => 'done', default => 'failed',
            },
            (int) $row['created_at'],
            rows: (int) $row['row_count'],
            errorCount: (int) $row['error_count'],
            key: (string) $row['storage_key'],
            expiresAt: (int) $row['expires_at'],
            failure: (string) $row['failure'],
        ),
        $kernel->orm->connection()->select('SELECT * FROM transfers ORDER BY created_at, id'),
    );
}

it('exports every order as a CSV the staff member downloads, and emails the link on the shop\'s address', function () {
    $staff = new Staff($this->kernel, account($this->kernel, StaffAccount::EMAIL));
    $orders = (int) $this->kernel->orm->connection()->select('SELECT COUNT(*) AS n FROM orders')[0]['n'];

    expect($staff->export(['format' => 'csv'])->status)->toBe(202);
    work($this->kernel);

    [$record] = records($this->kernel);
    expect($record->status)->toBe(TransferRecord::DONE)
        ->and($record->rows)->toBe($orders)
        ->and($record->panel)->toBe(StaffMemberships::PANEL);

    // The email: a link on the shop's canonical https address, never the
    // Host of the request that started it.
    $this->mail->assertSent('export-ready', static fn (\Tinvar\Testing\Fakes\SentMail $mail): bool => str_starts_with((string) $mail->props['downloadUrl'], 'https://pellwick.test/_download/') && $mail->isTo(StaffAccount::EMAIL));
    $link = (string) $this->mail->sent('export-ready')[0]->props['downloadUrl'];

    $response = $staff->download(substr($link, \strlen('https://pellwick.test')));
    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toBe('text/csv; charset=utf-8');
    $body = $response->body();
    expect($body)->toStartWith("number,status,placedAt,customerName,customerEmail,subtotalCents,shippingCents,totalCents\r\n")
        ->and(substr_count($body, "\r\n"))->toBe($orders + 1)
        ->and($body)->not->toContain('password');

    // A customer opening the forwarded link sees nothing.
    $customer = new Staff($this->kernel, account($this->kernel, DemoAccount::EMAIL));
    expect($customer->download(substr($link, \strlen('https://pellwick.test')))->status)->toBeIn([403, 404]);
});

it('lists the staff member\'s own transfers on the page, with a link minted for them', function () {
    $account = account($this->kernel, StaffAccount::EMAIL);
    expect(new Staff($this->kernel, $account)->export()->status)->toBe(202);
    work($this->kernel);

    actingAs($account)->get('/admin/transfers')
        ->assertOk()
        ->assertPage(TransfersPage::class, fn (AssertablePage $page) => $page
            ->where('stockColumns', ['sku', 'stock'])
            ->has('transfers', 1, fn (AssertablePage $t) => $t
                ->where('kind', 'export')
                ->where('status', 'done')
                ->where('download', fn (?string $d): bool => \is_string($d) && str_starts_with($d, '/_download/'))
                ->etc())
            ->etc());
});

it('refuses a customer, and staff without the permission, and starts nothing', function () {
    $customer = new Staff($this->kernel, account($this->kernel, DemoAccount::EMAIL));
    expect($customer->export()->status)->toBe(403);

    $staffAccount = account($this->kernel, StaffAccount::EMAIL);
    new StaffMemberships($this->kernel->orm)->grant($staffAccount->id, [StaffMemberships::VIEW_ALL_ORDERS]);
    $staff = new Staff($this->kernel, $staffAccount);
    expect($staff->export()->status)->toBe(403)
        ->and($staff->import("sku,stock\nX,1\n")->status)->toBe(403)
        ->and(records($this->kernel))->toBe([]);
});

it('writes nothing once the staff member\'s epoch has moved before the worker ran it', function () {
    $account = account($this->kernel, StaffAccount::EMAIL);
    expect(new Staff($this->kernel, $account)->export()->status)->toBe(202);

    new AuthEpochs($this->kernel->orm)->bump($account->id, $this->kernel->orm->connection());
    work($this->kernel);

    // Failed on its record at once, where the staff member reads it (ADR
    // 0107); nobody is signed in to be told by mail.
    expect(records($this->kernel)[0]->status)->toBe(TransferRecord::FAILED)
        ->and(records($this->kernel)[0]->failure)->toBe('You may no longer run this export.')
        ->and(records($this->kernel)[0]->key)->toBe('');
    $this->mail->assertNothingSent();
});

it('imports stock levels, every row or none', function () {
    $staff = new Staff($this->kernel, account($this->kernel, StaffAccount::EMAIL));
    $skus = array_column($this->kernel->orm->connection()->select('SELECT sku FROM products ORDER BY sku LIMIT 2'), 'sku');
    $stock = static fn (string $sku): int => (int) $staff->kernel->orm->connection()->select('SELECT stock FROM products WHERE sku = ?', [$sku])[0]['stock'];
    $before = $stock($skus[0]);

    // One bad row -- an unknown SKU, a formula -- and nothing changes.
    expect($staff->import("sku,stock\n{$skus[0]},77\nNOPE-1,3\n{$skus[1]},=1+1\n")->status)->toBe(202);
    work($this->kernel);
    $failed = records($this->kernel)[0];
    expect($failed->status)->toBe(TransferRecord::FAILED)
        ->and($failed->errorCount)->toBe(2)
        ->and($stock($skus[0]))->toBe($before);

    // Every row good, and every row applied.
    expect($staff->import("stock,sku\n77,{$skus[0]}\n0,{$skus[1]}\n")->status)->toBe(202);
    work($this->kernel);
    expect(records($this->kernel)[1]->status)->toBe(TransferRecord::DONE)
        ->and($stock($skus[0]))->toBe(77)
        ->and($stock($skus[1]))->toBe(0);
});

// ADR 0075: the import declares the tag the catalog's availability reads,
// so a page showing stock is told once the rows commit -- and no hold on a
// once-shared value carrying it is believed after them.
it('invalidates the catalog\'s stock once an import applies, and not when it is refused', function () {
    $staff = new Staff($this->kernel, account($this->kernel, StaffAccount::EMAIL));
    $sku = (string) $this->kernel->orm->connection()->select('SELECT sku FROM products ORDER BY sku LIMIT 1')[0]['sku'];
    $stamp = fn (): ?string => $this->kernel->kernel()->sharedTags?->stamp([Availability::TAG], '');
    $before = $stamp();

    expect($staff->import("sku,stock\n{$sku},-4\n")->status)->toBe(202);
    work($this->kernel);
    expect($stamp())->toBe($before);

    expect($staff->import("sku,stock\n{$sku},12\n")->status)->toBe(202);
    work($this->kernel);
    expect($stamp())->not->toBeNull()->not->toBe($before);
});

it('refuses a file that is not the stock CSV before anything is stored', function () {
    $staff = new Staff($this->kernel, account($this->kernel, StaffAccount::EMAIL));

    expect($staff->import("sku,price\nX,1\n")->status)->toBe(422)
        ->and($staff->import("sku,stock\nCaf\xE9,1\n")->status)->toBe(422)
        ->and($staff->import("sku,stock\n" . str_repeat("X,1\n", 1_001))->status)->toBe(422)
        ->and(records($this->kernel))->toBe([]);
});
