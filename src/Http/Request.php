<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * An incoming request.
 *
 * PHP stays in front of every request: routing, middleware, auth, validation,
 * the ORM, queries and actions. This is the object all of that runs against.
 */
final class Request
{
    /** @var array<string, mixed> values middleware and the router attach */
    private array $attributes = [];

    /**
     * @param array<string, string>       $headers lowercased names
     * @param array<string, string|list<string>> $query
     * @param array<string, mixed>        $body
     * @param array<string, string>       $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers = [],
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $cookies = [],
    ) {}

    public static function get(string $path, array $headers = [], array $query = []): self
    {
        return new self('GET', self::normalizePath($path), self::lowercase($headers), $query);
    }

    public static function post(string $path, array $body = [], array $headers = []): self
    {
        return new self('POST', self::normalizePath($path), self::lowercase($headers), [], $body);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * True when the client wants the RSC payload rather than HTML.
     * `RSC: 1` + `Vary: RSC`, the spec's rename of Inertia's X-Inertia.
     */
    public function wantsRscPayload(): bool
    {
        return $this->header('rsc') === '1';
    }

    public function isPrefetch(): bool
    {
        return $this->header('purpose') === 'prefetch';
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Collapses `//`, strips a trailing slash and refuses traversal.
     *
     * A path is matched against compiled routes and never touches the file
     * system, but normalizing here keeps `/racks/../admin` from matching
     * anything by accident.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/' . trim(explode('?', $path, 2)[0], '/');
        $path = (string) preg_replace('#/+#', '/', $path);

        if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new \InvalidArgumentException("Path '{$path}' contains traversal segments.");
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function lowercase(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower($name)] = $value;
        }

        return $out;
    }
}
