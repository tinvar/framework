<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * One request for OutboundHttp to send.
 *
 * Headers are sensitive -- an Authorization header, a signature, an API
 * key -- so they are kept out of what a dump of the request shows
 * (__debugInfo), out of a stack trace's arguments (#[\SensitiveParameter]),
 * and out of every message OutboundHttp throws. The body is shown only by
 * its length, and the URL by its origin.
 *
 * Checked when it is made: a header name is a token and a value is one
 * line, so nothing a caller passes can write a header of its own; and the
 * headers that frame the request -- Host, Content-Length,
 * Transfer-Encoding, Connection -- are curl's, so the host that was vetted
 * is the host the request names.
 *
 * `maxRedirects` is 0 unless asked for, as the spec has it ("redirects are
 * not followed"); see PinnedHttp for what following one involves.
 * `readBody: false` stops reading at the body, for a caller that wants the
 * status alone -- a webhook delivery, whose tenant sees only that.
 */
final readonly class OutboundRequest implements \JsonSerializable
{
    public const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** The most redirects a request may ask to have followed. */
    public const int MAX_REDIRECTS = 5;

    /** Headers the transport writes itself. */
    private const array RESERVED = ['host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade', 'te', 'trailer', 'expect', 'proxy-authorization', 'proxy-connection'];

    /** @var array<string, string> lowercased name => value */
    public array $headers;

    /**
     * @param array<array-key, mixed> $headers name => value
     * @param float|null $timeout seconds the whole request may take, redirects included; the client's default when null
     * @param int|null $maxResponseBytes the largest body read; the client's default when null
     */
    public function __construct(
        public string $method,
        public string $url,
        #[\SensitiveParameter]
        array $headers = [],
        #[\SensitiveParameter]
        public string $body = '',
        public ?float $timeout = null,
        public ?int $maxResponseBytes = null,
        public bool $readBody = true,
        public int $maxRedirects = 0,
    ) {
        if (!\in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException("'{$method}' is not a method outbound requests use: " . implode(', ', self::METHODS) . '.');
        }
        if ($timeout !== null && ($timeout <= 0 || $timeout > 120)) {
            throw new \InvalidArgumentException('A timeout is more than 0 seconds and at most 120.');
        }
        if ($maxResponseBytes !== null && $maxResponseBytes < 0) {
            throw new \InvalidArgumentException('A response size cap cannot be negative.');
        }
        if ($maxRedirects < 0 || $maxRedirects > self::MAX_REDIRECTS) {
            throw new \InvalidArgumentException('A request follows between 0 and ' . self::MAX_REDIRECTS . ' redirects.');
        }

        $checked = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) !== 1) {
                throw new \InvalidArgumentException('A header name is a token: letters, digits and !#$%&\'*+.^_`|~-.');
            }
            $lower = strtolower($name);
            if (\in_array($lower, self::RESERVED, true) || str_starts_with($lower, 'proxy-')) {
                throw new \InvalidArgumentException("The {$name} header is the transport's to write.");
            }
            if (!\is_string($value) || preg_match('/^[^\r\n\0]*\z/', $value) !== 1) {
                // The value is not repeated: it may be the secret.
                throw new \InvalidArgumentException("The {$name} header's value is not one line of text.");
            }
            $checked[$lower] = $value;
        }

        $this->headers = $checked;
    }

    /** @param array<array-key, mixed> $headers */
    public static function get(string $url, #[\SensitiveParameter] array $headers = []): self
    {
        return new self('GET', $url, $headers);
    }

    /** @param array<array-key, mixed> $headers */
    public static function post(string $url, #[\SensitiveParameter] string $body, #[\SensitiveParameter] array $headers = []): self
    {
        return new self('POST', $url, $headers, $body);
    }

    /**
     * What a dump shows: the destination's origin, the header names, and
     * the body's length -- a path, a query and a header can each be the
     * secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        try {
            $url = Target::parse($this->url)->origin() . '/[redacted]';
        } catch (OutboundRefused) {
            $url = '[redacted]';
        }

        return [
            'method' => $this->method,
            'url' => $url,
            'headers' => array_map(static fn (): string => '[redacted]', $this->headers),
            'body' => \strlen($this->body) . ' bytes',
            'timeout' => $this->timeout,
            'maxResponseBytes' => $this->maxResponseBytes,
            'readBody' => $this->readBody,
            'maxRedirects' => $this->maxRedirects,
        ];
    }

    /**
     * What json_encode() writes, which a log line may be: the same as a
     * dump, never the public properties themselves.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
