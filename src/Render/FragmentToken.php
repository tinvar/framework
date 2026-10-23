<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Support\Signer;

/**
 * The signed identity of one deferred fragment.
 *
 * tinvar-spec.md, "Deferred fragment tokens": "A fragment is identified by one
 * MAC'd token in the path, `/_fragment/{token}`, with no separate props
 * parameter. The token covers the component ID, props, originating route and
 * params, panel, build ID, a hash of the session ID and an expiry."
 *
 * One token, everything inside it. A separate props parameter would be a
 * parameter an attacker controls: the whole point is that the props the
 * fragment renders with are the props the page was rendered with.
 *
 * What the signature does and does not mean, from the spec's rules:
 *
 *   "Props are signed, not trusted for access. Queries still authorize against
 *    the current user; the signature only stops parameter tampering."
 *
 * So a valid token proves this server issued these props for this route. It
 * proves nothing about whether the caller may see the result, which is why
 * verification is followed by the originating route's full #[Authorize] chain
 * rather than standing in for it. A token stolen from page source is a token
 * for a page its holder could already read.
 *
 * The session binding is a HASH of the session id, not the id: the token is
 * readable in page source, and a token that carried a usable session id would
 * be a session-fixation primitive sitting in the DOM.
 */
final readonly class FragmentToken
{
    public const string PURPOSE = 'fragment-token';
    public const string VERSION = 'v1';

    /** One hour, as the spec specifies; each response returns a refreshed one. */
    public const int DEFAULT_TTL = 3600;

    /**
     * @param array<string, mixed> $props  the component's props, as rendered
     * @param array<string, string> $params the originating route's parameters
     */
    public function __construct(
        public string $component,
        public array $props,
        public string $route,
        public array $params,
        public string $panel,
        public string $build,
        public string $sessionHash,
        public int $expiresAt,
    ) {}

    /**
     * The session binding: a hash, never the id itself.
     *
     * Keyed through the signer so the hash cannot be precomputed or matched
     * against a session id obtained some other way.
     */
    public static function hashSession(Signer $signer, string $sessionId): string
    {
        return bin2hex(substr($signer->sign('session:' . $sessionId), 0, 16));
    }

    /** `v1.<payload>.<mac>`, URL-safe. */
    public function encode(Signer $signer): string
    {
        $payload = Signer::encode(json_encode([
            'c' => $this->component,
            'p' => $this->props,
            'r' => $this->route,
            'a' => $this->params,
            'n' => $this->panel,
            'b' => $this->build,
            's' => $this->sessionHash,
            'e' => $this->expiresAt,
        ], JSON_THROW_ON_ERROR));

        // The version is inside the signed string, not merely prefixed to it:
        // otherwise a token could be replayed under a later version whose
        // fields mean something else.
        return self::VERSION . '.' . $payload . '.' . Signer::encode(
            $signer->sign(self::VERSION . '.' . $payload)
        );
    }

    /**
     * Verifies and decodes a token.
     *
     * Every failure is the same failure to the caller -- FragmentTokenError
     * carries a reason for the log, and the endpoint answers one status for
     * all of them. A caller that could tell "expired" from "wrong session"
     * from "bad signature" could use the difference to probe.
     *
     * @throws FragmentTokenError
     */
    public static function decode(string $token, Signer $signer, ?int $now = null): self
    {
        $parts = explode('.', $token);
        if (\count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new FragmentTokenError('malformed or unknown token version');
        }

        [$version, $payload, $mac] = $parts;

        $signature = Signer::decode($mac);

        // Compared as the CANONICAL encoding, not just as bytes. base64url
        // leaves slack in the final character, so several distinct token
        // STRINGS decode to one MAC; accepting all of them is not a forgery,
        // but it defeats anything that keys on the token string.
        if ($signature !== null && Signer::encode($signature) !== $mac) {
            throw new FragmentTokenError('token is not canonically encoded');
        }

        if ($signature === null || !$signer->verify($version . '.' . $payload, $signature)) {
            // Checked BEFORE the payload is parsed: everything after this
            // point treats the contents as data this server issued, and
            // parsing first would mean parsing attacker-controlled JSON.
            throw new FragmentTokenError('signature does not verify');
        }

        $json = Signer::decode($payload);
        if ($json === null) {
            throw new FragmentTokenError('payload is not valid encoding');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FragmentTokenError('payload is not valid JSON');
        }

        if (!\is_array($decoded)) {
            throw new FragmentTokenError('payload is not an object');
        }

        $self = new self(
            component: self::string($decoded, 'c'),
            props: self::map($decoded, 'p'),
            route: self::string($decoded, 'r'),
            params: self::params($decoded),
            panel: self::string($decoded, 'n'),
            build: self::string($decoded, 'b'),
            sessionHash: self::string($decoded, 's'),
            expiresAt: \is_int($decoded['e'] ?? null) ? $decoded['e'] : 0,
        );

        $at = $now ?? time();

        if ($self->expiresAt <= $at) {
            throw new FragmentTokenError('expired');
        }

        // Only the mint side applies the TTL, so nothing stopped a token
        // claiming PHP_INT_MAX from being honoured forever. The reader
        // enforces it too: a token valid for longer than one may be issued
        // for was not issued by any path this framework offers.
        if ($self->expiresAt - $at > self::DEFAULT_TTL) {
            throw new FragmentTokenError('expiry is further out than a token may be issued for');
        }

        return $self;
    }

    /** A token for the same fragment, valid for another TTL. */
    public function refreshed(int $ttl = self::DEFAULT_TTL, ?int $now = null): self
    {
        return new self(
            component: $this->component,
            props: $this->props,
            route: $this->route,
            params: $this->params,
            panel: $this->panel,
            build: $this->build,
            sessionHash: $this->sessionHash,
            expiresAt: ($now ?? time()) + $ttl,
        );
    }

    /** Whether this token belongs to the session making the request. */
    public function belongsTo(Signer $signer, string $sessionId): bool
    {
        return hash_equals($this->sessionHash, self::hashSession($signer, $sessionId));
    }

    /** @param array<string, mixed> $decoded */
    private static function string(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;
        if (!\is_string($value)) {
            throw new FragmentTokenError("field '{$key}' is missing or not a string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private static function map(array $decoded, string $key): array
    {
        $value = $decoded[$key] ?? [];

        // A JSON list passes is_array() and then breaks the array<string,
        // mixed> contract everything downstream was promised.
        if (!\is_array($value) || array_is_list($value) && $value !== []) {
            throw new FragmentTokenError("field '{$key}' is not an object");
        }

        return $value;
    }

    /**
     * Route parameters, VALIDATED rather than coerced.
     *
     * This was array_map(strval(...)), which turned true into "1", null into
     * "" and a nested array into the literal "Array" -- with a PHP warning
     * that any handler promoting warnings to exceptions would turn into a 500,
     * escaping the one-status rule. A parameter that is not a scalar is a
     * token this server will not act on, like every other malformed field.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, string>
     */
    private static function params(array $decoded): array
    {
        $params = [];

        foreach (self::map($decoded, 'a') as $name => $value) {
            if (!\is_string($value) && !\is_int($value)) {
                throw new FragmentTokenError("param '{$name}' is not a scalar");
            }

            $params[(string) $name] = (string) $value;
        }

        return $params;
    }
}
