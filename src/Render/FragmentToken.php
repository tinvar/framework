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
 *
 * The envelope and the binding fields are SHARED with the route token --
 * SignedToken and TokenClaims -- rather than reimplemented here. They were
 * extracted beside this class and this class was not retrofitted onto them,
 * which left two implementations of one format: the checks happened to match
 * line for line, but what they CARRIED did not, and a route payload turned
 * out to be a strict subset of this one. With no type bound into the MAC,
 * every fragment token decoded cleanly as a route token. One envelope, one
 * purpose in the signature, and that cannot recur.
 *
 * The TENANT is carried for the same reason as the panel, and was missing for
 * longer. "Client-held data is bound to (user, tenant) ... any mismatch
 * between stored and current user or tenant also purges it." A fragment token
 * is client-held data with a one-day ceiling and a refresh chain inside it, so
 * without a tenant it asserts "this session was entitled to this route and
 * these props" across a tenant switch or a revoked membership. Tenant
 * resolution does not exist yet, so there is nothing to exploit today -- which
 * is precisely why the field goes in now: adding one to a signed format later
 * is a skew event.
 */
final readonly class FragmentToken
{
    public const string PURPOSE = 'fragment-token';

    /** One hour, as the spec specifies; each response returns a refreshed one. */
    public const int DEFAULT_TTL = 3600;

    /**
     * How long a token may live across refreshes, from its FIRST mint.
     *
     * Each response returns a refreshed token with a full TTL, so without an
     * absolute deadline a boundary that refreshes on a timer carries its
     * original assertions -- the session it was minted for, and the panel
     * that session was in -- for as long as anyone keeps asking. "One hour by
     * default" would then describe a value that never expires.
     */
    public const int MAX_LIFETIME = 86400;

    /**
     * @param array<string, mixed>  $props  the component's props, as rendered
     * @param array<string, string> $params the originating route's parameters
     */
    public function __construct(
        public string $component,
        public array $props,
        public string $route,
        public array $params,
        public string $panel,
        public string $tenant,
        public string $build,
        public string $sessionHash,
        public int $expiresAt,
        public int $issuedAt,
        // Who was signed in, at which auth epoch (TokenClaims::hashAuth()),
        // as a route token says; '' for a token minted before tokens said.
        public string $authHash = '',
    ) {}

    /** @see TokenClaims::hashSession() -- one implementation, both tokens. */
    public static function hashSession(Signer $signer, #[\SensitiveParameter] string $sessionId): string
    {
        return TokenClaims::hashSession($signer, $sessionId);
    }

    /** `v1.<payload>.<mac>`, URL-safe, with the purpose bound into the MAC. */
    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            ...$this->claims()->toMap(),
            'c' => $this->component,
            'p' => $this->props,
        ]);
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
        try {
            $decoded = SignedToken::decode($token, $signer, self::PURPOSE);
            $claims = TokenClaims::fromMap($decoded);
            $claims->assertFresh(self::DEFAULT_TTL, self::MAX_LIFETIME, $now);
        } catch (TokenError $e) {
            // The shared layer speaks TokenError; this layer's callers catch
            // FragmentTokenError, so the reason is carried across rather
            // than flattened.
            throw new FragmentTokenError($e->getMessage(), previous: $e);
        }

        $component = $decoded['c'] ?? null;
        if (!\is_string($component) || $component === '') {
            throw new FragmentTokenError("field 'c' is missing or not a string");
        }

        $props = $decoded['p'] ?? [];
        if (!\is_array($props) || (array_is_list($props) && $props !== [])) {
            throw new FragmentTokenError("field 'p' is not an object");
        }

        return self::fromClaims($claims, $component, $props);
    }

    /**
     * A token for the same fragment, valid for another TTL.
     *
     * Null once the absolute lifetime is spent. This is a LIVE path -- every
     * fragment response returns a refreshed token -- and without the clamp a
     * boundary near the ceiling was handed one that failed within seconds,
     * indistinguishably from a forgery.
     */
    public function refreshed(int $ttl = self::DEFAULT_TTL, ?int $now = null): ?self
    {
        $claims = $this->claims()->refreshed($ttl, self::MAX_LIFETIME, $now);

        return $claims === null ? null : self::fromClaims($claims, $this->component, $this->props);
    }

    /** Whether this token belongs to the session making the request. */
    public function belongsTo(Signer $signer, #[\SensitiveParameter] string $sessionId): bool
    {
        return $this->claims()->belongsTo($signer, $sessionId);
    }

    public function claims(): TokenClaims
    {
        return new TokenClaims(
            route: $this->route,
            params: $this->params,
            panel: $this->panel,
            tenant: $this->tenant,
            build: $this->build,
            sessionHash: $this->sessionHash,
            expiresAt: $this->expiresAt,
            issuedAt: $this->issuedAt,
            authHash: $this->authHash,
        );
    }

    /** @param array<string, mixed> $props */
    private static function fromClaims(TokenClaims $claims, string $component, array $props): self
    {
        return new self(
            component: $component,
            props: $props,
            route: $claims->route,
            params: $claims->params,
            panel: $claims->panel,
            tenant: $claims->tenant,
            build: $claims->build,
            sessionHash: $claims->sessionHash,
            expiresAt: $claims->expiresAt,
            issuedAt: $claims->issuedAt,
            authHash: $claims->authHash,
        );
    }
}
