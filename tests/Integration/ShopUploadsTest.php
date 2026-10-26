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

use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Support\Signer;
use Tinvar\Testing\Fakes\StorageFake;
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
            new Request('GET', $path, ['rsc' => '1', 'accept' => 'text/x-component', 'x-version' => 'dev'], [], [], $this->cookies, null, '127.0.0.1'),
        ));
    }

    public function get(string $path): Response
    {
        return $this->keep($this->kernel->handle(new Request('GET', $path, ['accept' => '*/*'], [], [], $this->cookies, null, '127.0.0.1')));
    }

    /** @param array<string, mixed> $body */
    public function action(string $name, array $body): Response
    {
        $token = $this->csrf();

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token, 'rsc' => '1'],
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
                ['content-type' => 'multipart/form-data; boundary=x', 'accept' => 'application/json', 'x-xsrf-token' => $token, 'rsc' => '1'],
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
        )->for(new Request('GET', '/', cookies: $this->cookies));
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
        $storage->assertStoredUnder('avatars', (string) $row['avatar_key'])->assertContents((string) $row['avatar_key'], $bytes);

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
    } finally {
        $renderer->stop();
    }
});
