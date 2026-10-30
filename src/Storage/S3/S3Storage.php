<?php

declare(strict_types=1);

namespace Tinvar\Storage\S3;

use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\Target;
use Tinvar\Storage\ObjectStore;
use Tinvar\Storage\PresignedUpload;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;
use Tinvar\Storage\StorageWriter;

/**
 * Files in a bucket of an S3-compatible object store: AWS S3, or MinIO,
 * Ceph, R2 and the rest that speak its API.
 *
 *     new S3Storage(
 *         endpoint: 'https://s3.eu-west-1.amazonaws.com',
 *         bucket: 'pellwick-uploads',
 *         region: 'eu-west-1',
 *         credentials: new S3Credentials($id, $secret),
 *         http: $outboundHttp,
 *     )
 *
 * Every request is signed with SigV4 by PHP, which alone holds the
 * credentials: the renderer is handed neither them nor this object, and a
 * browser gets at most a URL presigned for one key, one method and a short
 * while. Requests go through OutboundHttp -- the one client every outbound
 * request uses -- held to the endpoint's own origin (Destinations), with no
 * redirect followed: a store on the application's private network is one
 * its OutboundPolicy opens by `allow`, prefix by prefix, as for any other
 * service of its own.
 *
 * Keys are held to StorageKey's shape on every call, as on disk, and go into
 * the bucket below `root` when one is given. A path-style URL by default --
 * `endpoint/bucket/key`, which every compatible store takes -- or a
 * virtual-hosted one, `bucket.endpoint/key`, which AWS prefers.
 *
 * A file of up to `partBytes` is one PUT; a larger one, or one written
 * through writer(), a multipart upload of parts that size, each held in
 * memory as it is filled -- so nothing is gathered in the temporary
 * directory -- and completed only on commit, or aborted.
 */
