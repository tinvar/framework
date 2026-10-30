<?php

declare(strict_types=1);

/**
 * S3Storage against a real MinIO, through PinnedHttp: what FakeS3 stands
 * for, held to a store that checks the signatures itself.
 *
 * Only TINVAR_TEST_S3_ENDPOINT names a server -- with TINVAR_TEST_S3_KEY
 * and TINVAR_TEST_S3_SECRET, and TINVAR_TEST_S3_BUCKET or `tinvar-test` --
 * and without one these are skipped. MinIO, pinned by digest (Chainguard
 * builds it from MinIO's source; MinIO publishes no image any more):
 *
 *     docker run -d --name tinvar-minio -p 127.0.0.1::9000 \
 *       -e MINIO_ROOT_USER=tinvar -e MINIO_ROOT_PASSWORD=tinvar-secret-0123 \
 *       cgr.dev/chainguard/minio@sha256:3b6e40c30ecd5cea627e382ff0b82ca841f58c52294578787043ee80c8ef7c82 \
 *       server /data
 *     TINVAR_TEST_S3_ENDPOINT=http://127.0.0.1:<port> TINVAR_TEST_S3_KEY=tinvar \
 *       TINVAR_TEST_S3_SECRET=tinvar-secret-0123 vendor/bin/pest packages/framework/tests/Storage
 */

use Tinvar\Attributes\OutboundPolicy;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\PinnedHttp;
use Tinvar\Storage\S3\S3Credentials;
use Tinvar\Storage\S3\S3Storage;
use Tinvar\Storage\S3\SigV4;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;

/** @return array{S3Storage, string, string} the store, the endpoint, the bucket */
function minio(): array
{
    $endpoint = getenv('TINVAR_TEST_S3_ENDPOINT');
    if (!\is_string($endpoint) || $endpoint === '') {
        test()->markTestSkipped('MinIO: set TINVAR_TEST_S3_ENDPOINT, TINVAR_TEST_S3_KEY and TINVAR_TEST_S3_SECRET.');
    }
    $key = (string) getenv('TINVAR_TEST_S3_KEY');
    $secret = (string) getenv('TINVAR_TEST_S3_SECRET');
    $bucket = getenv('TINVAR_TEST_S3_BUCKET') ?: 'tinvar-test';
    $host = (string) parse_url($endpoint, PHP_URL_HOST);

    // The store on this machine: its address opened by the policy, as an
    // application opens its own store's.
    $http = new PinnedHttp(new OutboundPolicy(allow: [$host === 'localhost' ? '127.0.0.1' : $host]));
    $credentials = new S3Credentials($key, $secret);

    // The bucket, made once: S3Storage itself makes none.
    $port = (int) parse_url($endpoint, PHP_URL_PORT);
    $signed = new SigV4($credentials, 'us-east-1')->sign('PUT', "/{$bucket}", [], ['host' => "{$host}:{$port}"], hash('sha256', ''), time());
    $made = $http->send(new OutboundRequest('PUT', "{$endpoint}/{$bucket}", $signed));
    if (!$made->successful() && !str_contains($made->body, 'BucketAlreadyOwnedByYou')) {
        throw new RuntimeException("The test bucket could not be made: {$made->status}.");
    }

    return [new S3Storage($endpoint, $bucket, 'us-east-1', $credentials, $http, root: 'tests', partBytes: S3Storage::MIN_PART_BYTES), $endpoint, $bucket];
}

/**
 * A browser's request to a presigned URL, by curl itself: nothing of
 * S3Storage's in the way.
 *
 * @return array{int, array<string, string>, string}
 */
/** @param array<string, string> $send */
function minioBrowser(string $method, string $url, ?string $body = null, array $send = []): array
{
    $handle = curl_init($url);
    $headers = [];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Expect:', 'Content-Type:', ...array_map(static fn (string $n, string $v): string => "{$n}: {$v}", array_keys($send), $send)],
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }

            return \strlen($line);
        },
    ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]));
    $response = (string) curl_exec($handle);

    return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $response];
}

