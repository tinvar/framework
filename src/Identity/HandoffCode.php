<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * The one-time code the platform host hands a signed-in user's browser to
 * carry to another realm, where it becomes a new session.
 *
 * Signed in the envelope every token shares (SignedToken), under a purpose
 * and a key of its own, and bound to everything its redemption depends on:
 *
 * - the target **origin**, exactly, so a code for one domain is nothing on
 *   another -- not on the platform host, not on another domain of the same
 *   tenant, and not on a domain a different tenant controls;
 * - the target realm's **tenant** and **panel**, the ones its membership
 *   was checked in when the code was minted;
 * - the **user**, by id, and their **auth epoch** as a keyed hash (as route
 *   tokens carry it): a sign-out, a role change or impersonation in the
 *   minute it is in flight -- each moves the epoch -- spends it;
 * - the **PKCE challenge** and **state** of the session that asked for it on
 *   the target (Pkce), so only that browser can redeem it;
 * - where to go afterwards, a path on the target, decided when the handoff
 *   started and carried under the MAC;
 * - an **id**, claimed once in the nonce store on redemption; and an
 *   **expiry** a minute out, with no refresh.
 *
 * Not the build: a code names no route, component or payload whose meaning
 * a deploy could change, and a deploy in the minute it is in flight should
 * not sign anyone out.
 */
final readonly class HandoffCode
{
    /** The SignedToken purpose, and the Signer purpose its key is derived for. */
    public const string PURPOSE = 'handoff-code';

    /**
     * Seconds a code lives. Two redirects, the second straight after the
     * first: a code still unredeemed a minute on was lifted from somewhere.
     */
    public const int TTL = 60;

    /** Seconds a code's issue time may be ahead of this server's clock. */
    public const int SKEW = 30;

    /** The longest code read: a URL parameter, not a payload. */
    public const int MAX_LENGTH = 4096;

    /** The id's shape: 128 random bits, base64url. */
    private const string ID = '/^[A-Za-z0-9_-]{22}\z/';

    /** A challenge's shape: a SHA-256, base64url. */
    public const string CHALLENGE = '/^[A-Za-z0-9_-]{43}\z/';

    /** A state's shape: what HandoffEndpoint::start() makes, and no longer. */
    public const string STATE = '/^[A-Za-z0-9_-]{22,64}\z/';

    public function __construct(
        public string $origin,
        public string $tenant,
        public string $panel,
        public string $user,
        public string $authHash,
        public string $challenge,
        public string $state,
        public string $returnTo,
        public string $id,
        public int $issuedAt,
        public int $expiresAt,
    ) {}

    /** A new code for $user at $epoch, into $target, for the session that made $challenge. */
    public static function mint(
        Signer $signer,
        Realm $target,
        string $user,
        string $epoch,
        string $challenge,
        string $state,
        string $returnTo,
        int $now,
    ): self {
        return new self(
            origin: $target->origin,
            tenant: $target->tenant,
            panel: $target->panel,
            user: $user,
            authHash: TokenClaims::hashAuth($signer, $user, $epoch),
            challenge: $challenge,
            state: $state,
            returnTo: $returnTo,
            id: Signer::encode(random_bytes(16)),
            issuedAt: $now,
            expiresAt: $now + self::TTL,
        );
    }

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            'o' => $this->origin,
            'y' => $this->tenant,
            'n' => $this->panel,
            'u' => $this->user,
            'h' => $this->authHash,
            'c' => $this->challenge,
            's' => $this->state,
            'r' => $this->returnTo,
            'j' => $this->id,
            'i' => $this->issuedAt,
            'e' => $this->expiresAt,
        ]);
    }

    /**
     * The code, verified and fresh. Its binding -- origin, tenant, panel,
     * session, user and epoch -- is the redeemer's to check against the
     * request; this says only that this server minted it, lately.
     *
     * @throws TokenError
     */
    public static function decode(string $code, Signer $signer, int $now): self
    {
        if (\strlen($code) > self::MAX_LENGTH) {
            throw new TokenError('longer than any code minted');
        }

        $map = SignedToken::decode($code, $signer, self::PURPOSE);

        $string = static function (string $key) use ($map): string {
            $value = $map[$key] ?? null;
            if (!\is_string($value)) {
                throw new TokenError("field '{$key}' is missing or not a string");
            }

            return $value;
        };
        $int = static function (string $key) use ($map): int {
            $value = $map[$key] ?? null;
            if (!\is_int($value) || $value <= 0) {
                throw new TokenError("field '{$key}' is missing or not a time");
            }

            return $value;
        };

        $code = new self(
            origin: $string('o'),
            tenant: $string('y'),
            panel: $string('n'),
            user: $string('u'),
            authHash: $string('h'),
            challenge: $string('c'),
            state: $string('s'),
            returnTo: $string('r'),
            id: $string('j'),
            issuedAt: $int('i'),
            expiresAt: $int('e'),
        );

        // Shapes, though the MAC says this server wrote them: a reader that
        // trusted them anyway would be one minting bug from claiming a
        // nonce of any length, or comparing a challenge of none.
        if (preg_match(self::ID, $code->id) !== 1
            || preg_match(self::CHALLENGE, $code->challenge) !== 1
            || preg_match(self::STATE, $code->state) !== 1
            || $code->user === ''
            || Realm::hostOf($code->origin) === null) {
            throw new TokenError('a field is not the shape a minted code has');
        }

        if ($code->issuedAt > $now + self::SKEW) {
            throw new TokenError('issued in the future');
        }
        if ($code->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        // Only minting applies the TTL, so nothing else stops a code
        // claiming a far expiry from living that long.
        if ($code->expiresAt - $code->issuedAt > self::TTL) {
            throw new TokenError('lives longer than a code is minted for');
        }

        return $code;
    }
}
