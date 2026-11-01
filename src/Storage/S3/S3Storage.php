<?php

declare(strict_types=1);

namespace Tinvar\Storage\S3;

use Tinvar\Aws\AwsCredentials;
use Tinvar\Aws\SigV4;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\Target;
use Tinvar\Storage\FileTypes;
use Tinvar\Storage\ListsKeys;
use Tinvar\Storage\MultipartStore;
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
 *         credentials: new AwsCredentials($id, $secret),
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
 *
 * A browser may send a file in parts too, each to a URL presigned for its
 * number and length, over as many sittings as it takes (MultipartStore,
 * ADR 0143): PHP begins the upload, asks what parts are there, completes it
 * and aborts it, and never hands out the upload's id but inside a URL it
 * signed.
 */
final readonly class S3Storage implements MultipartStore, ListsKeys
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
        AwsCredentials $credentials,
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

    /**
     * Every presigned read names the type and disposition its response
     * carries, whatever the store kept beside the object: application/
     * octet-stream as an attachment unless the caller says otherwise, and
     * inline only for an image type uploads take. So no object -- one a
     * browser PUT with a type of its own choosing above all -- comes back as
     * something a browser would run.
     */
    public function presignedGet(string $key, int $ttl, array $responseHeaders = []): string
    {
        $responseHeaders += ['content-type' => 'application/octet-stream', 'content-disposition' => 'attachment'];
        $type = strtolower(trim(explode(';', $responseHeaders['content-type'], 2)[0]));
        if ($type !== 'application/octet-stream' && !FileTypes::known($type)) {
            throw new \InvalidArgumentException("A presigned read is not served as '{$type}': only as a type uploads take.");
        }
        if (strtolower(strtok($responseHeaders['content-disposition'], ';') ?: '') !== 'attachment' && !FileTypes::isImage($type)) {
            throw new \InvalidArgumentException('A presigned read is shown inline only as an image; anything else is an attachment.');
        }

        $query = [];
        foreach ($responseHeaders as $name => $value) {
            if (!\in_array($name, ['content-type', 'content-disposition', 'cache-control', 'content-language', 'expires', 'content-encoding'], true)) {
                throw new \InvalidArgumentException("A presigned read cannot set '{$name}'.");
            }
            $query["response-{$name}"] = $value;
        }

        return $this->presigned('GET', $key, $query, [], $ttl);
    }

    /**
     * Signed with the length, and with the type and disposition the object
     * is kept under: application/octet-stream, as an attachment. A browser
     * sends the headers it is handed, and the store refuses a PUT whose
     * Content-Type is anything else -- text/html above all -- so an object
     * uploaded this way is never kept as a type a browser would run, should
     * anything ever read it straight from the bucket. It is not: what comes
     * in this way is sniffed and drawn again by PHP before it is kept
     * anywhere an upload is served from (UploadEndpoint).
     */
    public function presignedPut(string $key, int $size, int $ttl): PresignedUpload
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('A presigned upload is for a file of at least a byte.');
        }

        $headers = ['content-type' => 'application/octet-stream', 'content-disposition' => 'attachment'];

        // The length signed too: a browser cannot set it, and sends the
        // file's own, which the store holds to this.
        return new PresignedUpload(
            $this->presigned('PUT', $key, [], ['content-length' => (string) $size, ...$headers], $ttl),
            $headers,
            $this->now() + $ttl,
        );
    }

    public function beginMultipart(string $key): string
    {
        $response = $this->send('POST', $key, ['uploads' => '']);
        self::assertOk($response, 'begun');
        if (preg_match('#<UploadId>([^<]{1,1024})</UploadId>#', $response->body, $m) !== 1) {
            throw new StorageException('The object store began an upload and named no upload id.');
        }

        return html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES);
    }

    /**
     * Signed with the part's number, the upload's id and the length: a
     * browser sends the length of what it PUTs, which the store holds to
     * this, and cannot move the bytes to another part or another upload.
     * Nothing of the type: a part has none, and the object it becomes is
     * fetched back by PHP and never served (UploadEndpoint).
     */
    public function presignedPart(string $key, string $uploadId, int $number, int $size, int $ttl): PresignedUpload
    {
        if ($number < 1 || $number > 10_000) {
            throw new \InvalidArgumentException('A part is numbered from 1 to 10000.');
        }
        if ($size < 1 || $size > 5_368_709_120) {
            throw new \InvalidArgumentException('A part is between a byte and 5 GiB.');
        }
        if ($uploadId === '') {
            throw new \InvalidArgumentException('A part belongs to an upload.');
        }
        StorageKey::assertValid($key);
        [$url, $path, $host] = $this->location($key, []);
        $query = ['partNumber' => (string) $number, 'uploadId' => $uploadId];

        return new PresignedUpload(
            $url . '?' . $this->signer->presign('PUT', $path, $query, ['host' => $host, 'content-length' => (string) $size], $ttl, $this->now()),
            [],
            $this->now() + $ttl,
        );
    }

    public function listParts(string $key, string $uploadId): ?array
    {
        $parts = [];
        $marker = '0';
        // At most ten pages: an upload has at most ten thousand parts.
        for ($page = 0; $page < 11; $page++) {
            $response = $this->send('GET', $key, ['uploadId' => $uploadId, 'max-parts' => '1000', 'part-number-marker' => $marker]);
            if ($response->status === 404) {
                return null;
            }
            self::assertOk($response, 'listed');
            $list = self::xml($response->body);
            foreach ($list->Part as $part) {
                $number = (string) $part->PartNumber;
                $size = (string) $part->Size;
                if (!ctype_digit($number) || !ctype_digit($size) || (int) $number < 1 || (int) $number > 10_000) {
                    continue;
                }
                $parts[(int) $number] = ['size' => (int) $size, 'etag' => (string) $part->ETag];
            }
            $next = (string) $list->NextPartNumberMarker;
            if ((string) $list->IsTruncated !== 'true' || !ctype_digit($next) || (int) $next <= (int) $marker) {
                ksort($parts);

                return $parts;
            }
            $marker = $next;
        }

        throw new StorageException('The object store listed more parts than an upload has.');
    }

    public function completeMultipart(string $key, string $uploadId, array $etags): void
    {
        if ($etags === []) {
            throw new \InvalidArgumentException('An upload is completed from at least one part.');
        }
        ksort($etags);
        $parts = '';
        foreach ($etags as $number => $etag) {
            $parts .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
        }
        $response = $this->send('POST', $key, ['uploadId' => $uploadId], '<CompleteMultipartUpload>' . $parts . '</CompleteMultipartUpload>', timeout: 120.0);
        self::assertOk($response, 'completed');
        // S3 can answer 200 and put an error in the body of a completion
        // that failed after it began.
        if (str_contains($response->body, '<Error>')) {
            throw new StorageException('A stored file could not be completed: the object store failed the upload as it completed it.');
        }
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        $response = $this->send('DELETE', $key, ['uploadId' => $uploadId]);
        if ($response->status !== 404) {
            self::assertOk($response, 'aborted');
        }
    }

    /**
     * By ListMultipartUploads, a page of up to 1000 at a time until $limit.
     * Needs `s3:ListBucketMultipartUploads` on the bucket: the store's 403
     * is a StorageException then.
     */
    public function multipartUploadsUnder(string $prefix, int $limit): array
    {
        $whole = str_ends_with($prefix, '/') ? substr($prefix, 0, -1) : $prefix;
        if (!StorageKey::isValidPrefix($whole) && !StorageKey::isValid($whole)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage prefix to list.");
        }
        $root = $this->root === '' ? '' : $this->root . '/';
        $uploads = [];
        $keyMarker = null;
        $idMarker = null;
        while (\count($uploads) < $limit) {
            $query = ['uploads' => '', 'prefix' => $root . $prefix, 'max-uploads' => (string) min(1000, $limit - \count($uploads))];
            if ($keyMarker !== null) {
                $query['key-marker'] = $keyMarker;
                $query['upload-id-marker'] = (string) $idMarker;
            }
            [$url, $path, $host] = $this->bucketLocation($query);
            $response = $this->dispatch('GET', $url, $path, $host, $query, '', null, null, null);
            self::assertOk($response, 'listed');
            $list = self::xml($response->body);
            foreach ($list->Upload as $upload) {
                $key = (string) $upload->Key;
                $at = self::timestamp((string) $upload->Initiated);
                if (!str_starts_with($key, $root) || $at === null || \count($uploads) >= $limit) {
                    continue;
                }
                $key = substr($key, \strlen($root));
                if (StorageKey::isValid($key) && (string) $upload->UploadId !== '') {
                    $uploads[] = ['key' => $key, 'uploadId' => (string) $upload->UploadId, 'initiated' => $at];
                }
            }
            if ((string) $list->IsTruncated !== 'true' || (string) $list->NextKeyMarker === '') {
                break;
            }
            $keyMarker = (string) $list->NextKeyMarker;
            $idMarker = (string) $list->NextUploadIdMarker;
        }

        return $uploads;
    }

    /** A document the store answered with, read with no network and no entities. */
    private static function xml(string $body): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $list = simplexml_load_string($body, options: LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($list === false) {
            throw new StorageException('The object store\'s answer could not be read.');
        }

        return $list;
    }

    private static function timestamp(string $at): ?int
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', $at)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $at);

        return $parsed === false ? null : $parsed->getTimestamp();
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

        return $this->dispatch($method, $url, $path, $host, $query, $body, $timeout, $sink, $maxBytes);
    }

    /**
     * The keys under $prefix, by ListObjectsV2, a page of up to 1000 at a
     * time until $limit. Needs `s3:ListBucket` on the bucket, which a policy
     * granting only objects' reads and writes leaves out: the store's 403
     * is a StorageException then.
     */
    public function keysUnder(string $prefix, int $limit): array
    {
        if (!StorageKey::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException("'{$prefix}' is not a storage prefix to list.");
        }
        $root = $this->root === '' ? '' : $this->root . '/';
        $keys = [];
        $token = null;
        while (\count($keys) < $limit) {
            $query = ['list-type' => '2', 'prefix' => $root . $prefix . '/', 'max-keys' => (string) min(1000, $limit - \count($keys))];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            [$url, $path, $host] = $this->bucketLocation($query);
            $response = $this->dispatch('GET', $url, $path, $host, $query, '', null, null, null);
            self::assertOk($response, 'listed');
            $list = self::xml($response->body);
            foreach ($list->Contents as $object) {
                $key = (string) $object->Key;
                $at = self::timestamp((string) $object->LastModified);
                if (!str_starts_with($key, $root) || $at === null) {
                    continue;
                }
                $key = substr($key, \strlen($root));
                if (StorageKey::isValid($key) && \count($keys) < $limit) {
                    $keys[$key] = $at;
                }
            }
            $token = (string) $list->IsTruncated === 'true' ? (string) $list->NextContinuationToken : '';
            if ($token === '') {
                break;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, string> $query
     * @param (\Closure(string): void)|null $sink
     */
    private function dispatch(
        string $method,
        string $url,
        string $path,
        string $host,
        array $query,
        string $body,
        ?float $timeout,
        ?\Closure $sink,
        ?int $maxBytes,
    ): OutboundResponse {
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
        return $this->address(($this->root === '' ? '' : $this->root . '/') . $key, $query);
    }

    /**
     * The bucket's own URL, for a request about the bucket rather than one
     * key: a listing.
     *
     * @param array<string, string> $query
     * @return array{string, string, string}
     */
    private function bucketLocation(array $query): array
    {
        return $this->address('', $query);
    }

    /**
     * @param array<string, string> $query
     * @return array{string, string, string}
     */
    private function address(string $object, array $query): array
    {
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
