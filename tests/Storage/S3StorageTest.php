<?php

declare(strict_types=1);

/**
 * The object store: SigV4 held to AWS's own published examples, then
 * S3Storage against an S3 in memory that checks every signature (FakeS3).
 * S3MinioTest runs the same store against a real MinIO.
 */

use Tinvar\Outbound\OutboundRequest;
use Tinvar\Storage\S3\S3Credentials;
use Tinvar\Storage\S3\S3Storage;
use Tinvar\Storage\S3\SigV4;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;
use Tinvar\Tests\Storage\Fixtures\FakeS3;

use const Tinvar\Tests\Storage\Fixtures\S3_SECRET;

require_once __DIR__ . '/Fixtures/s3.php';

/** @return resource */
function s3StreamOf(string $contents)
{
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

describe('SigV4', function () {
    // docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
    // and sigv4-query-string-auth.html: the example bucket, key and date.
    $credentials = new S3Credentials('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
    $at = gmmktime(0, 0, 0, 5, 24, 2013);

    it('signs a request in its header as AWS\'s example does', function () use ($credentials, $at) {
        $headers = new SigV4($credentials, 'us-east-1')->sign(
            'GET',
            '/test.txt',
            [],
            ['host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9'],
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $at,
        );

        expect($headers['authorization'])->toBe(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
        )->and($headers['x-amz-date'])->toBe('20130524T000000Z');
    });

    it('presigns a URL as AWS\'s example does', function () use ($credentials, $at) {
        $query = new SigV4($credentials, 'us-east-1')->presign('GET', '/test.txt', [], ['host' => 'examplebucket.s3.amazonaws.com'], 86400, $at);

        expect($query)->toContain('X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404')
            ->and($query)->toContain('X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request');
    });

    it('never presigns past a week, nor leaves the secret in a dump', function () use ($credentials, $at) {
        $signer = new SigV4($credentials, 'us-east-1');

        expect(fn () => $signer->presign('GET', '/a', [], ['host' => 'h'], SigV4::MAX_EXPIRES + 1, $at))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $signer->sign('GET', '/a', [], [], '', $at))->toThrow(InvalidArgumentException::class)
            ->and(print_r($credentials, true))->not->toContain('wJalrXUtnFEMI')
            ->and(print_r($signer, true))->not->toContain('wJalrXUtnFEMI');
    });
});

describe('S3Storage', function () {
    it('puts, reads, sizes and deletes by key, each request signed and held to the endpoint', function () {
        $s3 = new FakeS3();
        $storage = $s3->storage(root: 'app');
        $key = StorageKey::generate('receipts', 'pdf');

        expect($storage->exists($key))->toBeFalse()
            ->and($storage->read($key))->toBeNull()
            ->and($storage->size($key))->toBeNull();

        $storage->put($key, s3StreamOf('first'));
        $storage->put($key, s3StreamOf('second, longer'));

        expect(stream_get_contents($storage->read($key)))->toBe('second, longer')
            ->and($storage->size($key))->toBe(14)
            ->and(array_keys($s3->objects))->toBe(["app/{$key}"]);

        $chunks = [];
        expect($storage->readTo($key, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }))->toBeTrue()
            ->and(implode('', $chunks))->toBe('second, longer');

        $storage->delete($key);
        $storage->delete($key);
        expect($storage->exists($key))->toBeFalse();

        foreach ($s3->http->sent as $request) {
            expect($request->maxRedirects)->toBe(0)
                ->and($request->destinations?->hosts)->toBe(['s3.store.example'])
                ->and(json_encode($request))->not->toContain(S3_SECRET);
        }
    });

    it('refuses a key not of the framework\'s shape before anything is sent', function (string $key) {
        $s3 = new FakeS3();
        $storage = $s3->storage();

        expect(fn () => $storage->put($key, s3StreamOf('x')))->toThrow(StorageException::class)
            ->and(fn () => $storage->read($key))->toThrow(StorageException::class)
            ->and(fn () => $storage->delete($key))->toThrow(StorageException::class)
            ->and(fn () => $storage->presignedGet($key, 60))->toThrow(StorageException::class)
            ->and(fn () => $storage->presignedPut($key, 1, 60))->toThrow(StorageException::class)
            ->and($s3->http->sent)->toBe([]);
    })->with(['../etc/passwd', 'a/../../b', '/abs', 'UPPER/x', 'a?b', 'a%2f..']);

    it('says what the store refused by its code alone, with no secret and no signature in it', function () {
        $s3 = new FakeS3();
        $wrong = $s3->storage(secret: 'not-the-secret');

        expect(fn () => $wrong->put(StorageKey::generate('a', 'pdf'), s3StreamOf('x')))
            ->toThrow(StorageException::class, 'the object store answered 403 (SignatureDoesNotMatch)');

        try {
            $wrong->size(StorageKey::generate('a', 'pdf'));
        } catch (StorageException $e) {
            expect($e->getMessage())->not->toContain('Signature=')->not->toContain('not-the-secret');
        }
    });

    it('writes a file larger than a part as a multipart upload, completed only on commit', function () {
        $s3 = new FakeS3();
        $storage = $s3->storage(partBytes: S3Storage::MIN_PART_BYTES);
        $key = StorageKey::generate('images', 'iso');
        $bytes = random_bytes(S3Storage::MIN_PART_BYTES * 2 + 1000);

        $writer = $storage->writer($key);
        foreach (str_split($bytes, 1_000_000) as $chunk) {
            $writer->write($chunk);
        }
        expect($s3->objects)->toBe([])
            ->and($s3->uploads)->toHaveCount(1);
        $writer->commit();

        expect($s3->objects[$key] ?? null)->toBe($bytes)
            ->and($s3->uploads)->toBe([])
            ->and(array_values(array_filter($s3->log, static fn (string $line): bool => str_contains($line, 'partNumber'))))->toHaveCount(3);
    });

    it('aborts a multipart upload given up, or dropped, and keeps nothing at the key', function () {
        $s3 = new FakeS3();
        $storage = $s3->storage(partBytes: S3Storage::MIN_PART_BYTES);
        $key = StorageKey::generate('images', 'iso');

        $writer = $storage->writer($key);
        $writer->write(random_bytes(S3Storage::MIN_PART_BYTES + 1));
        $writer->abort();

        $dropped = $storage->writer($key);
        $dropped->write(random_bytes(S3Storage::MIN_PART_BYTES + 1));
        unset($dropped);

        $small = $storage->writer($key);
        $small->write('never sent');
        $small->abort();

        expect($s3->objects)->toBe([])
            ->and($s3->uploads)->toBe([])
            ->and(fn () => $writer->write('x'))->toThrow(StorageException::class);
    });

    it('presigns a read with the response headers it was given, for one key, for a while', function () {
        $s3 = new FakeS3();
        $storage = $s3->storage();
        $key = StorageKey::generate('receipts', 'pdf');
        $storage->put($key, s3StreamOf('%PDF-1.4 receipt'));

        $url = $storage->presignedGet($key, 60, [
            'content-type' => 'application/pdf',
            'content-disposition' => 'attachment; filename="r.pdf"',
            'cache-control' => 'private, no-store',
        ]);
        $read = $s3->browser('GET', $url);

        expect($read->status)->toBe(200)
            ->and($read->body)->toBe('%PDF-1.4 receipt')
            ->and($read->header('content-disposition'))->toBe('attachment; filename="r.pdf"')
            ->and($read->header('content-type'))->toBe('application/pdf')
            ->and($url)->not->toContain(S3_SECRET);

        // Another key under the same signature, or a header changed, is not it.
        $other = StorageKey::generate('receipts', 'pdf');
        expect($s3->browser('GET', str_replace($key, $other, $url))->status)->toBe(403)
            ->and($s3->browser('GET', str_replace('attachment', 'inline', $url))->status)->toBe(403)
            ->and(fn () => $storage->presignedGet($key, 60, ['x-amz-meta-a' => 'b']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $storage->presignedGet($key, 60, ['content-type' => 'text/html']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $storage->presignedGet($key, 60, ['content-type' => 'application/pdf', 'content-disposition' => 'inline']))->toThrow(InvalidArgumentException::class);

        // Named nothing, a read is an octet stream to save, whatever was kept.
        $bare = $s3->browser('GET', $storage->presignedGet($key, 60));
        expect($bare->header('content-type'))->toBe('application/octet-stream')
            ->and($bare->header('content-disposition'))->toBe('attachment');

        $s3->now += 61;
        expect($s3->browser('GET', $url)->status)->toBe(403);
    });

    it('presigns an upload for its exact size, refused for any other', function () {
        $s3 = new FakeS3();
        $storage = $s3->storage();
        $key = StorageKey::generate('incoming', '');

        $put = $storage->presignedPut($key, 11, 300);

        expect($put->headers)->toBe(['content-type' => 'application/octet-stream', 'content-disposition' => 'attachment'])
            ->and($s3->browser('PUT', $put->url, 'twelve byte', $put->headers)->status)->toBe(200)
            ->and($s3->objects[$key])->toBe('twelve byte')
            ->and($s3->browser('PUT', $put->url, 'a much larger body than was signed', $put->headers)->status)->toBe(403)
            // A page of its own type, to come back as one: refused.
            ->and($s3->browser('PUT', $put->url, '<script>1</s', ['content-type' => 'text/html', 'content-disposition' => 'attachment'])->status)->toBe(403)
            ->and($s3->browser('PUT', $put->url, 'twelve byte', ['content-type' => 'application/octet-stream', 'content-disposition' => 'inline'])->status)->toBe(403)
            ->and($s3->browser('PUT', $put->url, 'twelve byte')->status)->toBe(403)
            ->and($s3->browser('GET', $put->url)->status)->toBe(403)
            ->and($put->expiresAt)->toBe($s3->now + 300)
            ->and(fn () => $storage->presignedPut($key, 0, 300))->toThrow(InvalidArgumentException::class);
    });

    it('takes an endpoint, a bucket and a root only of the shapes a store is reached by', function (array $arguments) {
        expect(fn () => new S3Storage(...[
            'endpoint' => 'https://s3.store.example',
            'bucket' => 'uploads',
            'region' => 'eu-west-1',
            'credentials' => new S3Credentials('AKID', 'secret'),
            'http' => new FakeS3()->http,
            ...$arguments,
        ]))->toThrow(InvalidArgumentException::class);
    })->with([
        'a path on the endpoint' => [['endpoint' => 'https://s3.store.example/bucket']],
        'a user in the endpoint' => [['endpoint' => 'https://me:pw@s3.store.example']],
        'a bucket with a slash' => [['bucket' => 'up/loads']],
        'a bucket of capitals' => [['bucket' => 'Uploads']],
        'a root out of the store' => [['root' => '../x']],
        'parts under 5 MiB' => [['partBytes' => 1024]],
        'a region of anything' => [['region' => 'eu west']],
    ]);

    it('addresses a bucket by its own host when asked to, held to that host alone', function () {
        $sent = [];
        $storage = new S3Storage(
            endpoint: 'https://s3.store.example',
            bucket: 'uploads',
            region: 'eu-west-1',
            credentials: new S3Credentials('AKID', 'secret'),
            http: new Tinvar\Outbound\FakeOutboundHttp(function (OutboundRequest $request) use (&$sent): Tinvar\Outbound\OutboundResponse {
                $sent[] = $request;

                return new Tinvar\Outbound\OutboundResponse(404);
            }),
            pathStyle: false,
        );
        $key = StorageKey::generate('a', 'png');
        $storage->size($key);

        expect($sent[0]->url)->toBe("https://uploads.s3.store.example/{$key}")
            ->and($sent[0]->destinations?->hosts)->toBe(['uploads.s3.store.example']);
    });
});
