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

    /** Set once the producer has run, so a render is never replayed. */
    private ?string $buffered = null;

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

    /**
     * A same-origin redirect.
     *
     * "ActionResult::redirect(), back() and the 419 redirect accept
     * same-origin URLs only. External URLs go through location() with an
     * allowlist." An absolute or scheme-relative target is refused here rather
     * than checked by every caller.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        self::assertSameOrigin($location);

        return self::make($status, '', ['location' => $location]);
    }

    /**
     * A redirect target must be a path on this origin, and nothing else.
     *
     * `//evil.example` is protocol-relative. `/\evil.example` is treated the
     * same way by browsers, so a check for `//` alone is not enough -- and a
     * backslash anywhere is rejected rather than reasoned about, since
     * browsers normalize it to `/` in paths.
     */
    private static function assertSameOrigin(string $location): void
    {
        $refuse = static fn (string $why): never => throw new \InvalidArgumentException(
            "A redirect target must be a root-relative path ({$why}). "
            . 'Use Response::location() for an allowlisted external URL.'
        );

        if (!str_starts_with($location, '/')) {
            $refuse('it does not start with /');
        }
        if (str_contains($location, '\\')) {
            $refuse('it contains a backslash');
        }
        if (str_starts_with($location, '//')) {
            $refuse('it is protocol-relative');
        }

        $parts = parse_url($location);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            $refuse('it names a scheme or host');
        }
    }

    /**
     * An off-origin redirect, for targets the application has allowed.
     *
     * Separate from redirect() on purpose: the allowlist decision belongs to
     * the caller, and making it type out a different method name is the point.
     */
    public static function location(string $url, int $status = 302): self
    {
        return self::make($status, '', ['location' => $url]);
    }

    public function withHeader(string $name, string $value): self
    {
        self::assertSafeHeader($name, $value);

        $clone = clone $this;
        $clone->headers[strtolower($name)] = $value;

        return $clone;
    }

    /**
     * Header values may not carry CR, LF or other control characters.
     *
     * A value reaching the wire with a newline in it is response splitting:
     * everything after the break is read by the client as further headers.
     */
    private static function assertSafeHeader(string $name, string $value): void
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Header name contains characters that are not allowed.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException("Header '{$name}' contains a control character.");
        }
    }

    /**
     * Appends to a comma-joined header such as Vary, without duplicating a
     * value. Header values of this kind are case-insensitive, so `Vary: rsc`
     * already covers `RSC`.
     */
    public function withAddedHeader(string $name, string $value): self
    {
        $existing = $this->header($name);
        if ($existing === null) {
            return $this->withHeader($name, $value);
        }

        $parts = array_map(trim(...), explode(',', $existing));
        foreach ($parts as $part) {
            if (strcasecmp($part, $value) === 0) {
                return $this;
            }
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

    /**
     * Buffers a streaming body. Tests and error paths want the whole thing.
     *
     * Memoized: a producer is a render, and an error path or a logger calling
     * this after the body has already been produced must not run it again.
     */
    public function body(): string
    {
        if ($this->producer === null) {
            return $this->body;
        }
        if ($this->buffered !== null) {
            return $this->buffered;
        }

        $buffer = '';
        ($this->producer)(static function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        return $this->buffered = $buffer;
    }
}