final readonly class S3Storage implements ObjectStore
{
    /** 8 MiB: S3 takes parts of 5 MiB and more, but for the last. */
    public const int PART_BYTES = 8_388_608;

    public const int MIN_PART_BYTES = 5_242_880;

    /** Seconds one request may take; a part, or a file sent on to a client, is given longer. */
    public const float TIMEOUT = 30.0;

    private const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private Target $endpoint;

    private SigV4 $signer;

    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(
        string $endpoint,
        private string $bucket,
        string $region,
        S3Credentials $credentials,
        private OutboundHttp $http,
        private string $root = '',
        private bool $pathStyle = true,
        private int $partBytes = self::PART_BYTES,
        private ?\Closure $clock = null,
    ) {
        try {
            $this->endpoint = Target::parse($endpoint);
        } catch (OutboundRefused $e) {
            throw new \InvalidArgumentException("An object store endpoint is an http or https URL with no user in it: {$e->getMessage()}", previous: $e);
        }
        if (!\in_array($this->endpoint->requestTarget(), ['', '/'], true)) {
            // A path on the endpoint would be signed as part of every key's,
            // and a query or a user in it is nothing a store is reached by.
            throw new \InvalidArgumentException('An object store endpoint is a scheme, a host and a port: nothing after them.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/', $bucket) !== 1 || str_contains($bucket, '..')) {
            throw new \InvalidArgumentException("'{$bucket}' is not a bucket name.");
        }
        if ($root !== '' && !StorageKey::isValidPrefix($root)) {
            throw new \InvalidArgumentException("'{$root}' is not a storage prefix to keep files under.");
        }
        if ($partBytes < self::MIN_PART_BYTES) {
            throw new \InvalidArgumentException('A multipart upload\'s parts are at least 5 MiB.');
        }
        $this->signer = new SigV4($credentials, $region);
    }

    public function put(string $key, mixed $stream): void
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Storage::put() takes a readable stream.');
        }

        $writer = $this->writer($key);
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    throw new StorageException('A stored file could not be read to the end.');
                }
                $writer->write($chunk);
            }
            $writer->commit();
        } finally {
            $writer->abort();
        }
    }

    public function read(string $key): mixed
    {
        // Spilled to a temporary file past 2 MB, as php://temp does: a caller
        // that only wants to send a file on uses readTo(), which holds none.
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new StorageException('A stream to read a stored file into cannot be opened.');
        }

        try {
            $found = $this->readTo($key, static function (string $chunk) use ($stream): void {
                if (fwrite($stream, $chunk) !== \strlen($chunk)) {
                    throw new StorageException('A stored file could not be read to the end.');
                }
            });
        } catch (\Throwable $e) {
            fclose($stream);

            throw $e;
        }
        if (!$found) {
            fclose($stream);

            return null;
        }
        rewind($stream);

        return $stream;
    }

    public function readTo(string $key, \Closure $sink): bool
    {
        $response = $this->send('GET', $key, timeout: OutboundRequest::MAX_STREAM_TIMEOUT, sink: $sink, maxBytes: PHP_INT_MAX);
        if ($response->status === 404) {
            return false;
        }
        self::assertOk($response, 'read');

        return true;
    }

    public function exists(string $key): bool
    {
        return $this->size($key) !== null;
    }

    public function size(string $key): ?int
    {
        $response = $this->send('HEAD', $key);
        if ($response->status === 404) {
            return null;
        }
        self::assertOk($response, 'measured');
        $length = $response->header('content-length');

        return $length !== null && ctype_digit($length) ? (int) $length : throw new StorageException('The object store gave a stored file no size.');
    }

    public function delete(string $key): void
    {
        $response = $this->send('DELETE', $key);
        if ($response->status !== 404) {
            self::assertOk($response, 'removed');
        }
    }

    public function writer(string $key): StorageWriter
    {
        StorageKey::assertValid($key);

        return new S3Writer($this, $key, $this->partBytes);
    }

    public function presignedGet(string $key, int $ttl, array $responseHeaders = []): string
    {
        $query = [];
        foreach ($responseHeaders as $name => $value) {
            if (!\in_array($name, ['content-type', 'content-disposition', 'cache-control', 'content-language', 'expires', 'content-encoding'], true)) {
                throw new \InvalidArgumentException("A presigned read cannot set '{$name}'.");
            }
            $query["response-{$name}"] = $value;
        }

        return $this->presigned('GET', $key, $query, [], $ttl);
    }

    public function presignedPut(string $key, int $size, int $ttl): PresignedUpload
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('A presigned upload is for a file of at least a byte.');
        }

        // The length signed: a browser cannot set it, and sends the file's
        // own, which the store holds to this.
        return new PresignedUpload(
            $this->presigned('PUT', $key, [], ['content-length' => (string) $size], $ttl),
            [],
            $this->now() + $ttl,
        );
    }

    /**
     * Sends one signed request for $key and returns the store's answer,
     * whatever its status.
     *
     * For S3Writer, which speaks the multipart calls; nothing else outside
     * this class has a reason to.
     *
     * @internal
     * @param array<string, string> $query
     * @param (\Closure(string): void)|null $sink
     * @throws StorageException when the store could not be reached
     */
    public function send(
        string $method,
        string $key,
        array $query = [],
        string $body = '',
        ?float $timeout = null,
        ?\Closure $sink = null,
        ?int $maxBytes = null,
    ): OutboundResponse {
        StorageKey::assertValid($key);
        [$url, $path, $host] = $this->location($key, $query);
        $headers = $this->signer->sign(
            $method,
            $path,
            $query,
            ['host' => $host],
            $body === '' ? self::EMPTY_SHA256 : hash('sha256', $body),
            $this->now(),
        );

        try {
            return $this->http->send(new OutboundRequest(
                $method,
                $url,
                $headers,
                $body,
                timeout: $timeout ?? self::TIMEOUT,
                maxResponseBytes: $maxBytes ?? 1_048_576,
                // A HEAD's Content-Length is the object's, not a body to cap.
                readBody: $method !== 'HEAD',
                sink: $sink,
                // The one origin this request is for -- the endpoint's, or the
                // bucket's own name under it -- and no other, redirects included.
                destinations: Destinations::originOf($url),
            ));
        } catch (OutboundRefused|OutboundFailed $e) {
            // The store's origin and why, never the request: its headers are
            // its signature.
            throw new StorageException("The object store could not be reached: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Refuses an answer that is not a 2xx, naming the store's own error code
     * -- `AccessDenied`, `NoSuchBucket` -- and nothing else it said.
     *
     * @internal
     */
    public static function assertOk(OutboundResponse $response, string $doing): void
    {
        if ($response->successful()) {
            return;
        }
        $code = preg_match('#<Code>([A-Za-z0-9.]{1,64})</Code>#', $response->body, $m) === 1 ? " ({$m[1]})" : '';

        throw new StorageException("A stored file could not be {$doing}: the object store answered {$response->status}{$code}.");
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers beside `host`, each signed
     */
    private function presigned(string $method, string $key, array $query, array $headers, int $ttl): string
    {
        StorageKey::assertValid($key);
        [$url, $path, $host] = $this->location($key, []);

        return $url . '?' . $this->signer->presign($method, $path, $query, ['host' => $host, ...$headers], $ttl, $this->now());
    }

    /**
     * The URL of $key, its path as signed, and the Host header the request
     * will carry.
     *
     * @param array<string, string> $query
     * @return array{string, string, string}
     */
    private function location(string $key, array $query): array
    {
        $object = ($this->root === '' ? '' : $this->root . '/') . $key;
        $port = $this->endpoint->port === ($this->endpoint->scheme === 'https' ? 443 : 80) ? '' : ':' . $this->endpoint->port;
        $endpointHost = $this->endpoint->literal !== null && str_contains($this->endpoint->literal, ':')
            ? "[{$this->endpoint->literal}]"
            : $this->endpoint->host;

        if ($this->pathStyle) {
            $host = $endpointHost . $port;
            $path = "/{$this->bucket}/{$object}";
        } else {
            $host = "{$this->bucket}.{$endpointHost}{$port}";
            $path = "/{$object}";
        }

        $url = "{$this->endpoint->scheme}://{$host}" . SigV4::canonicalPath($path)
            . ($query === [] ? '' : '?' . SigV4::canonicalQuery($query));

        return [$url, $path, $host];
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint->origin(), 'bucket' => $this->bucket, 'root' => $this->root];
    }
}
