<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * An outgoing response.
 *
 * The body is either a string or a producer that writes to a sink, so a render
 * can stream through the same type an error page uses.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var (\Closure(\Closure(string): void): void)|null */
    private ?\Closure $producer = null;

    private function __construct(
        public readonly int $status,
        private string $body = '',
    ) {}

    /** @param array<string, string> $headers */
    public static function make(int $status = 200, string $body = '', array $headers = []): self
    {
        $response = new self($status, $body);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** @param \Closure(\Closure(string): void): void $producer */
    public static function stream(\Closure $producer, int $status = 200, array $headers = []): self
    {
        $response = self::make($status, '', $headers);
        $response->producer = $producer;

        return $response;
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return self::make($status, '', ['location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = $value;

        return $clone;
    }

    /** Appends to a comma-joined header such as Vary, without duplicating a value. */
    public function withAddedHeader(string $name, string $value): self
    {
        $existing = $this->header($name);
        if ($existing === null) {
            return $this->withHeader($name, $value);
        }

        $parts = array_map(trim(...), explode(',', $existing));
        if (in_array($value, $parts, true)) {
            return $this;
        }
        $parts[] = $value;

        return $this->withHeader($name, implode(', ', $parts));
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isStreaming(): bool
    {
        return $this->producer !== null;
    }

    /** @param \Closure(string): void $sink */
    public function sendBody(\Closure $sink): void
    {
        if ($this->producer !== null) {
            ($this->producer)($sink);

            return;
        }

        if ($this->body !== '') {
            $sink($this->body);
        }
    }

    /** Buffers a streaming body. Tests and error paths want the whole thing. */
    public function body(): string
    {
        if ($this->producer === null) {
            return $this->body;
        }

        $buffer = '';
        ($this->producer)(static function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        return $buffer;
    }
}
