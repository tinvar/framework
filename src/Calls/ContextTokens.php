<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Http\BuildRegistry;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

/**
 * Issues and opens one kind of ContextToken: a tool grant, or a service
 * context token, by the purpose it is built for.
 *
 * The envelope is every signed token's (SignedToken): `v1.<payload>.<mac>`,
 * the version and the purpose inside the MAC, the MAC checked before the
 * payload is parsed. The key is derived from the application key for the
 * purpose alone, so a token of one kind verifies as no other -- not a route
 * token, not a fragment token, not the other kind of context token.
 *
 * Opening one checks what holds whoever presents it: the MAC, its shape,
 * its lifetime -- issued no later than now, not yet expired, and never for
 * longer than this kind may be issued for, so a token claiming to last a
 * year is refused rather than honoured -- and its build, which must be one
 * this server still answers for, so a revoked build's tokens die with it.
 * What it is bound to beyond that -- the user and their epoch, the tenant,
 * the panel, the operation -- is checked where the call is made, against
 * the context it is made in.
 */
final readonly class ContextTokens
{
    /** The longest token string opened: the claims fit in a fraction of it. */
    public const int MAX_LENGTH = 8_192;

    private Signer $signer;

    /**
     * @param string $purpose what kind of token, bound into the key and the MAC
     * @param int $ttl seconds a token lasts unless issued for less
     * @param int $maxTtl the longest any token of this kind may last
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        #[\SensitiveParameter]
        string $key,
        private string $purpose,
        private int $ttl,
        private int $maxTtl,
        private BuildRegistry $builds,
        private ?\Closure $clock = null,
    ) {
        if ($ttl < 1 || $ttl > $maxTtl) {
            throw new \InvalidArgumentException("A {$purpose} lasts at least a second and at most {$maxTtl}.");
        }
        $this->signer = new Signer($key, $purpose);
    }

    /**
     * A token for these operations, in this tenant and panel, for this user
     * on this epoch.
     *
     * @param list<string> $operations
     * @param int|null $ttl seconds it lasts; this kind's default unless given, and never past its ceiling
     * @param bool $singleUse whether its id is to be spent at its first use, by whoever checks it
     */
    public function issue(string $audience, array $operations, string $tenant, string $panel, ?string $user, string $epoch, ?int $ttl = null, bool $singleUse = false): string
    {
        $operations = array_values(array_unique($operations));
        if ($operations === [] || \count($operations) > ContextToken::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('A ' . $this->purpose . ' names between 1 and ' . ContextToken::MAX_OPERATIONS . ' operations.');
        }
        $ttl ??= $this->ttl;
        if ($ttl < 1 || $ttl > $this->maxTtl) {
            throw new \InvalidArgumentException("A {$this->purpose} lasts at least a second and at most {$this->maxTtl}.");
        }

        $now = $this->now();

        return SignedToken::encode($this->signer, $this->purpose, new ContextToken(
            id: Uuid::v7(),
            audience: $audience,
            operations: $operations,
            tenant: $tenant,
            panel: $panel,
            user: $user ?? '',
            authHash: $user === null ? '' : $this->authHash($user, $epoch),
            build: $this->builds->current,
            issuedAt: $now,
            expiresAt: $now + $ttl,
            singleUse: $singleUse,
        )->toMap());
    }

    /**
     * The claims a token carries, once everything about it that does not
     * depend on where it is used has been checked.
     *
     * @throws TokenError
     */
    public function open(string $token): ContextToken
    {
        if (\strlen($token) > self::MAX_LENGTH) {
            throw new TokenError('token is longer than any this server issues');
        }

        $claims = ContextToken::fromMap(SignedToken::decode($token, $this->signer, $this->purpose));
        $now = $this->now();

        if ($claims->issuedAt > $now + TokenClaims::CLOCK_SKEW) {
            throw new TokenError('issued in the future');
        }
        if ($claims->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        if ($claims->expiresAt - $claims->issuedAt > $this->maxTtl || $claims->expiresAt - $now > $this->maxTtl + TokenClaims::CLOCK_SKEW) {
            throw new TokenError('expiry is further out than a token may be issued for');
        }
        if (!$this->builds->accepts($claims->build)) {
            throw new TokenError('issued by a build this server no longer answers for');
        }

        return $claims;
    }

    /** The keyed hash of a user and their auth epoch that a token binds. */
    public function authHash(string $user, string $epoch): string
    {
        return TokenClaims::hashAuth($this->signer, $user, $epoch);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
