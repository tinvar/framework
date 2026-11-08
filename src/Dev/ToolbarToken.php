<?php

declare(strict_types=1);

namespace Tinvar\Dev;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * What a page's dev toolbar shows the endpoint, so it may read back the
 * requests of the visitor it was drawn for: the spec's "per-run token".
 *
 * Signed with the key `tinvar serve --dev` made for this run (DevToolbar), so a
 * token from an earlier run, or one any other process wrote, does not
 * verify. Bound as every token is (TokenClaims): the session and the
 * signed-in user with their auth epoch, as keyed hashes, the build, and
 * the host the page was drawn on (ADR 0149) -- read when the page was
 * drawn, and checked against the request presenting it. A token taken
 * from one browser's page reads nothing in another, nor on another host;
 * and signing in, signing out or an impersonation that keeps the session
 * id stops the old page's token from reading what the new one is shown.
 *
 * Not bound to a tenant or a panel: the endpoint runs behind the global
 * middleware alone, where neither is resolved -- they are per entry point --
 * and what it reads back is the session's own requests, whichever panel each
 * ran in, never another visitor's.
 */
final readonly class ToolbarToken
{
    public const string PURPOSE = 'dev-toolbar';

    /** A day's work at a desk; the key it is signed with lasts one run of `tinvar serve --dev` regardless. */
    public const int TTL = 43200;

    public function __construct(
        public string $sessionHash,
        public string $authHash,
        public string $build,
        public int $expiresAt,
        public int $issuedAt,
        public string $host,
    ) {}

    /** The binding of a request as it stands: what a page is given, and what a presented token must match. */
    public static function for(
        Signer $signer,
        #[\SensitiveParameter]
        string $sessionId,
        ?string $user,
        string $epoch,
        string $build,
        string $host,
        ?int $now = null,
    ): self {
        $at = $now ?? time();

        return new self(
            TokenClaims::hashSession($signer, $sessionId),
            TokenClaims::hashAuth($signer, $user, $epoch),
            $build,
            $at + self::TTL,
            $at,
            self::hostOf($host),
        );
    }

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            's' => $this->sessionHash,
            'u' => $this->authHash,
            'b' => $this->build,
            'e' => $this->expiresAt,
            'i' => $this->issuedAt,
            'h' => $this->host,
        ]);
    }

    /** @throws TokenError */
    public static function decode(string $token, Signer $signer, ?int $now = null): self
    {
        $decoded = SignedToken::decode($token, $signer, self::PURPOSE);
        // The host with the rest: a token from before it was bound carries
        // none, and is refused -- the page it was drawn into is drawn again.
        foreach (['s', 'u', 'b', 'h'] as $field) {
            if (!\is_string($decoded[$field] ?? null) || $decoded[$field] === '') {
                throw new TokenError("field '{$field}' is missing or not a string");
            }
        }
        $expiresAt = $decoded['e'] ?? null;
        $issuedAt = $decoded['i'] ?? null;
        if (!\is_int($expiresAt) || !\is_int($issuedAt) || $issuedAt <= 0) {
            throw new TokenError('token carries no expiry or issue time');
        }

        $at = $now ?? time();
        if ($expiresAt <= $at) {
            throw new TokenError('expired');
        }
        if ($expiresAt - $issuedAt > self::TTL || $issuedAt > $at + 60) {
            throw new TokenError('token claims a longer life than one is issued for');
        }

        /** @var array{s: string, u: string, b: string, h: string} $decoded */
        return new self($decoded['s'], $decoded['u'], $decoded['b'], $expiresAt, $issuedAt, $decoded['h']);
    }

    /** Whether this token was issued for the binding $current describes. */
    public function admits(self $current): bool
    {
        return hash_equals($current->sessionHash, $this->sessionHash)
            && hash_equals($current->authHash, $this->authHash)
            && hash_equals($current->build, $this->build)
            && $current->host !== ''
            && hash_equals($current->host, $this->host);
    }

    /**
     * The host a token is bound to: the Host header as the request named it,
     * port and all -- the toolbar answers before any host is resolved, and a
     * page and its toolbar's reads share one origin -- lowercased, with no
     * trailing dot.
     */
    public static function hostOf(string $header): string
    {
        $host = strtolower(trim($header));
        if (preg_match('/^(.*?)\.?(:\d{1,5})?\z/', $host, $m) === 1) {
            $host = $m[1] . ($m[2] ?? '');
        }

        return $host;
    }
}
