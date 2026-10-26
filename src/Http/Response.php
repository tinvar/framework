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

    /**
     * Set-Cookie lines, by cookie name.
     *
     * Apart from the headers because a response can set several cookies --
     * a session and a CSRF token on one page -- and a header map holds one
     * value per name, so the second cookie replaced the first.
     *
     * @var array<string, string>
     */
    private array $cookies = [];

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

    /**
     * @param \Closure(\Closure(string): void): void $producer
     * @param array<string, string>                 $headers
     */
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
     * Whether a target passes the same rules redirect() enforces. For code
     * that has to classify a Location it did not build -- one validator, so
     * two cannot disagree about the same string.
     */
    public static function isSameOriginTarget(string $location): bool
    {
        try {
            self::assertSameOrigin($location);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
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
            . 'Use Response::location() for an allowlisted external URL.',
        );

        // Checked here, first, rather than relied on downstream. withHeader()
        // also refuses control characters, but then the guarantee would
        // depend on this value happening to travel through it -- and an open
        // redirect is exactly the bug that lives in two validators
        // disagreeing about the same string.
        if (preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            $refuse('it contains a control or whitespace character');
        }
        if (!str_starts_with($location, '/')) {
            $refuse('it does not start with /');
        }
        if (str_contains($location, '\\')) {
            $refuse('it contains a backslash');
        }
        if (str_starts_with($location, '//')) {
            $refuse('it is protocol-relative');
        }

        // `/..//host` normalizes to `//host` under RFC 3986 dot-segment
        // removal. Browsers resolve that against this origin, so it is not a
        // redirect off-site today -- but a target with traversal in it is
        // never intentional, and parsers disagree about these. Encoded too:
        // browsers decode `%2e%2e` as `..` when they resolve a path, and
        // `/%2e%2e//host` reached a client that hands the result to
        // location.assign() as `//host`, which is another site.
        foreach (explode('/', explode('?', $location, 2)[0]) as $segment) {
            if (str_ireplace('%2e', '.', $segment) === '..') {
                $refuse('it contains a traversal segment');
            }
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
     * An action's is ActionResult::location(), which the action endpoint
     * checks against the application's ExternalOrigins before it gets here.
     */
    public static function location(string $url, int $status = 302): self
    {
        return self::make($status, '', ['location' => $url]);
    }

    /**
     * The same response under another status: an error page rendered as a
     * page, answered with the status of the failure it stands for.
     */
    public function withStatus(int $status): self
    {
        return clone($this, ['status' => $status]);
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
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name) !== 1) {
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

    /**
     * A cookie, written with the attributes the spec's cookie rule requires.
     *
     * tinvar-spec.md, "HTTP": "Session and CSRF cookies use the `__Host-`
     * prefix and are `HttpOnly` (session), `Secure` and `SameSite=Lax`." So
     * Secure, SameSite=Lax and Path=/ are always written, and the prefix is
     * required rather than offered: a browser holds a `__Host-` cookie to
     * Secure, Path=/ and no Domain, which is what stops a sibling subdomain
     * or a plain-http response from planting one -- and it does so only when
     * the name says so.
     *
     * HttpOnly by default. Only a cookie script must read, the CSRF token,
     * turns it off.
     *
     * @param int|null $maxAge seconds until the browser drops it; null for
     *                         a browser-session cookie
     */
    public function withCookie(string $name, string $value, bool $httpOnly = true, ?int $maxAge = null): self
    {
        if (preg_match('/^__Host-[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name) !== 1) {
            throw new \InvalidArgumentException('A cookie name must be a __Host- prefixed token.');
        }

        // RFC 6265 cookie-octets: nothing that ends the value early, splits
        // the header or needs quoting.
        if (preg_match('/^[\x21\x23-\x2b\x2d-\x3a\x3c-\x5b\x5d-\x7e]*\z/', $value) !== 1) {
            throw new \InvalidArgumentException("Cookie '{$name}' has a value with characters a cookie cannot carry.");
        }

        if ($maxAge !== null && $maxAge < 0) {
            throw new \InvalidArgumentException("Cookie '{$name}' cannot have a negative Max-Age.");
        }

        $clone = clone $this;
        $clone->cookies[$name] = "{$name}={$value}; Path=/; Secure"
            . ($httpOnly ? '; HttpOnly' : '')
            . '; SameSite=Lax'
            . ($maxAge === null ? '' : "; Max-Age={$maxAge}");

        return $clone;
    }

    /**
     * The response, setting a cookie for one path alone: `__Secure-`,
     * HttpOnly, SameSite=Strict.
     *
     * For a credential something other than the application reads at its
     * own path -- the live hub's access token, which the hub reads at
     * /.well-known/mercure. Path=/ would send it with every request the
     * application serves, where nothing reads it and it only adds bytes;
     * and `__Host-` requires Path=/, so the prefix is `__Secure-`, which
     * still keeps a plain-http response from setting it. Strict, because
     * the only request that carries it is the client's own, from this
     * origin.
     */
    public function withPathCookie(string $name, string $value, string $path, ?int $maxAge = null): self
    {
        if (preg_match('/^__Secure-[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name) !== 1) {
            throw new \InvalidArgumentException('A path cookie name must be a __Secure- prefixed token.');
        }

        if (preg_match('/^\/[\x21-\x3a\x3c-\x7e]*\z/', $path) !== 1) {
            throw new \InvalidArgumentException("Cookie '{$name}' needs an absolute path with nothing a cookie attribute cannot carry.");
        }

        if (preg_match('/^[\x21\x23-\x2b\x2d-\x3a\x3c-\x5b\x5d-\x7e]*\z/', $value) !== 1) {
            throw new \InvalidArgumentException("Cookie '{$name}' has a value with characters a cookie cannot carry.");
        }

        if ($maxAge !== null && $maxAge < 0) {
            throw new \InvalidArgumentException("Cookie '{$name}' cannot have a negative Max-Age.");
        }

        $clone = clone $this;
        $clone->cookies[$name] = "{$name}={$value}; Path={$path}; Secure; HttpOnly; SameSite=Strict"
            . ($maxAge === null ? '' : "; Max-Age={$maxAge}");

        return $clone;
    }

    /**
     * Every cookie another response sets, set on this one too -- for a
     * response that replaces another, as a translated redirect does, and
     * must not drop what it was carrying.
     */
    public function withCookiesOf(self $other): self
    {
        $clone = clone $this;
        $clone->cookies = [...$this->cookies, ...$other->cookies];

        return $clone;
    }

    /** @return array<string, string> Set-Cookie lines, by cookie name */
    public function cookies(): array
    {
        return $this->cookies;
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
        // What body() already buffered, if anything did: running the
        // producer again would send the client a second render, after a
        // logger or a middleware had read the first.
        if ($this->buffered !== null) {
            if ($this->buffered !== '') {
                $sink($this->buffered);
            }

            return;
        }

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
