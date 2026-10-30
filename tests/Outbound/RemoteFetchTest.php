<?php

declare(strict_types=1);

/**
 * The spec's other outbound callers on OutboundHttp (ADR 0096): a body
 * streamed to a sink, held to its cap and never to memory; downloads held
 * to the digest they were asked for; and image sources held to what an
 * upload is.
 */

namespace Tinvar\Tests\Outbound\RemoteFetch;

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\PinnedHttp;
use Tinvar\Outbound\RemoteContentRefused;
use Tinvar\Outbound\RemoteFiles;
use Tinvar\Outbound\RemoteImages;
use Tinvar\Outbound\StaticResolver;
use Tinvar\Storage\LocalStorage;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Storage\Storage;
use Tinvar\Storage\StorageWriter;
use Tinvar\Tests\Outbound\Fixtures\Server;

require_once __DIR__ . '/Fixtures/server.php';

function needsFork(): void
{
    if (!\function_exists('pcntl_fork') || !\function_exists('curl_init')) {
        test()->markTestSkipped('needs pcntl and curl');
    }
}

function png(int $width = 2, int $height = 3): string
{
    $image = imagecreatetruecolor($width, $height);
    \assert($image !== false);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A PNG whose header claims $width x $height, and no pixels behind it. */
function claimingPng(int $width, int $height): string
{
    $ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
}

/** A store that remembers what it was asked to keep. */
final class Kept implements Storage
{
    /** @var list<string> */
    public array $put = [];

    private MemoryStorage $memory;

    public function __construct()
    {
        $this->memory = new MemoryStorage();
    }

    public function put(string $key, mixed $stream): void
    {
        $this->put[] = $key;
        $this->memory->put($key, $stream);
    }

    public function read(string $key): mixed
    {
        return $this->memory->read($key);
    }

    public function exists(string $key): bool
    {
        return $this->memory->exists($key);
    }

    public function size(string $key): ?int
    {
        return $this->memory->size($key);
    }

    public function delete(string $key): void
    {
        $this->memory->delete($key);
    }

    /** @var list<int> the size of each chunk written, as it was written */
    public array $chunks = [];

    public function writer(string $key): StorageWriter
    {
        $inner = $this->memory->writer($key);
        $chunks = &$this->chunks;
        $put = &$this->put;

        return new class ($inner, $key, $chunks, $put) implements StorageWriter {
            /**
             * @param list<int> $chunks
             * @param list<string> $put
             */
            public function __construct(private StorageWriter $inner, private string $key, private array &$chunks, private array &$put) {}

            public function write(string $bytes): void
            {
                $this->chunks[] = \strlen($bytes);
                $this->inner->write($bytes);
            }

            public function commit(): void
            {
                $this->inner->commit();
                $this->put[] = $this->key;
            }

            public function abort(): void
            {
                $this->inner->abort();
            }
        };
    }
}

function stored(Storage $storage, string $key): string
{
    $stream = $storage->read($key);
    \assert(\is_resource($stream));

    return (string) stream_get_contents($stream);
}

describe('a sink', function () {
    it('takes a 2xx body chunk by chunk, and the response comes back without it', function () {
        needsFork();
        $body = str_repeat('tinvar-', 50_000);
        $server = Server::start([Server::reply(200, $body)]);
        $received = '';

        try {
            $response = new PinnedHttp(new OutboundPolicy(allow: ['127.0.0.1']), new StaticResolver(['mirror.invalid' => ['127.0.0.1']]))->send(new OutboundRequest(
                'GET',
                "http://mirror.invalid:{$server->port}/image.iso",
                maxResponseBytes: 1_000_000,
                sink: static function (string $chunk) use (&$received): void {
                    $received .= $chunk;
                },
            ));
        } finally {
            $server->stop();
        }

        expect($response->status)->toBe(200)
            ->and($response->body)->toBe('')
            ->and($received)->toBe($body);
    });

    it('is held to the cap, and a redirect\'s body never reaches it', function () {
        needsFork();
        $server = Server::start([
            Server::reply(302, 'moved', ['Location' => '/next']),
            Server::reply(200, str_repeat('x', 5000)),
        ]);
        $received = '';

        try {
            expect(fn () => new PinnedHttp(new OutboundPolicy(allow: ['127.0.0.1']), new StaticResolver(['mirror.invalid' => ['127.0.0.1']]))->send(new OutboundRequest(
                'GET',
                "http://mirror.invalid:{$server->port}/",
                maxResponseBytes: 4096,
                maxRedirects: 1,
                sink: static function (string $chunk) use (&$received): void {
                    $received .= $chunk;
                },
            )))->toThrow(fn (OutboundFailed $e) => expect($e->reason)->toBe(OutboundFailed::TOO_LARGE));
        } finally {
            $server->stop();
        }

        expect($received)->not->toContain('moved');
    });

    it('stops the transfer when it throws, and the error is the sink\'s', function () {
        needsFork();
        $server = Server::start([Server::reply(200, str_repeat('x', 100_000))]);

        try {
            expect(fn () => new PinnedHttp(new OutboundPolicy(allow: ['127.0.0.1']), new StaticResolver(['mirror.invalid' => ['127.0.0.1']]))->send(new OutboundRequest(
                'GET',
                "http://mirror.invalid:{$server->port}/",
                maxResponseBytes: 1_000_000,
                sink: static function (): void {
                    throw new \RuntimeException('disk full');
                },
            )))->toThrow(\RuntimeException::class, 'disk full');
        } finally {
            $server->stop();
        }
    });

    it('may be given an hour; a request holding its body, two minutes', function () {
        $sink = static function (): void {};

        expect(new OutboundRequest('GET', 'https://mirror.example/', timeout: 3600.0, sink: $sink)->timeout)->toBe(3600.0)
            ->and(fn () => new OutboundRequest('GET', 'https://mirror.example/', timeout: 121.0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new OutboundRequest('GET', 'https://mirror.example/', timeout: 3601.0, sink: $sink))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new OutboundRequest('GET', 'https://mirror.example/', readBody: false, sink: $sink))->toThrow(\InvalidArgumentException::class);
    });
});

describe('downloads', function () {
    it('stores a file only when its digest is the one asked for, under a key nothing remote chose', function () {
        $iso = random_bytes(40_000);
        $storage = new MemoryStorage();
        $http = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, [], $iso, 12));
        $file = new RemoteFiles($http, $storage)->download('https://mirror.example/releases/../../etc/passwd.iso', strtoupper(hash('sha256', $iso)), 'images', 'iso');

        expect($file->key)->toMatch('#^images/[0-9a-f]{32}\.iso\z#')
            ->and($file->bytes)->toBe(40_000)
            ->and($file->sha256)->toBe(hash('sha256', $iso))
            ->and(stored($storage, $file->key))->toBe($iso)
            ->and($http->sent[0]->maxRedirects)->toBe(OutboundRequest::MAX_REDIRECTS)
            ->and($http->sent[0]->maxResponseBytes)->toBe(RemoteFiles::MAX_BYTES);
    });

    it('stores nothing when the digest does not match, or the answer is not a 2xx', function () {
        $storage = new Kept();

        expect(fn () => new RemoteFiles(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, [], 'tampered')), $storage)
            ->download('https://mirror.example/a.iso', hash('sha256', 'original'), 'images', 'iso'))
            ->toThrow(fn (RemoteContentRefused $e) => expect($e->reason)->toBe(RemoteContentRefused::CHECKSUM))
            ->and(fn () => new RemoteFiles(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(404, [], 'nope')), $storage)
                ->download('https://mirror.example/a.iso', hash('sha256', 'nope'), 'images', 'iso'))
            ->toThrow(fn (RemoteContentRefused $e) => expect($e->status)->toBe(404))
            ->and($storage->put)->toBe([]);
    });

    it('writes a download into the store as it arrives, never gathered whole first', function () {
        $iso = random_bytes(40_000);
        $storage = new Kept();
        $file = new RemoteFiles(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, [], $iso)), $storage)
            ->download('https://mirror.example/a.iso', hash('sha256', $iso), 'images', 'iso');

        // The fake hands the body over 8 KiB at a time, as curl does: each
        // chunk reached the store as it came.
        expect($storage->chunks)->toBe([8192, 8192, 8192, 8192, 7232])
            ->and($storage->put)->toBe([$file->key])
            ->and(stored($storage, $file->key))->toBe($iso);
    });

    it('leaves nothing on disk -- beside the destination or anywhere -- for a download that fails its digest', function () {
        $root = sys_get_temp_dir() . '/tinvar-remote-' . bin2hex(random_bytes(4));
        $storage = new LocalStorage($root);

        try {
            expect(fn () => new RemoteFiles(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, [], random_bytes(20_000))), $storage)
                ->download('https://mirror.example/a.iso', hash('sha256', 'something else'), 'images', 'iso'))
                ->toThrow(RemoteContentRefused::class);

            $left = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $found) {
                $left[] = (string) $found;
            }
            expect($left)->toBe([]);

            $iso = random_bytes(20_000);
            $kept = new RemoteFiles(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, [], $iso)), $storage)
                ->download('https://mirror.example/a.iso', hash('sha256', $iso), 'images', 'iso');
            expect(stored($storage, $kept->key))->toBe($iso)
                ->and(fileperms("{$root}/{$kept->key}") & 0o777)->toBe(0o600);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    });

    it('refuses plain http, a digest that is not one, and a key it could not make safely', function () {
        $files = new RemoteFiles(new FakeOutboundHttp(), new MemoryStorage());
        $digest = hash('sha256', '');

        expect(fn () => $files->download('http://mirror.example/a.iso', $digest, 'images', 'iso'))->toThrow(fn (OutboundRefused $e) => expect($e->reason)->toBe('scheme'))
            ->and(fn () => $files->download('https://mirror.example/a.iso', 'md5:abc', 'images', 'iso'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $files->download('https://mirror.example/a.iso', $digest, '../images', 'iso'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $files->download('https://mirror.example/a.iso', $digest, 'images', 'iso/../x'))->toThrow(\InvalidArgumentException::class);
    });

    it('refuses a destination on the private network before a byte is sent', function () {
        $http = new FakeOutboundHttp(null, new OutboundPolicy(), new StaticResolver(['mirror.example' => ['10.0.0.7']]));

        expect(fn () => new RemoteFiles($http, new MemoryStorage())->download('https://mirror.example/a.iso', hash('sha256', ''), 'images', 'iso'))
            ->toThrow(OutboundRefused::class)
            ->and($http->sent)->toBe([]);
    });
});

