<?php

declare(strict_types=1);

/**
 * The shop's account page, with the renderer: the upload tickets its loader
 * hands the two forms, a file sent to /_upload under one, the action called
 * with the reference, and the file shown back by a URL minted for this
 * session alone -- and another customer turned away at each step. Skipped
 * when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\ShopUploads;

use Shop\Database\Models\Account\Receipt;
use Shop\Http\Actions\Account\AccountActions;
use Shop\Database\Seeders\Seeder;
use Shop\Support\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Actions\ActionResult;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Support\Signer;
use Tinvar\Testing\Fakes\StorageFake;
use Tinvar\Uploads\UploadEndpoint;
use Tinvar\Uploads\UploadTicket;

const KEY = 'shop-uploads-test-key-of-32-bytes!!!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** A browser of one: a cookie jar, and the Kernel it talks to. */
final class Visitor
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(public readonly Kernel $kernel) {}

    public function payload(string $path): Response
    {
        return $this->keep($this->kernel->handle(
            new Request('GET', $path, ['host' => 'localhost', 'rsc' => '1', 'accept' => 'text/x-component', 'x-version' => 'dev'], [], [], $this->cookies, null, '127.0.0.1'),
        ));
    }

    public function get(string $path): Response
    {
        return $this->keep($this->kernel->handle(new Request('GET', $path, ['host' => 'localhost', 'accept' => '*/*'], [], [], $this->cookies, null, '127.0.0.1')));
    }

    /** @param array<string, mixed> $body */
    public function action(string $name, array $body): Response
    {
        $token = $this->csrf();

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['host' => 'localhost', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token, 'rsc' => '1'],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                '127.0.0.1',
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    /** POSTs a file to /_upload as the upload field does. */
    public function upload(string $ticket, string $bytes, string $name): Response
    {
        $token = $this->csrf();
        $path = (string) tempnam(sys_get_temp_dir(), 'tinvar-shop-upload-');
        file_put_contents($path, $bytes);

        try {
            return $this->keep($this->kernel->handle(new Request(
                'POST',
                '/_upload',
                ['host' => 'localhost', 'content-type' => 'multipart/form-data; boundary=x', 'accept' => 'application/json', 'x-xsrf-token' => $token, 'rsc' => '1'],
                [],
                ['ticket' => $ticket],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                '127.0.0.1',
                ['file' => new UploadedFile($path, $name, 'image/png', UPLOAD_ERR_OK, \strlen($bytes))],
            )));
        } finally {
            @unlink($path);
        }
    }

    /**
     * POSTs to a resumable upload's paths as the upload field does: JSON, or
     * a chunk as the raw body under its session and offset.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, string> $headers
     */
    public function session(string $path, array|string $body, array $headers = []): Response
    {
        $token = $this->csrf();
        $chunk = \is_string($body);

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                $path,
                ['host' => 'localhost',
                    'content-type' => $chunk ? 'application/offset+octet-stream' : 'application/json',
                    'accept' => 'application/json',
                    'x-xsrf-token' => $token,
                    'rsc' => '1',
                    ...$headers,
                ],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                '127.0.0.1',
            ),
            $chunk ? $body : json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    public function signIn(string $email, string $password): void
    {
        $this->get('/sign-in');
        $response = $this->action('account.signIn', ['email' => $email, 'password' => $password, 'next' => '/account']);
        expect($response->status)->toBe(409);
    }

    private function csrf(): string
    {
        return (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', cookies: $this->cookies, headers: ['host' => 'localhost']));
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

/** A seeded database of the shop's own, in a directory of its own. */
function database(): string
{
    $directory = PrivateDirectory::create('tinvar-shopup');
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });
    $path = "{$directory}/shop.sqlite";
    new Seeder(PdoConnection::sqlite($path, 'shop', create: true))->run(customers: 1);

    return $path;
}

/**
 * The upload tickets a payload carries, by the action parameter each is for.
 *
 * @return array<string, string>
 */
function tickets(Response $payload): array
{
    preg_match_all('/v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', $payload->body(), $matches);
    $tickets = [];
    foreach (array_unique($matches[0]) as $candidate) {
        try {
            $ticket = UploadTicket::decode($candidate, new Signer(KEY, UploadTicket::PURPOSE));
        } catch (\Tinvar\Render\TokenError) {
            continue;
        }
        $tickets[$ticket->field] = $candidate;
    }

    return $tickets;
}

function png(): string
{
    $image = imagecreatetruecolor(8, 8);
    \assert($image !== false);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('uploads an avatar and a receipt from the account page, and shows them to the customer alone', function () {
    $database = database();
    $renderer = Renderer::start('msgpack', 'shop-uploads');
    // The uploaded files in a fake, which says where each was kept.
    $storage = new StorageFake();
    $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database, storage: $storage);
    $customer = new Visitor($kernel);
    $other = new Visitor($kernel);
    $pdo = new \PDO("sqlite:{$database}");
    $demo = (string) $pdo->query('SELECT id FROM customers WHERE email = ' . $pdo->quote(DemoAccount::EMAIL))?->fetchColumn();

    try {
        // Signed out, the account page sends a visitor to sign in.
        expect($customer->get('/account')->header('location'))->toBe('/sign-in?next=%2Faccount');

        $customer->signIn(DemoAccount::EMAIL, DemoAccount::PASSWORD);
        $page = $customer->payload('/account');
        expect($page->status)->toBe(200)
            ->and($page->body())->toContain('No receipts yet');
        $tickets = tickets($page);
        expect(array_keys($tickets))->toEqualCanonicalizing(['avatar', 'receipt']);

        // Another customer, signed in in another browser, cannot use them.
        $other->signIn('customer1@shop.test', 'pellwick-1');
        $refused = $other->upload($tickets['avatar'], $bytes = png(), 'me.png');
        expect($refused->status)->toBe(403);
        $storage->assertNothingStored();

        // The customer uploads, then saves: the action holds the reference
        // to this account, and keeps the key the framework made.
        $uploaded = $customer->upload($tickets['avatar'], $bytes, '../../../me.png');
        expect($uploaded->status)->toBe(201, $uploaded->body());
        $reference = (string) json_decode($uploaded->body(), true)['upload'];

        // Not on someone else's account, however the request is put.
        $otherId = (string) $pdo->query("SELECT id FROM customers WHERE email = 'customer1@shop.test'")?->fetchColumn();
        expect($customer->action('account.updateAvatar', ['customer' => $otherId, 'avatar' => $reference])->status)->toBe(403)
            ->and($other->action('account.updateAvatar', ['customer' => $otherId, 'avatar' => $reference])->status)->toBe(422);

        $saved = $customer->action('account.updateAvatar', ['customer' => $demo, 'avatar' => $reference]);
        expect($saved->status)->toBe(200, $saved->body())
            ->and(json_decode($saved->body(), true)['revalidated'])->toBe(['shop.account']);

        $row = $pdo->query('SELECT avatar_key, avatar_type FROM customers WHERE id = ' . $pdo->quote($demo))?->fetch(\PDO::FETCH_ASSOC);
        expect($row['avatar_key'] ?? null)->toMatch('#^avatars/[a-f0-9]{32}\.png$#')
            ->and($row['avatar_type'] ?? null)->toBe('image/png');
        // Kept as drawn again from its pixels (ImageSanitizer, ADR 0103):
        // the same image, not the same bytes.
        $storage->assertStoredUnder('avatars', (string) $row['avatar_key'])->assertContents(
            (string) $row['avatar_key'],
            static fn (string $kept): bool => \array_slice((array) getimagesizefromstring($kept), 0, 3) === \array_slice((array) getimagesizefromstring($bytes), 0, 3),
        );

        // A receipt, kept with the account and listed on the page.
        $receipt = $customer->upload($tickets['receipt'], png(), 'till receipt.png');
        expect($customer->action('account.addReceipt', ['customer' => $demo, 'receipt' => json_decode($receipt->body(), true)['upload']])->status)->toBe(200);
        $storage->assertCount(2)->assertStoredUnder('receipts');

        $again = $customer->payload('/account');
        expect($again->body())->toContain('till receipt.png')
            ->and($again->body())->not->toContain('No receipts yet');

        // Shown by a URL for this session alone: the header's avatar and the
        // receipt's link.
        preg_match_all('#/_file/v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+#', $again->body(), $urls);
        expect(array_unique($urls[0]))->toHaveCount(2);
        $avatarUrl = $urls[0][0];

        $file = $customer->get($avatarUrl);
        expect($file->status)->toBe(200)
            ->and($file->header('content-type'))->toBe('image/png')
            ->and($file->header('x-content-type-options'))->toBe('nosniff')
            ->and($other->get($avatarUrl)->status)->toBe(404);

        // A storage key never reaches the page.
        expect($again->body())->not->toContain((string) $row['avatar_key']);

        // The receipt removed: by its customer alone, its row and then its
        // file, and its upload's record with it -- the bytes back in the
        // customer's quota.
        $receiptRow = $pdo->query('SELECT id, storage_key FROM receipts WHERE customer_id = ' . $pdo->quote($demo))?->fetch(\PDO::FETCH_ASSOC);
        $receiptKey = (string) ($receiptRow['storage_key'] ?? '');
        expect($other->action('account.removeReceipt', ['receipt' => $receiptRow['id']])->status)->toBe(403);
        $storage->assertExists($receiptKey);

        // A second submit, its receipt read before the first removed it,
        // reaches the body once the row is gone: it answers as the first
        // did, rather than with MissingRow's 500 (ADR 0208).
        $stale = $kernel->orm->find(Receipt::class, (string) $receiptRow['id']);
        expect($stale)->not->toBeNull();

        $removed = $customer->action('account.removeReceipt', ['receipt' => $receiptRow['id']]);
        expect($removed->status)->toBe(200, $removed->body())
            ->and(json_decode($removed->body(), true)['revalidated'])->toBe(['shop.account'])
            ->and($pdo->query('SELECT COUNT(*) FROM receipts')?->fetchColumn())->toBe(0)
            ->and($pdo->query('SELECT COUNT(*) FROM uploads WHERE storage_key = ' . $pdo->quote($receiptKey))?->fetchColumn())->toBe(0);
        $storage->assertMissing($receiptKey)->assertCount(1);

        $instance = $kernel->actions->instanceFor($kernel->actions->get('account.removeReceipt'));
        \assert($instance instanceof AccountActions && $stale instanceof Receipt);
        $again = $kernel->orm->transaction(static fn (): ActionResult => $instance->removeReceipt($stale));
        expect($again->revalidatedRoutes)->toBe(['shop.account']);
        $storage->assertCount(1);
        expect($customer->payload('/account')->body())->toContain('No receipts yet');
    } finally {
        $renderer->stop();
    }
});

// ADR 0143: the receipt field's upload, resumable, through the shop's own
// stack -- begun under the page's ticket, its first chunk in, the page
// loaded again, and only the rest sent before the receipt is saved.
it('resumes a receipt upload from the byte the shop holds, and saves it once whole', function () {
    $database = database();
    $renderer = Renderer::start('msgpack', 'shop-uploads-resumable');
    $storage = new StorageFake();
    $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database, storage: $storage);
    $customer = new Visitor($kernel);
    $other = new Visitor($kernel);
    $pdo = new \PDO("sqlite:{$database}");
    $demo = (string) $pdo->query('SELECT id FROM customers WHERE email = ' . $pdo->quote(DemoAccount::EMAIL))?->fetchColumn();

    // A PNG of noise, large enough for two chunks, and under the receipt's limit.
    $image = imagecreatetruecolor(400, 400);
    \assert($image !== false);
    for ($i = 0; $i < 160_000; $i++) {
        imagesetpixel($image, $i % 400, intdiv($i, 400), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    $chunk = UploadEndpoint::MIN_CHUNK_BYTES;
    expect(\strlen($bytes))->toBeGreaterThan($chunk);

    try {
        $customer->signIn(DemoAccount::EMAIL, DemoAccount::PASSWORD);
        $ticket = tickets($customer->payload('/account'))['receipt'];

        $begun = $customer->session(UploadEndpoint::SESSION_PATH, ['ticket' => $ticket, 'size' => \strlen($bytes), 'name' => 'long receipt.png']);
        expect($begun->status)->toBe(201, $begun->body());
        $session = (string) json_decode($begun->body(), true)['session'];
        $first = $customer->session(UploadEndpoint::SESSION_CHUNK_PATH, substr($bytes, 0, $chunk), [
            'upload-session' => $session,
            'upload-offset' => '0',
        ]);
        expect($first->status)->toBe(200, $first->body())
            ->and($storage->keys('receipts'))->toBe([]);

        // The page loaded again: the session is still this customer's, and
        // the shop says where it got to. Another customer's is refused.
        $customer->payload('/account');
        $offset = $customer->session(UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $session]);
        $other->signIn('customer1@shop.test', 'pellwick-1');
        expect(json_decode($offset->body(), true)['offset'])->toBe($chunk)
            ->and($other->session(UploadEndpoint::SESSION_OFFSET_PATH, ['session' => $session])->status)->toBe(403);

        $done = $customer->session(UploadEndpoint::SESSION_CHUNK_PATH, substr($bytes, $chunk), [
            'upload-session' => $session,
            'upload-offset' => (string) $chunk,
        ]);
        expect($done->status)->toBe(201, $done->body());
        $reference = (string) json_decode($done->body(), true)['upload'];

        $saved = $customer->action('account.addReceipt', ['customer' => $demo, 'receipt' => $reference]);
        expect($saved->status)->toBe(200, $saved->body())
            ->and($storage->keys('tinvar-partial'))->toBe([])
            ->and($storage->keys('receipts'))->toHaveCount(1)
            ->and($customer->payload('/account')->body())->toContain('long receipt.png');
    } finally {
        $renderer->stop();
    }
});
