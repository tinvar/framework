<?php

declare(strict_types=1);

/**
 * An S3 that lives in memory, for FakeOutboundHttp to answer from: objects
 * and multipart uploads in one bucket, path-style, every request's SigV4
 * signature checked against the secret it was given -- a header-signed one
 * as the client sends it, a presigned URL as a browser would use it.
 */

namespace Tinvar\Tests\Storage\Fixtures;

use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Outbound\Target;
use Tinvar\Storage\S3\S3Credentials;
use Tinvar\Storage\S3\S3Storage;
use Tinvar\Storage\S3\SigV4;

const S3_ACCESS = 'AKIDTESTFAKE0001';
const S3_SECRET = 'fake-secret/never-sent+0123456789abcdefghij';
const S3_BUCKET = 'uploads';
const S3_ENDPOINT = 'https://s3.store.example';

final class FakeS3
{
    /** @var array<string, string> object key => bytes */
    public array $objects = [];

    /** @var array<string, array<int, string>> upload id => part number => bytes */
    public array $uploads = [];

    /** @var list<string> each request, as "METHOD /path?query" */
    public array $log = [];

    public int $now = 1_800_000_000;

    public readonly FakeOutboundHttp $http;

    public function __construct(private readonly string $secret = S3_SECRET)
    {
        $this->http = new FakeOutboundHttp(fn (OutboundRequest $request): OutboundResponse => $this->handle(
            $request->method,
            $request->url,
            $request->headers,
            $request->body,
        ));
    }

    public function storage(string $root = '', int $partBytes = S3Storage::PART_BYTES, ?string $secret = null): S3Storage
    {
        return new S3Storage(
            endpoint: S3_ENDPOINT,
            bucket: S3_BUCKET,
            region: 'eu-west-1',
            credentials: new S3Credentials(S3_ACCESS, $secret ?? $this->secret),
            http: $this->http,
            root: $root,
            partBytes: $partBytes,
            clock: fn (): int => $this->now,
        );
    }

    /**
     * A browser's request to a presigned URL: the signature in its query,
     * and the headers a browser sends with it.
     *
     * @param array<string, string> $headers
     */
    public function browser(string $method, string $url, string $body = '', array $headers = []): OutboundResponse
    {
        return $this->handle($method, $url, ['content-length' => (string) \strlen($body), ...$headers], $body, presigned: true);
    }

    /** @param array<string, string> $headers */
    private function handle(string $method, string $url, array $headers, string $body, bool $presigned = false): OutboundResponse
    {
        $target = Target::parse($url);
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        $this->log[] = "{$method} {$path}" . ($query === [] ? '' : '?' . implode('&', array_keys($query)));
        $host = $target->host . ($target->port === 443 ? '' : ":{$target->port}");

        if (!($presigned ? $this->presignedHolds($method, $path, $query, $host, $headers) : $this->signedHolds($method, $path, $query, $host, $headers, $body))) {
            return new OutboundResponse(403, [], '<Error><Code>SignatureDoesNotMatch</Code></Error>');
        }

        if (!str_starts_with($path, '/' . S3_BUCKET . '/')) {
            return new OutboundResponse(404, [], '<Error><Code>NoSuchBucket</Code></Error>');
        }
        $key = substr($path, \strlen(S3_BUCKET) + 2);

        if ($method === 'POST' && \array_key_exists('uploads', $query)) {
            $id = bin2hex(random_bytes(8));
            $this->uploads[$id] = [];

            return new OutboundResponse(200, [], "<InitiateMultipartUploadResult><UploadId>{$id}</UploadId></InitiateMultipartUploadResult>");
        }
        if ($method === 'PUT' && isset($query['uploadId'], $query['partNumber'])) {
            if (!isset($this->uploads[$query['uploadId']])) {
                return new OutboundResponse(404, [], '<Error><Code>NoSuchUpload</Code></Error>');
            }
            $this->uploads[$query['uploadId']][(int) $query['partNumber']] = $body;

            return new OutboundResponse(200, ['etag' => ['"' . md5($body) . '"']]);
        }
        if ($method === 'POST' && isset($query['uploadId'])) {
            $parts = $this->uploads[$query['uploadId']] ?? null;
            if ($parts === null) {
                return new OutboundResponse(404, [], '<Error><Code>NoSuchUpload</Code></Error>');
            }
            ksort($parts);
            $this->objects[$key] = implode('', $parts);
            unset($this->uploads[$query['uploadId']]);

            return new OutboundResponse(200, [], '<CompleteMultipartUploadResult/>');
        }
        if ($method === 'DELETE' && isset($query['uploadId'])) {
            unset($this->uploads[$query['uploadId']]);

            return new OutboundResponse(204);
        }

        return match ($method) {
            'PUT' => $this->put($key, $body),
            'GET' => isset($this->objects[$key])
                ? new OutboundResponse(200, self::responseHeaders($query), $this->objects[$key])
                : new OutboundResponse(404, [], '<Error><Code>NoSuchKey</Code></Error>'),
            'HEAD' => isset($this->objects[$key])
                ? new OutboundResponse(200, ['content-length' => [(string) \strlen($this->objects[$key])]])
                : new OutboundResponse(404),
            'DELETE' => $this->delete($key),
            default => new OutboundResponse(405),
        };
    }

