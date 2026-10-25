<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * The pending handoff a consent screen asks about: what its two forms post
 * back, and what whichever of them is posted spends.
 *
 * The screen is a page on the platform host, and its answer a POST, so what
 * the person was asked about has to survive the round trip without the post
 * being able to say anything else. Everything the code minted on a confirm
 * will carry is in here, under the MAC, and nothing the post carries but
 * this and the decision is read:
 *
 * - the **session** the screen was shown to, as a keyed hash (as route
 *   tokens carry it): a ticket lifted from the page is nothing in another
 *   browser;
 * - the **user** and their **auth epoch**, as one keyed hash: a sign-out, a
 *   sign-in as somebody else, a role change -- each moves the epoch -- in the
 *   minutes the screen is open spends it;
 * - the **destination** -- origin, tenant and panel -- exactly as the screen
 *   named it, so a confirm given for one domain cannot be redeemed for
 *   another;
 * - the target session's **PKCE challenge** and **state**, and the **path**
 *   to go on to, which the code carries on (HandoffCode);
 * - an **id**, claimed once in the nonce store whichever form is posted, and
 *   an **expiry** five minutes out, with no refresh.
 *
 * Signed under its own purpose, so a ticket never reads as a code, nor a
 * code as a ticket, though one signer makes both. Not the build, for the
 * code's reason: it names nothing a deploy could change the meaning of.
 */
final readonly class ConsentTicket
{
    /** The SignedToken purpose, bound into the MAC. */
    public const string PURPOSE = 'handoff-consent';

    /**
     * Seconds a screen may be answered in. Long enough to read two lines and
     * press a button; a screen still open after that is one the person walked
     * away from, and is asked again.
     */
    public const int TTL = 300;

    /** The longest ticket read: a form field, not a payload. */
    public const int MAX_LENGTH = 4096;

    /** The id's shape: 128 random bits, base64url. */
    private const string ID = '/^[A-Za-z0-9_-]{22}\z/';

    /** A keyed hash's shape, as TokenClaims writes one. */
    private const string HASH = '/^[0-9a-f]{32}\z/';

    public function __construct(
        public string $sessionHash,
        public string $authHash,
        public string $origin,
        public string $tenant,
        public string $panel,
        public string $challenge,
        public string $state,
        public string $returnTo,
        public string $id,
        public int $issuedAt,
        public int $expiresAt,
    ) {}

    /** A new ticket for the session $sessionId, signed in as $user at $epoch, asked about $target. */
    public static function mint(
        Signer $signer,
        #[\SensitiveParameter]
        string $sessionId,
        string $user,
        string $epoch,
        Realm $target,
        string $challenge,
        string $state,
        string $returnTo,
        int $now,
    ): self {
        if ($sessionId === '' || $user === '') {
            // A ticket bound to no session, or to nobody, is one anyone holds.
            throw new \InvalidArgumentException('A consent ticket is bound to a session and a user; one was missing.');
        }

        return new self(
            sessionHash: TokenClaims::hashSession($signer, $sessionId),
            authHash: TokenClaims::hashAuth($signer, $user, $epoch),
            origin: $target->origin,
            tenant: $target->tenant,
            panel: $target->panel,
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
            's' => $this->sessionHash,
            'h' => $this->authHash,
            'o' => $this->origin,
            'y' => $this->tenant,
            'n' => $this->panel,
            'c' => $this->challenge,
            't' => $this->state,
            'r' => $this->returnTo,
            'j' => $this->id,
            'i' => $this->issuedAt,
            'e' => $this->expiresAt,
        ]);
    }

    /**
     * The ticket, verified and fresh. Its binding -- session, user and epoch,
     * destination -- is the endpoint's to check against the request; this
     * says only that this server issued it, lately.
     *
     * @throws TokenError
     */
    public static function decode(string $ticket, Signer $signer, int $now): self
    {
        if (\strlen($ticket) > self::MAX_LENGTH) {
            throw new TokenError('longer than any ticket issued');
        }

        $map = SignedToken::decode($ticket, $signer, self::PURPOSE);

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

        $ticket = new self(
            sessionHash: $string('s'),
            authHash: $string('h'),
            origin: $string('o'),
            tenant: $string('y'),
            panel: $string('n'),
            challenge: $string('c'),
            state: $string('t'),
            returnTo: $string('r'),
            id: $string('j'),
            issuedAt: $int('i'),
            expiresAt: $int('e'),
        );

        // Shapes, though the MAC says this server wrote them -- as a code's
        // are: the id is a nonce key, and the challenge goes into a code.
        if (preg_match(self::ID, $ticket->id) !== 1
            || preg_match(self::HASH, $ticket->sessionHash) !== 1
            || preg_match(self::HASH, $ticket->authHash) !== 1
            || preg_match(HandoffCode::CHALLENGE, $ticket->challenge) !== 1
            || preg_match(HandoffCode::STATE, $ticket->state) !== 1
            || Realm::hostOf($ticket->origin) === null) {
            throw new TokenError('a field is not the shape an issued ticket has');
        }

        if ($ticket->issuedAt > $now + HandoffCode::SKEW) {
            throw new TokenError('issued in the future');
        }
        if ($ticket->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        // Only minting applies the TTL, so nothing else stops a ticket
        // claiming a far expiry from living that long.
        if ($ticket->expiresAt - $ticket->issuedAt > self::TTL) {
            throw new TokenError('lives longer than a ticket is issued for');
        }

        return $ticket;
    }

    /** Whether $sessionId is the session the screen was shown to. */
    public function belongsTo(Signer $signer, #[\SensitiveParameter] string $sessionId): bool
    {
        return $sessionId !== '' && hash_equals($this->sessionHash, TokenClaims::hashSession($signer, $sessionId));
    }

    /** Whether $user, at $epoch, is who the screen was shown to. */
    public function isFor(Signer $signer, string $user, string $epoch): bool
    {
        return $user !== '' && hash_equals($this->authHash, TokenClaims::hashAuth($signer, $user, $epoch));
    }
}
