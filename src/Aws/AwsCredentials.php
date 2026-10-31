<?php

declare(strict_types=1);

namespace Tinvar\Aws;

/**
 * The access key an AWS API's requests are signed with: an object store's,
 * or SES's for mail.
 *
 * Held by PHP alone. Nothing that leaves the server carries the secret: a
 * request carries a signature made with a key derived from it for one day,
 * region and service, and a presigned URL the same. The renderer is never
 * handed this object, and no Data object can hold it (the encoder refuses
 * anything that is not Data). Kept out of dumps and stack traces, as every
 * secret here is.
 */
final readonly class AwsCredentials
{
    public function __construct(
        public string $accessKey,
        #[\SensitiveParameter]
        private string $secretKey,
        #[\SensitiveParameter]
        private ?string $sessionToken = null,
    ) {
        if (preg_match('/^[A-Za-z0-9]{3,128}\z/', $accessKey) !== 1) {
            throw new \InvalidArgumentException('An access key id is letters and digits.');
        }
        if ($secretKey === '') {
            throw new \InvalidArgumentException('An AWS secret access key is not empty.');
        }
    }

    /** The key a day's requests are signed with, for one region and service: what the secret is used for, and all. */
    public function signingKey(string $date, string $region, string $service): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    /** A temporary credential's token, sent beside the signature; null for a long-lived key. */
    public function sessionToken(): ?string
    {
        return $this->sessionToken;
    }

    /**
     * $text with the secret and the session token cut out: for an error a
     * service wrote, which should never quote either, and is read as if it
     * might.
     */
    public function redact(string $text): string
    {
        return str_replace(array_values(array_filter([$this->secretKey, $this->sessionToken], static fn (?string $s): bool => $s !== null && $s !== '')), '[redacted]', $text);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['accessKey' => $this->accessKey, 'secretKey' => '[redacted]'];
    }
}
