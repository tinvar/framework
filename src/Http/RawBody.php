<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The exact bytes an endpoint's request body arrived as (ADR 0183).
 *
 * A caller that signs its body -- a node agent's ECDSA over
 * `METHOD\npath\nts\nhex(sha256(body))`, a partner's HMAC -- signed these
 * bytes, not what decoding them builds: JSON's spacing, key order and
 * escapes do not survive a decode. So an endpoint may take one as a
 * parameter, and its own #[Middleware] reads the same one with of(), to
 * check a signature before validation and the method run.
 *
 * Only an endpoint is handed one. An action or a query is called by the
 * application's own pages, with the session's CSRF token, and its body is
 * the framework's protocol; an endpoint is what another server calls.
 *
 * Held to the endpoint body limit before the endpoint's middleware runs,
 * so nothing reads more than the limit. A dump shows its length, never its
 * bytes: a body is a caller's data, and may be a secret.
 */
final readonly class RawBody
{
    /** The request attribute an endpoint's middleware finds it under. */
    public const string ATTRIBUTE = 'tinvar.raw_body';

    public function __construct(
        #[\SensitiveParameter]
        public string $bytes,
    ) {}

    /**
     * The body an endpoint's request arrived as; null outside an endpoint's
     * own stack, and for a body whose bytes PHP consumed parsing it -- a
     * multipart form -- so a check that needs them fails closed rather
     * than verifying an empty string.
     */
    public static function of(Request $request): ?self
    {
        $body = $request->attribute(self::ATTRIBUTE);

        return $body instanceof self ? $body : null;
    }

    public function length(): int
    {
        return \strlen($this->bytes);
    }

    /** The bytes' digest, lowercase hex: `sha256` unless another hash_algos() name is given. */
    public function hash(string $algorithm = 'sha256'): string
    {
        return hash($algorithm, $this->bytes);
    }

    /** @return array{length: int} */
    public function __debugInfo(): array
    {
        return ['length' => $this->length()];
    }
}
