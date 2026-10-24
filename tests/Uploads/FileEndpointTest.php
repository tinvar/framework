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