describe('image sources', function () {
    it('fetches an image by its bytes, whatever the server said it was, and stores it under the sniffed type', function () {
        $storage = new MemoryStorage();
        $http = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, ['content-type' => ['text/html']], png(4, 5)));
        $image = new RemoteImages($http, $storage)->store('https://cdn.example/logo', 'logos');

        expect($image->type)->toBe('image/png')
            ->and([$image->width, $image->height])->toBe([4, 5])
            ->and($image->key)->toMatch('#^logos/[0-9a-f]{32}\.png\z#')
            ->and(stored($storage, (string) $image->key))->toBe(png(4, 5))
            ->and($http->sent[0]->maxRedirects)->toBe(RemoteImages::MAX_REDIRECTS)
            ->and(print_r($image, true))->not->toContain("\x89PNG");
    });

    it('refuses what is not a PNG, JPEG, GIF or WebP -- an SVG above all', function (string $body) {
        $images = new RemoteImages(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, ['content-type' => ['image/svg+xml']], $body)));

        expect(fn () => $images->fetch('https://cdn.example/logo.svg'))
            ->toThrow(fn (RemoteContentRefused $e) => expect($e->reason)->toBe(RemoteContentRefused::TYPE));
    })->with([
        'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
        'html' => ['<html><script>alert(1)</script></html>'],
        'pdf' => ['%PDF-1.7 ...'],
        'a png signature and nothing after' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR"],
    ]);

    it('refuses an image claiming more pixels than are decoded', function () {
        $images = new RemoteImages(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(200, [], claimingPng(19_999, 19_999))));

        expect(fn () => $images->fetch('https://cdn.example/bomb.png'))
            ->toThrow(fn (RemoteContentRefused $e) => expect($e->reason)->toBe(RemoteContentRefused::DIMENSIONS));
    });

    it('refuses plain http, a refused destination, and an answer that is not a 2xx', function () {
        $http = new FakeOutboundHttp(null, new OutboundPolicy(), new StaticResolver(['metadata.example' => ['169.254.169.254']]));

        expect(fn () => new RemoteImages(new FakeOutboundHttp())->fetch('http://cdn.example/a.png'))->toThrow(OutboundRefused::class)
            ->and(fn () => new RemoteImages($http)->fetch('https://metadata.example/latest/meta-data/'))->toThrow(OutboundRefused::class)
            ->and(fn () => new RemoteImages(new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(403)))->fetch('https://cdn.example/a.png'))
            ->toThrow(fn (RemoteContentRefused $e) => expect($e->status)->toBe(403))
            ->and($http->sent)->toBe([]);
    });
});
