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
     * @param ?string                     $rawQuery the query string exactly as
     *                                              received, without the `?`; null
     *                                              when the adapter does not have it
     */
    public readonly string $method;

    public readonly string $path;

    /**
     * Normalization happens here, not in the factories.
     *
     * A server adapter builds requests with `new Request(...)` straight from
     * the environment; if the guard lived only in the test factories, the one
     * place that matters would be the one place without it.
     */
    public function __construct(
        string $method,
        string $path,
        private readonly array $headers = [],
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $cookies = [],
        public readonly ?string $rawQuery = null,
        /**
         * The address of the peer that connected, from the server -- never
         * from a header. X-Forwarded-For is whatever the client chose to
         * send unless a proxy you control overwrote it, so anything keyed on
         * a client's identity (a throttle) belongs on this.
         */
        public readonly ?string $remoteAddress = null,
    ) {
        $this->method = strtoupper($method);
        $this->path = self::normalizePath($path);
    }

    public static function get(string $path, array $headers = [], array $query = []): self
    {
        return new self('GET', $path, self::lowercase($headers), $query);
    }

    public static function post(string $path, array $body = [], array $headers = []): self
    {
        return new self('POST', $path, self::lowercase($headers), [], $body);
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
     * Normalizes a client-supplied path, or refuses it.
     *
     * The guard decodes each segment before inspecting it. Checking the raw
     * path first would let `%2e%2e%2f` through, since matching decodes
     * afterwards -- the segment would arrive at a loader, an #[Authorize]
     * subject and the ORM as `../`. Nothing in routing touches the filesystem
     * today, which is exactly why this is worth holding now rather than after
     * something does.
     *
     * A refusal is a 404: the path is fully client-controlled, so a malformed
     * one is a route that does not exist, not a server error.
     */
    public static function normalizePath(string $path): string
    {
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];

        // Raw control characters never belong in a path, and a CR or LF in one
        // forges log lines wherever the path is recorded.
        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new NotFoundException('Path contains a control character.');
        }

        $path = (string) preg_replace('#/+#', '/', '/' . trim($path, '/'));

        if ($path === '/') {
            return '/';
        }

        foreach (explode('/', ltrim($path, '/')) as $segment) {
            $decoded = rawurldecode($segment);

            // A parameter is one segment. A decoded separator, a traversal
            // step or a null byte means the request is trying to be more.
            // Control characters are checked again after decoding: the raw
            // check above cannot see %0a, and a decoded newline reaches logs
            // through parameter values.
            //
            // A backslash is refused the same way. Browsers treat it as a
            // separator in http URLs, so "/\evil.example/x" echoed back as a
            // relative location resolves to https://evil.example/x -- an open
            // redirect anywhere a path is reflected, which the version check's
            // X-Location does. Response::assertSameOrigin already refuses it;
            // the path a request is built from has to agree.
            if ($decoded === '.' || $decoded === '..'
                || str_contains($decoded, '/')
                || str_contains($decoded, '\\')
                || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1) {
                // The segment is not interpolated: it is client-controlled and
                // this message reaches logs.
                throw new NotFoundException('A path segment is not a single path segment.');
            }
        }

        return $path;
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
