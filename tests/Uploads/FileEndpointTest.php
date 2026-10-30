<?php

declare(strict_types=1);

/**
 * /_file: a stored file, sent to the session a page minted its URL for, as
 * the type it was sniffed as and nothing a browser could run.
 */

use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Uploads\FileToken;
use Tinvar\Uploads\FileUrls;

use function Tinvar\Tests\Uploads\Fixtures\pdf;
use function Tinvar\Tests\Uploads\Fixtures\png;

require_once __DIR__ . '/Fixtures/app.php';

/** @return array{App, MemoryStorage, string} an app holding one PNG, and its key */
function appWithStoredPng(): array
{
    $app = new App($storage = new MemoryStorage());
    $key = 'avatars/' . str_repeat('ab', 16) . '.png';
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, png());
    rewind($stream);
    $storage->put($key, $stream);

    return [$app, $storage, $key];
}

it('sends the file to the session and user its URL was minted for, as nothing more than its type', function () {
    [$app, $storage, $key] = appWithStoredPng();
    $url = $app->fileUrl($key, 'image/png');

    expect($url)->toStartWith(FileUrls::PREFIX);

    $response = $app->fetch($url);
    expect($response->status)->toBe(200)
        ->and($response->body())->toBe($storage->contents($key))
        ->and($response->header('content-type'))->toBe('image/png')
        ->and($response->header('content-length'))->toBe((string) strlen((string) $storage->contents($key)))
        ->and($response->header('x-content-type-options'))->toBe('nosniff')
        ->and($response->header('content-security-policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->header('content-disposition'))->toStartWith('inline;')
        ->and($response->header('cache-control'))->toBe('private, no-store');
});

// ADR 0053: a file on disk is one the server can send itself, with no worker
// writing it to a client that may stop reading. The response still streams
// the same bytes for anything that cannot.
it('says which file on disk the body is, when the store keeps it on disk', function () {
    $root = sys_get_temp_dir() . '/tinvar-file-endpoint-' . bin2hex(random_bytes(4));
    $app = new App($storage = new Tinvar\Storage\LocalStorage($root));
    $key = 'avatars/' . str_repeat('ab', 16) . '.png';
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, png());
    rewind($stream);
    $storage->put($key, $stream);

    try {
        $response = $app->fetch($app->fileUrl($key, 'image/png'));

        expect($response->status)->toBe(200)
            ->and($response->file())->toBe(realpath("{$root}/{$key}"))
            ->and($response->body())->toBe(png())
            ->and($response->header('x-content-type-options'))->toBe('nosniff');

        // In memory there is no file to name.
        [$memory, , $memoryKey] = appWithStoredPng();
        expect($memory->fetch($memory->fileUrl($memoryKey, 'image/png'))->file())->toBeNull();
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
});

it('offers anything but an image as a download, under a name that cannot break the header', function () {
    $app = new App($storage = new MemoryStorage());
    $key = 'documents/' . str_repeat('cd', 16) . '.pdf';
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, pdf());
    rewind($stream);
    $storage->put($key, $stream);

    $app->auth->login(new Tinvar\Tests\Uploads\Fixtures\User('u-1'), 'e1');
    $url = (string) $app->kernel->fileUrls?->url(
        new Tinvar\Http\Request('GET', '/', cookies: [Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE => 'session-a']),
        $key,
        'application/pdf',
        "receipt\";\r\nset-cookie: x=1.pdf",
    );
    $app->auth->login(null);

    $disposition = (string) $app->fetch($url)->header('content-disposition');
    expect($disposition)->toStartWith('attachment; filename="receipt__set-cookie__x_1.pdf"')
        ->and($disposition)->not->toContain("\n")
        ->and($disposition)->not->toContain("\r");
});

it('is a 404 to another session, another user, the same user at another epoch, and after it expires', function () {
    [$app, , $key] = appWithStoredPng();
    $url = $app->fileUrl($key, 'image/png');

    expect($app->fetch($url, session: 'session-b')->status)->toBe(404)
        ->and($app->fetch($url, user: 'u-2')->status)->toBe(404)
        ->and($app->fetch($url, epoch: 'e2')->status)->toBe(404);

    $app->now += FileToken::DEFAULT_TTL + 600;
    expect($app->fetch($url)->status)->toBe(404);
});

it('is a 404 for a tampered token, a token of another kind, and a file that is gone', function () {
    [$app, $storage, $key] = appWithStoredPng();
    $url = $app->fileUrl($key, 'image/png');
    $token = substr($url, strlen(FileUrls::PREFIX));
    [$version, $payload, $mac] = explode('.', $token);

    // Pointed at another key, under the old MAC.
    $claims = json_decode((string) Tinvar\Support\Signer::decode($payload), true);
    $claims['k'] = 'avatars/' . str_repeat('ef', 16) . '.png';
    $forged = FileUrls::PREFIX . $version . '.' . Tinvar\Support\Signer::encode(json_encode($claims, JSON_THROW_ON_ERROR)) . '.' . $mac;

    // An upload ticket is signed with another purpose and never reads as a file token.
    $ticket = FileUrls::PREFIX . $app->ticket($app->profile(Tinvar\Tests\Uploads\Fixtures\P1));

    expect($app->fetch($forged)->status)->toBe(404)
        ->and($app->fetch($ticket)->status)->toBe(404)
        ->and($app->fetch(FileUrls::PREFIX . 'nope')->status)->toBe(404);

    $storage->delete($key);
    expect($app->fetch($url)->status)->toBe(404);
});

it('mints the same URL for the same file and viewer within a window, so the browser caches it', function () {
    [$app, , $key] = appWithStoredPng();
    $app->now = 1_800_000_000;
    $first = $app->fileUrl($key, 'image/png');
    $app->now += 30;

    expect($app->fileUrl($key, 'image/png'))->toBe($first)
        ->and($app->fileUrl($key, 'image/png', session: 'session-b'))->not->toBe($first);
});

it('refuses to mint a URL for a type it would not serve, or a key the store does not make', function () {
    [$app, , $key] = appWithStoredPng();

    expect(fn () => $app->fileUrl($key, 'text/html'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $app->fileUrl('../../etc/passwd', 'image/png'))->toThrow(Tinvar\Storage\StorageException::class);
});

it('answers HEAD without the body, and refuses a POST', function () {
    [$app, , $key] = appWithStoredPng();
    $url = $app->fileUrl($key, 'image/png');

    $head = $app->kernel->handle(
        new Tinvar\Http\Request('HEAD', $url, ['x-test-user' => 'u-1', 'x-test-epoch' => 'e1'], cookies: [Tinvar\Tests\Uploads\Fixtures\SESSION_COOKIE => 'session-a']),
        static fn () => Tinvar\Http\Response::make(500),
    );
    expect($head->status)->toBe(200)
        ->and($head->body())->toBe('');

    $post = $app->kernel->handle(new Tinvar\Http\Request('POST', $url), static fn () => Tinvar\Http\Response::make(500));
    expect($post->status)->toBe(405);
});

describe('the tenant and the panel', function () {
    it('serves a URL only in the tenant the page that minted it ran in', function () {
        [$app, , $key] = appWithStoredPng();
        $url = $app->fileUrl($key, 'image/png', tenant: 'acme');

        expect($app->fetch($url, tenant: 'acme')->status)->toBe(200)
            ->and($app->fetch($url, tenant: 'globex')->status)->toBe(404)
            ->and($app->fetch($url)->status)->toBe(404)
            // And one minted in no tenant is not served in one.
            ->and($app->fetch($app->fileUrl($key, 'image/png'), tenant: 'acme')->status)->toBe(404);
    });

    it('puts the request in the panel the URL names, and serves it there alone', function () {
        [$app, , $key] = appWithStoredPng();
        $staff = $app->fileUrl($key, 'image/png', panel: 'staff');
        $none = $app->fileUrl($key, 'image/png');

        expect($app->fetch($staff)->status)->toBe(200)
            ->and($app->fetch($none)->status)->toBe(200)
            ->and($staff)->not->toBe($none);
    });

    it('refuses a token naming a panel or source the application does not have, or minted before either was bound', function () {
        [$app, , $key] = appWithStoredPng();
        $now = $app->now;
        $sign = static fn (array $claims): string => FileUrls::PREFIX . Tinvar\Render\SignedToken::encode($app->uploads->fileSigner, FileToken::PURPOSE, $claims);
        $base = [
            'k' => $key,
            't' => 'image/png',
            'd' => 'a.png',
            's' => $app->uploads->sessionHash('session-a'),
            'u' => $app->uploads->authHash('u-1', 'e1'),
            'e' => $now + 600,
            'i' => $now,
        ];

        expect($app->fetch($sign([...$base, 'y' => '', 'n' => '', 'r' => '']))->status)->toBe(200)
            ->and($app->fetch($sign($base))->status)->toBe(404)
            ->and($app->fetch($sign([...$base, 'y' => '', 'n' => 'ghost', 'r' => '']))->status)->toBe(404)
            ->and($app->fetch($sign([...$base, 'y' => '', 'n' => '', 'r' => 'No\\Such\\Source']))->status)->toBe(404)
            ->and($app->fetch($sign([...$base, 'y' => '', 'n' => '', 'r' => '../x']))->status)->toBe(404);
    });
});

/** @return array{App, Tinvar\Tests\Storage\Fixtures\FakeS3, string} */
function appWithS3Pdf(Tinvar\Uploads\FileDelivery $delivery): array
{
    require_once __DIR__ . '/../Storage/Fixtures/s3.php';
    $s3 = new Tinvar\Tests\Storage\Fixtures\FakeS3();
    $app = new App($s3->storage(), delivery: $delivery);
    $s3->now = $app->now;
    $key = 'receipts/' . str_repeat('cd', 16) . '.pdf';
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, pdf());
    rewind($stream);
    $app->storage->put($key, $stream);

    return [$app, $s3, $key];
}

describe('a file in an object store', function () {
    it('is streamed from the store under every header /_file sets', function () {
        [$app, , $key] = appWithS3Pdf(Tinvar\Uploads\FileDelivery::Stream);
        $response = $app->fetch($app->fileUrl($key, 'application/pdf'));

        expect($response->status)->toBe(200)
            ->and($response->body())->toBe(pdf())
            ->and($response->header('content-type'))->toBe('application/pdf')
            ->and($response->header('x-content-type-options'))->toBe('nosniff')
            ->and($response->header('content-security-policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->header('content-disposition'))->toStartWith('attachment;');
    });

    it('is sent to the store by a minute\'s presigned URL naming its type and disposition, when asked', function () {
        [$app, $s3, $key] = appWithS3Pdf(Tinvar\Uploads\FileDelivery::Redirect);
        $response = $app->fetch($app->fileUrl($key, 'application/pdf'));
        $location = (string) $response->header('location');

        expect($response->status)->toBe(302)
            ->and($location)->toStartWith('https://s3.store.example/uploads/' . $key . '?')
            ->and($location)->toContain('X-Amz-Expires=60')
            ->and($response->header('cache-control'))->toBe('private, no-store')
            ->and($response->header('referrer-policy'))->toBe('no-referrer');

        $fetched = $s3->browser('GET', $location);
        expect($fetched->status)->toBe(200)
            ->and($fetched->header('content-type'))->toBe('application/pdf')
            ->and($fetched->header('content-disposition'))->toStartWith('attachment;')
            ->and($fetched->header('cache-control'))->toBe('private, no-store');

        // Still the session's alone: another is redirected nowhere.
        expect($app->fetch($app->fileUrl($key, 'application/pdf'), session: 'session-b')->status)->toBe(404);
        $app->storage->delete($key);
        expect($app->fetch($app->fileUrl($key, 'application/pdf'))->status)->toBe(404);
    });
});

describe('a public file', function () {
    /** @return array{App, string} an app serving `products` publicly, holding a PNG there */
    function appWithPublicPng(): array
    {
        $app = new App($storage = new MemoryStorage(), publicPrefixes: ['products']);
        $key = 'products/' . str_repeat('ab', 16) . '.png';
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, png());
        rewind($stream);
        $storage->put($key, $stream);

        return [$app, $key];
    }

    it('is served to anyone, signed in or not, under every header that keeps it an image', function () {
        [$app, $key] = appWithPublicPng();
        $url = (string) $app->kernel->fileUrls?->publicUrl($key, 'image/png', 'Lamp.png');

        expect($url)->toStartWith(FileUrls::PUBLIC_PREFIX);

        $response = $app->kernel->handle(new Tinvar\Http\Request('GET', $url), static fn () => Tinvar\Http\Response::make(500));
        expect($response->status)->toBe(200)
            ->and($response->body())->toBe(png())
            ->and($response->header('x-content-type-options'))->toBe('nosniff')
            ->and($response->header('content-security-policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->header('content-disposition'))->toStartWith('inline; filename="Lamp.png"')
            ->and($response->header('cache-control'))->toBe('public, max-age=3600')
            ->and($response->header('cross-origin-resource-policy'))->toBe('cross-origin')
            ->and($response->header('vary'))->toBeNull()
            ->and($app->fetch($url, user: 'u-2', session: 'other')->status)->toBe(200);
    });

    it('is minted only under a prefix the application named public', function () {
        [$app] = appWithPublicPng();

        expect(fn () => $app->kernel->fileUrls?->publicUrl('avatars/' . str_repeat('ab', 16) . '.png', 'image/png'))->toThrow(LogicException::class)
            ->and(fn () => $app->kernel->fileUrls?->publicUrl('products-private/' . str_repeat('ab', 16) . '.png', 'image/png'))->toThrow(LogicException::class)
            ->and(fn () => $app->kernel->fileUrls?->publicUrl('products/' . str_repeat('ab', 16) . '.png', 'image/png', ttl: 60 * 86400))->toThrow(InvalidArgumentException::class);
    });

    it('stops being served once its prefix is no longer public, once it runs out, or once it is tampered with', function () {
        [$app, $key] = appWithPublicPng();
        $url = (string) $app->kernel->fileUrls?->publicUrl($key, 'image/png', ttl: 3600);

        // The same key and token, under an application that no longer opts in.
        $closed = new App($app->storage);
        $closed->now = $app->now;
        expect($closed->fetch($url)->status)->toBe(404);

        // A session-bound file token is no public one, nor the other way round.
        $private = $app->fileUrl($key, 'image/png');
        $swapped = FileUrls::PUBLIC_PREFIX . substr($private, strlen(FileUrls::PREFIX));
        $asPrivate = FileUrls::PREFIX . substr($url, strlen(FileUrls::PUBLIC_PREFIX));
        expect($app->fetch($swapped)->status)->toBe(404)
            ->and($app->fetch($asPrivate)->status)->toBe(404);

        $app->now += 2 * 86400;
        expect($app->fetch($url)->status)->toBe(404);
    });
});