/** @return resource */
function minioStream(string $contents)
{
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

describe('S3Storage against MinIO', function () {
    it('puts, reads, sizes and deletes a file, signed as MinIO checks it', function () {
        [$storage] = minio();
        $key = StorageKey::generate('receipts', 'pdf');

        $storage->put($key, minioStream('%PDF-1.4 a receipt'));

        $chunks = '';
        expect($storage->size($key))->toBe(18)
            ->and(stream_get_contents($storage->read($key)))->toBe('%PDF-1.4 a receipt')
            ->and($storage->readTo($key, function (string $chunk) use (&$chunks): void {
                $chunks .= $chunk;
            }))->toBeTrue()
            ->and($chunks)->toBe('%PDF-1.4 a receipt');

        $storage->delete($key);
        expect($storage->exists($key))->toBeFalse()
            ->and($storage->read($key))->toBeNull();
    });

    it('writes a large file as a multipart upload completed on commit, and keeps nothing of one aborted', function () {
        [$storage] = minio();
        $key = StorageKey::generate('images', 'iso');
        $bytes = random_bytes(S3Storage::MIN_PART_BYTES * 2 + 4096);

        $writer = $storage->writer($key);
        foreach (str_split($bytes, 1_048_576) as $chunk) {
            $writer->write($chunk);
        }
        expect($storage->exists($key))->toBeFalse();
        $writer->commit();
        expect($storage->size($key))->toBe(\strlen($bytes))
            ->and(hash('sha256', (string) stream_get_contents($storage->read($key))))->toBe(hash('sha256', $bytes));
        $storage->delete($key);

        $given = $storage->writer($key);
        $given->write(random_bytes(S3Storage::MIN_PART_BYTES + 1));
        $given->abort();
        expect($storage->exists($key))->toBeFalse();
    });

    it('refuses a wrong secret with the store\'s own code', function () {
        [, $endpoint, $bucket] = minio();
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $wrong = new S3Storage($endpoint, $bucket, 'us-east-1', new S3Credentials((string) getenv('TINVAR_TEST_S3_KEY'), 'not-the-secret'), new PinnedHttp(new OutboundPolicy(allow: [$host])));

        expect(fn () => $wrong->put(StorageKey::generate('a', 'pdf'), minioStream('x')))
            ->toThrow(StorageException::class, 'SignatureDoesNotMatch');
    });

    it('serves a presigned read with the headers it was signed with, and refuses it once expired', function () {
        [$storage] = minio();
        $key = StorageKey::generate('receipts', 'pdf');
        $storage->put($key, minioStream('%PDF-1.4 a receipt'));

        $url = $storage->presignedGet($key, 2, [
            'content-type' => 'application/pdf',
            'content-disposition' => "attachment; filename=\"receipt.pdf\"; filename*=UTF-8''receipt.pdf",
            'cache-control' => 'private, no-store',
        ]);
        [$status, $headers, $body] = minioBrowser('GET', $url);

        expect($status)->toBe(200)
            ->and($body)->toBe('%PDF-1.4 a receipt')
            ->and($headers['content-type'] ?? null)->toBe('application/pdf')
            ->and($headers['content-disposition'] ?? null)->toBe("attachment; filename=\"receipt.pdf\"; filename*=UTF-8''receipt.pdf")
            ->and($headers['cache-control'] ?? null)->toBe('private, no-store')
            ->and(minioBrowser('GET', str_replace('attachment', 'inline', $url))[0])->toBe(403);

        sleep(3);
        expect(minioBrowser('GET', $url)[0])->toBe(403);
        $storage->delete($key);
    });

    it('takes a presigned upload of exactly the size signed, and no other', function () {
        [$storage] = minio();
        $key = StorageKey::generate('incoming', '');

        $put = $storage->presignedPut($key, 11, 60);
        expect(minioBrowser('PUT', $put->url, 'not eleven bytes at all', $put->headers)[0])->toBe(403)
            ->and(minioBrowser('PUT', $put->url, '<b>html</b>', ['content-type' => 'text/html', 'content-disposition' => 'attachment'])[0])->toBe(403)
            ->and($storage->exists($key))->toBeFalse()
            ->and(minioBrowser('PUT', $put->url, 'eleven byte', $put->headers)[0])->toBe(200)
            ->and(stream_get_contents($storage->read($key)))->toBe('eleven byte');

        // Read back with no headers named, it is an octet stream to save.
        [, $headers] = minioBrowser('GET', $storage->presignedGet($key, 60));
        expect($headers['content-type'] ?? null)->toBe('application/octet-stream')
            ->and($headers['content-disposition'] ?? null)->toBe('attachment');

        $storage->delete($key);
    });
});
