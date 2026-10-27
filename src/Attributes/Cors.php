<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Lets the pages of other origins named here call an endpoint, and read
 * what it answers (ADR 0074).
 *
 *     #[Get('/api/products/{product}/stock'), PublicAccess,
 *         Cors(origins: ['https://partner.example'])]
 *
 * Opt-in, per endpoint, and only by name: every origin is written out in
 * full -- `https://partner.example`, `http://localhost:5173` -- and a
 * request's Origin header is echoed back only when it is one of them,
 * byte for byte. There is no `*` and no pattern, so no Origin a client
 * makes up is ever reflected. A plain `http:` origin is refused unless it
 * is the loopback: anyone on the network between that origin's visitors
 * and it could put script on it, and the script would read what this
 * endpoint answers.
 *
 * `credentials: true` sends Access-Control-Allow-Credentials, so a page on
 * one of the origins may call with the visitor's cookies and read the
 * answer. It opens reading, never writing: a write still needs the CSRF
 * token, which no other origin can read -- it is a `__Host-` cookie -- so a
 * credentialed write from another origin is refused as any forged one is.
 * An API another origin writes to is a #[WithoutSession] one, with a token
 * of its own, which reads no cookie and so takes no credentials: the two
 * are refused together.
 *
 * Only an endpoint reads it. A preflight (OPTIONS) is answered for one of
 * the named origins, asking for a method the endpoint answers and headers
 * it allows; any other OPTIONS is the 405 it always was.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Cors
{
    /** How long a browser may keep a preflight's answer, at most: Chrome's own cap. */
    public const int MAX_AGE = 7200;

    /** Headers a cross-origin call may always send: a JSON body's type, and what it accepts. */
    public const array ALWAYS_ALLOWED_HEADERS = ['accept', 'content-type'];

    /** @var non-empty-list<string> */
    public array $origins;

    /** @var list<string> lowercased */
    public array $headers;

    /**
     * @param list<string> $origins every origin allowed, serialized as a browser sends it
     * @param bool $credentials whether a call may carry the visitor's cookies
     * @param list<string> $headers request headers allowed beyond Accept and Content-Type,
     *        as `authorization` for a bearer token
     * @param int $maxAge seconds a browser may keep a preflight's answer
     */
    public function __construct(
        array $origins,
        public bool $credentials = false,
        array $headers = [],
        public int $maxAge = 600,
    ) {
        $normalized = [];
        foreach ($origins as $origin) {
            self::assertOrigin($origin);
            $normalized[] = $origin;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('#[Cors] names no origin. Name each origin allowed; there is no wildcard.');
        }
        $this->origins = array_values(array_unique($normalized));

        $allowed = [];
        foreach ($headers as $header) {
            $lower = strtolower(trim($header));
            if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $lower) !== 1) {
                throw new \InvalidArgumentException("#[Cors] allows the header '{$header}', which is no header name.");
            }
            $allowed[] = $lower;
        }
        $this->headers = array_values(array_unique([...self::ALWAYS_ALLOWED_HEADERS, ...$allowed]));

        if ($maxAge < 0 || $maxAge > self::MAX_AGE) {
            throw new \InvalidArgumentException('#[Cors] keeps a preflight for ' . $maxAge . ' seconds; between 0 and ' . self::MAX_AGE . '.');
        }
    }

    /** Whether $origin -- a request's Origin header, as sent -- is one of those named. */
    public function allows(?string $origin): bool
    {
        return $origin !== null && \in_array($origin, $this->origins, true);
    }

    /**
     * Whether every header a preflight asks to send is allowed; a header
     * nobody named is refused, rather than the preflight answered for the
     * others.
     */
    public function allowsHeaders(?string $requested): bool
    {
        if ($requested === null || trim($requested) === '') {
            return true;
        }
        foreach (explode(',', $requested) as $header) {
            if (!\in_array(strtolower(trim($header)), $this->headers, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The policy an endpoint declares: its method's, else its class's; null
     * for none. Off the class itself, never an ancestor's: inheriting a way
     * in is the fail-open direction.
     */
    public static function declaredOn(string $class, string $method): ?self
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        $attribute = ($reflection->hasMethod($method) ? $reflection->getMethod($method)->getAttributes(self::class)[0] ?? null : null)
            ?? $reflection->getAttributes(self::class)[0] ?? null;

        return $attribute?->newInstance();
    }

    /**
     * An origin as a browser serializes one: a scheme, a lowercase host and
     * a port only when it is not the scheme's own -- nothing after. Anything
     * else would never equal an Origin header, and an author who wrote it
     * meant something this cannot do.
     */
    private static function assertOrigin(string $origin): void
    {
        if ($origin === '*' || $origin === 'null') {
            throw new \InvalidArgumentException(
                "#[Cors] names the origin '{$origin}'. Name each origin allowed in full: a wildcard, or the opaque `null` "
                . 'origin a sandboxed frame or a file sends, lets any page read what the endpoint answers.',
            );
        }

        if (preg_match('#^(https?)://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:.]+\])(?::([0-9]{1,5}))?$#D', $origin, $parts) !== 1) {
            throw new \InvalidArgumentException(
                "#[Cors] names '{$origin}', which is no origin as a browser sends one: a scheme, a lowercase host and a "
                . 'port, with no path and no trailing slash, as in https://partner.example.',
            );
        }

        $port = $parts[3] ?? '';
        if ($port !== '' && ((int) $port > 65535 || ($parts[1] === 'https' && $port === '443') || ($parts[1] === 'http' && $port === '80'))) {
            throw new \InvalidArgumentException("#[Cors] names '{$origin}', whose port a browser never writes. Leave out the scheme's own port.");
        }

        if ($parts[1] === 'http' && !\in_array($parts[2], ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new \InvalidArgumentException(
                "#[Cors] names '{$origin}', over plain http. Anyone between its visitors and it could put script on it that "
                . 'reads what this endpoint answers: allow its https origin.',
            );
        }
    }
}
