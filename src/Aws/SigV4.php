<?php

declare(strict_types=1);

namespace Tinvar\Aws;

/**
 * AWS Signature Version 4, as S3 and every store speaking its API take it,
 * and every other AWS API the framework calls -- SES's, for mail: a request
 * signed in its Authorization header, or a URL presigned in its query. One
 * signer for each, told the service it signs for (`s3`, `ses`).
 *
 * What is signed is the canonical request -- the method, the path, the
 * query, the headers named in SignedHeaders and the payload's hash -- under
 * a key derived from the secret for one day, region and service
 * (AwsCredentials::signingKey()). The secret itself goes nowhere.
 *
 * A presigned URL is a capability: whoever holds it may make that one
 * request until it expires. So one is minted only for a key the framework
 * made, for seconds or minutes, and -- for an upload -- with the size it
 * may be signed in, so the store refuses any other.
 */
final readonly class SigV4
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    /** The payload hash a presigned URL signs: the body is not known when it is minted. */
    public const string UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /** The longest a presigned URL may be valid for, by SigV4's own rule: a week. */
    public const int MAX_EXPIRES = 604_800;

    public function __construct(
        private AwsCredentials $credentials,
        private string $region,
        private string $service = 's3',
    ) {
        if (preg_match('/^[a-z0-9-]{1,64}\z/', $region) !== 1) {
            throw new \InvalidArgumentException("'{$region}' is not a region name.");
        }
    }

    /**
     * The headers that sign a request: x-amz-date, x-amz-content-sha256,
     * Authorization, and the session token when there is one. $headers must
     * hold `host`, as the request will send it.
     *
     * @param array<string, string> $headers lowercased name => value, every one signed
     * @param array<string, string> $query name => value, unencoded
     * @return array<string, string> the headers to add
     */
    public function sign(string $method, string $path, array $query, array $headers, string $payloadHash, int $now): array
    {
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $date = substr($amzDate, 0, 8);
        $added = ['x-amz-date' => $amzDate, 'x-amz-content-sha256' => $payloadHash];
        if ($this->credentials->sessionToken() !== null) {
            $added['x-amz-security-token'] = $this->credentials->sessionToken();
        }
        $headers = [...$headers, ...$added];

        [$canonicalHeaders, $signedHeaders] = self::canonicalHeaders($headers);
        $canonical = implode("\n", [$method, self::canonicalPath($path), self::canonicalQuery($query), $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";

        $added['authorization'] = \sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->credentials->accessKey,
            $scope,
            $signedHeaders,
            $this->signature($date, self::stringToSign($amzDate, $scope, $canonical)),
        );

        return $added;
    }

    /**
     * The query that presigns a request: the X-Amz-* parameters and the
     * signature, beside the $query given (response-* overrides, say), all
     * of it to be sent as it is returned.
     *
     * @param array<string, string> $query name => value, unencoded
     * @param array<string, string> $headers lowercased name => value the request must send: `host` at least
     * @return string the query string, encoded
     */
    public function presign(string $method, string $path, array $query, array $headers, int $expires, int $now): string
    {
        if ($expires < 1 || $expires > self::MAX_EXPIRES) {
            throw new \InvalidArgumentException('A presigned URL is valid for between a second and a week.');
        }

        $amzDate = gmdate('Ymd\THis\Z', $now);
        $date = substr($amzDate, 0, 8);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        [$canonicalHeaders, $signedHeaders] = self::canonicalHeaders($headers);

        $query = [
            ...$query,
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => "{$this->credentials->accessKey}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => $signedHeaders,
        ];
        if ($this->credentials->sessionToken() !== null) {
            $query['X-Amz-Security-Token'] = $this->credentials->sessionToken();
        }

        $canonical = implode("\n", [$method, self::canonicalPath($path), self::canonicalQuery($query), $canonicalHeaders, $signedHeaders, self::UNSIGNED_PAYLOAD]);
        $query['X-Amz-Signature'] = $this->signature($date, self::stringToSign($amzDate, $scope, $canonical));

        return self::canonicalQuery($query);
    }

    /** The path as SigV4 has it: each segment percent-encoded, the slashes between kept. */
    public static function canonicalPath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path === '' ? '/' : $path)));
    }

    /** @param array<string, string> $query */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode($value);
        }
        ksort($pairs, SORT_STRING);
        $out = [];
        foreach ($pairs as $name => $value) {
            $out[] = "{$name}={$value}";
        }

        return implode('&', $out);
    }

    /**
     * @param array<string, string> $headers
     * @return array{string, string} the canonical headers, a newline after each, and the signed header list
     */
    private static function canonicalHeaders(array $headers): array
    {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower((string) $name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        if (!isset($canonical['host'])) {
            throw new \InvalidArgumentException('A signed request names its host.');
        }
        ksort($canonical, SORT_STRING);

        $lines = '';
        foreach ($canonical as $name => $value) {
            $lines .= "{$name}:{$value}\n";
        }

        return [$lines, implode(';', array_keys($canonical))];
    }

    private static function stringToSign(string $amzDate, string $scope, string $canonical): string
    {
        return implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonical)]);
    }

    private function signature(string $date, string $stringToSign): string
    {
        return hash_hmac('sha256', $stringToSign, $this->credentials->signingKey($date, $this->region, $this->service));
    }
}