    private function put(string $key, string $body): OutboundResponse
    {
        $this->objects[$key] = $body;

        return new OutboundResponse(200, ['etag' => ['"' . md5($body) . '"']]);
    }

    private function delete(string $key): OutboundResponse
    {
        unset($this->objects[$key]);

        return new OutboundResponse(204);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, list<string>>
     */
    private static function responseHeaders(array $query): array
    {
        $headers = [];
        foreach ($query as $name => $value) {
            if (str_starts_with($name, 'response-')) {
                $headers[substr($name, 9)] = [$value];
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function signedHolds(string $method, string $path, array $query, string $host, array $headers, string $body): bool
    {
        $authorization = $headers['authorization'] ?? '';
        if (preg_match('#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([a-z0-9-]+)/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([0-9a-f]{64})$#', $authorization, $m) !== 1
            || $m[1] !== S3_ACCESS
            || ($headers['x-amz-content-sha256'] ?? '') !== hash('sha256', $body)) {
            return false;
        }

        $signed = [];
        foreach (explode(';', $m[4]) as $name) {
            if ($name === 'host') {
                $signed['host'] = $host;
            } elseif (isset($headers[$name]) && !\in_array($name, ['x-amz-date', 'x-amz-content-sha256', 'x-amz-security-token'], true)) {
                $signed[$name] = $headers[$name];
            }
        }
        $date = (string) ($headers['x-amz-date'] ?? '');
        $at = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $date, new \DateTimeZone('UTC'));
        if ($at === false || abs($at->getTimestamp() - $this->now) > 900) {
            return false;
        }

        $expected = new SigV4(new S3Credentials(S3_ACCESS, $this->secret), $m[3])
            ->sign($method, $path, $query, $signed, $headers['x-amz-content-sha256'], $at->getTimestamp());

        return hash_equals($expected['authorization'], $authorization);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function presignedHolds(string $method, string $path, array $query, string $host, array $headers): bool
    {
        $signature = $query['X-Amz-Signature'] ?? '';
        $at = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $query['X-Amz-Date'] ?? '', new \DateTimeZone('UTC'));
        $expires = (int) ($query['X-Amz-Expires'] ?? 0);
        if ($at === false || $this->now > $at->getTimestamp() + $expires
            || preg_match('#^' . S3_ACCESS . '/\d{8}/([a-z0-9-]+)/s3/aws4_request$#', $query['X-Amz-Credential'] ?? '', $m) !== 1) {
            return false;
        }

        $signed = [];
        foreach (explode(';', $query['X-Amz-SignedHeaders'] ?? '') as $name) {
            $signed[$name] = $name === 'host' ? $host : ($headers[$name] ?? '');
        }
        $unsigned = array_filter(
            $query,
            static fn (string $name): bool => !\in_array($name, ['X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders', 'X-Amz-Signature', 'X-Amz-Security-Token'], true),
            ARRAY_FILTER_USE_KEY,
        );
        $expected = new SigV4(new S3Credentials(S3_ACCESS, $this->secret), $m[1])
            ->presign($method, $path, $unsigned, $signed, $expires, $at->getTimestamp());
        parse_str($expected, $recomputed);

        return \is_string($recomputed['X-Amz-Signature'] ?? null) && hash_equals($recomputed['X-Amz-Signature'], $signature);
    }
}
