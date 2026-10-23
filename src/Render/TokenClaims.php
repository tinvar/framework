<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Support\Signer;

/**
 * The binding fields every signed token this framework issues carries, and
 * the checks they must survive.
 *
 * There are two kinds of signed token — a fragment token and a route token —
 * and the spec gives them the same binding: "route, params, panel, session
 * hash", plus the build and the tenant that client-held data is scoped to.
 * Only what they ADDRESS differs: a fragment names a component and props, a
 * route names nothing further.
 *
 * They live here together because the alternative is two copies of the same
 * security reasoning, and this phase has supplied repeated evidence of what
 * happens to two copies. The panel check was written for fragments and the
 * tenant check was not; the tenant check was then written and wired to
 * nothing; the minting side read one source and the verifying side another.
 * Each was a divergence between two places that should have been one.
 *
 * So: one place decides what a token is bound to, one place decides what
 * makes it invalid, and a new kind of token inherits both rather than
 * reimplementing them.
 */
final readonly class TokenClaims
{
    /** @param array<string, string> $params */
    public function __construct(
        public string $route,
        public array $params,
        public string $panel,
        public string $tenant,
        public string $build,
        public string $sessionHash,
        public int $expiresAt,
        public int $issuedAt,
    ) {}

    /**
     * The session binding: a hash, never the id.
     *
     * Keyed through the signer, so it cannot be precomputed or matched
     * against a session id obtained some other way. A token is readable in
     * page source, and one carrying a usable session id would be a
     * session-fixation primitive sitting in the DOM.
     */
    public static function hashSession(Signer $signer, string $sessionId): string
    {
        return bin2hex(substr($signer->sign('session:' . $sessionId), 0, 16));
    }

    /** @return array<string, mixed> the signed map, by short key */
    public function toMap(): array
    {
        return [
            'r' => $this->route,
            'a' => $this->params,
            'n' => $this->panel,
            'y' => $this->tenant,
            'b' => $this->build,
            's' => $this->sessionHash,
            'e' => $this->expiresAt,
            'i' => $this->issuedAt,
        ];
    }

    /**
     * Reads the claims out of a decoded payload.
     *
     * @param array<string, mixed> $decoded
     * @throws TokenError
     */
    public static function fromMap(array $decoded): self
    {
        return new self(
            route: self::string($decoded, 'r'),
            params: self::params($decoded),
            panel: self::string($decoded, 'n'),
            tenant: self::string($decoded, 'y'),
            build: self::string($decoded, 'b'),
            sessionHash: self::string($decoded, 's'),
            expiresAt: \is_int($decoded['e'] ?? null) ? $decoded['e'] : 0,
            issuedAt: \is_int($decoded['i'] ?? null) ? $decoded['i'] : 0,
        );
    }

    /**
     * Everything that makes a token too old to act on.
     *
     * @throws TokenError
     */
    public function assertFresh(int $ttl, int $maxLifetime, ?int $now = null): void
    {
        $at = $now ?? time();

        // The malformed check first: a token with no issue time that is
        // ALSO expired should be reported as malformed, which is the more
        // specific cause and the one the log exists for.
        if ($this->issuedAt <= 0) {
            throw new TokenError('token carries no issue time');
        }

        if ($this->expiresAt <= $at) {
            throw new TokenError('expired');
        }

        // Only the mint side applies the TTL, so nothing else stops a token
        // claiming PHP_INT_MAX from being honoured forever.
        if ($this->expiresAt - $at > $ttl) {
            throw new TokenError('expiry is further out than a token may be issued for');
        }

        // The same half-open lifetime refreshed() applies: a token is dead
        // from its deadline on, not one second after it.
        if ($at - $this->issuedAt >= $maxLifetime) {
            throw new TokenError('token is older than one may live across refreshes');
        }
    }

    public function belongsTo(Signer $signer, string $sessionId): bool
    {
        return hash_equals($this->sessionHash, self::hashSession($signer, $sessionId));
    }

    /**
     * The same claims, valid for another window, CLAMPED to the deadline.
     *
     * The issue time is kept, which is the point -- refreshing must not
     * extend the absolute lifetime. But nothing clamped the new expiry, so
     * a token issued just under the ceiling was handed back with a nominal
     * hour of life and became undecodable within seconds, failing
     * indistinguishably from a forgery. Null says "re-bootstrap", which the
     * caller can act on.
     */
    public function refreshed(int $ttl, int $maxLifetime, ?int $now = null): ?self
    {
        $at = $now ?? time();
        $deadline = $this->issuedAt + $maxLifetime;

        if ($deadline <= $at) {
            return null;
        }

        return new self(
            route: $this->route,
            params: $this->params,
            panel: $this->panel,
            tenant: $this->tenant,
            build: $this->build,
            sessionHash: $this->sessionHash,
            expiresAt: min($at + $ttl, $deadline),
            issuedAt: $this->issuedAt,
        );
    }

    /** @param array<string, mixed> $decoded */
    private static function string(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;
        if (!\is_string($value)) {
            throw new TokenError("field '{$key}' is missing or not a string");
        }

        return $value;
    }

    /**
     * Route parameters, VALIDATED rather than coerced.
     *
     * strval turned true into "1", null into "" and a nested array into the
     * literal "Array" -- with a warning that any handler promoting warnings
     * turns into a 500, escaping the one-status rule these tokens depend on.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, string>
     */
    private static function params(array $decoded): array
    {
        $value = $decoded['a'] ?? [];
        if (!\is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new TokenError("field 'a' is not an object");
        }

        $params = [];
        foreach ($value as $name => $one) {
            if (!\is_string($one) && !\is_int($one)) {
                throw new TokenError("param '{$name}' is not a scalar");
            }

            $params[(string) $name] = (string) $one;
        }

        return $params;
    }
}
