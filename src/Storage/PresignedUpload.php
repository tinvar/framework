<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * Where a browser sends one file straight to an object store: a URL signed
 * for one key, one size and a few minutes, and the headers the request must
 * carry for the signature to hold.
 */
final readonly class PresignedUpload implements \JsonSerializable
{
    /** @param array<string, string> $headers lowercased name => value, to send as given */
    public function __construct(
        public string $url,
        public array $headers,
        public int $expiresAt,
        public string $method = 'PUT',
    ) {}

    /** @return array{method: string, url: string, headers: array<string, string>, expiresAt: int} */
    public function jsonSerialize(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'headers' => $this->headers, 'expiresAt' => $this->expiresAt];
    }
}
